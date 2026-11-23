<?php

namespace App\Services;

use App\Models\DeviceUser;
use App\Models\FingerprintDevice;
use App\Models\Student;
use App\Models\StudentBiometricTemplate;

/**
 * Operasi backup & distribusi template sidik jari antara mesin fisik dan server.
 *
 * Penarikan template mengikuti `ingest_driver` mesin: SOAP (port 80) atau
 * connector (port 4370), dengan fallback ke jalur lain bila jalur utama gagal.
 * Pengiriman template ke mesin target masih memerlukan SOAP karena protokol
 * tulis template (`CMD_USERTEMP_WRQ`) belum didukung connector.
 */
class BiometricTemplateService
{
    public function __construct(
        protected SoapFingerprintService $soapService,
        protected FingerprintConnectorService $connectorService,
    ) {}

    /**
     * Alasan backup massal tidak mungkin dijalankan, atau null bila bisa dicoba.
     */
    public function backupBlockedReason(FingerprintDevice $device): ?string
    {
        if ($device->ingestDriver() === 'soap') {
            if ($this->soapService->isSoapReachable($device)) {
                return null;
            }

            return $this->connectorService->supportsTemplateVerification()
                ? null
                : "Mesin {$device->name} tidak membuka layanan SOAP (port 80) dan connector yang berjalan belum mendukung ekspor template sidik jari. Perbarui connector lalu jalankan ulang layanannya.";
        }

        if ($this->connectorService->supportsTemplateVerification()) {
            return null;
        }

        return $this->soapService->isSoapReachable($device)
            ? null
            : "Mesin {$device->name} tidak dapat dijangkau melalui connector dan layanan SOAP (port 80) juga tidak terbuka. Periksa IP/port mesin serta status layanan connector.";
    }

    /**
     * Tarik seluruh template sidik jari satu PIN dari mesin.
     *
     * @return array{success: bool, templates: array<int, array{finger_index: int, template: string, size: int}>, message: string}
     */
    public function pullTemplate(FingerprintDevice $device, string $pin, ?int $actorId = null): array
    {
        if ($device->ingestDriver() === 'soap') {
            $primary = $this->pullViaSoap($device, $pin, $actorId);

            if ($primary['success']) {
                return $primary;
            }

            $fallback = $this->pullViaConnector($device, $pin);

            return $fallback['success'] ? $fallback : $primary;
        }

        $primary = $this->pullViaConnector($device, $pin);

        if ($primary['success']) {
            return $primary;
        }

        // Jalur utama gagal: coba SOAP hanya bila port 80 memang menjawab,
        // supaya mesin connector-only tidak menunggu timeout SOAP sia-sia.
        if ($this->soapService->isSoapReachable($device)) {
            $fallback = $this->pullViaSoap($device, $pin, $actorId);

            if ($fallback['success']) {
                return $fallback;
            }
        }

        return $primary;
    }

    /**
     * Backup template sidik jari dari mesin fisik ke database server.
     *
     * @return array{success: bool, message: string}
     */
    public function backupTemplate(FingerprintDevice $device, Student $student, string $pin, int $actorId): array
    {
        $result = $this->pullTemplate($device, $pin, $actorId);

        if (! $result['success']) {
            return ['success' => false, 'message' => $result['message']];
        }

        $size = $this->storeTemplates($student, $device, $result['templates']);
        $fingers = count($result['templates']);

        return [
            'success' => true,
            'message' => "Template sidik jari {$student->name} berhasil di-backup ke database server "
                ."({$fingers} jari, total {$size} bytes).",
        ];
    }

    /**
     * Distribusikan template sidik jari siswa ke terminal mesin lain.
     *
     * @return array{success: bool, message: string}
     */
    public function distributeTemplate(DeviceUser $mapping, FingerprintDevice $targetDevice, int $actorId): array
    {
        $student = $mapping->student;

        if (! $student) {
            return ['success' => false, 'message' => 'Data siswa pada pemetaan tidak valid.'];
        }

        // Ambil template dari database atau tarik on-the-fly dari mesin asal
        $template = StudentBiometricTemplate::where('student_id', $student->id)
            ->where('finger_index', 0)
            ->first();

        if (! $template) {
            $pullResult = $this->pullTemplate($mapping->device, $mapping->machine_user_id, $actorId);

            if (! $pullResult['success']) {
                return [
                    'success' => false,
                    'message' => "Gagal mengambil sidik jari dari mesin asal: {$pullResult['message']}",
                ];
            }

            $this->storeTemplates($student, $mapping->device, $pullResult['templates']);

            $template = StudentBiometricTemplate::where('student_id', $student->id)
                ->where('finger_index', 0)
                ->first();
        }

        if (! $template) {
            return ['success' => false, 'message' => 'Template sidik jari siswa tidak ditemukan setelah penarikan data.'];
        }

        // 1. Kirim nama user ke mesin target
        $this->soapService->pushUserInfo($targetDevice, $mapping->machine_user_id, $student->name, $actorId);

        // 2. Kirim template sidik jari ke mesin target (masih via SOAP)
        $result = $this->soapService->setUserTemplate($targetDevice, $mapping->machine_user_id, $template->finger_index, $template->template_data, $actorId);

        if (! $result['success']) {
            $hint = $targetDevice->ingestDriver() === 'connector'
                ? ' Pengiriman template ke mesin target masih memerlukan layanan SOAP (port 80).'
                : '';

            return ['success' => false, 'message' => $result['message'].$hint];
        }

        // 3. Buat pemetaan pada mesin target secara otomatis jika belum ada
        DeviceUser::firstOrCreate(
            ['device_id' => $targetDevice->id, 'student_id' => $student->id],
            ['machine_user_id' => $mapping->machine_user_id]
        );

        return [
            'success' => true,
            'message' => "Sidik jari {$student->name} (PIN {$mapping->machine_user_id}) berhasil disalin dan didistribusikan ke mesin {$targetDevice->name}!",
        ];
    }

    /**
     * Simpan daftar template hasil penarikan ke database.
     *
     * @param  array<int, array{finger_index: int, template: string, size: int}>  $templates
     * @return int Total ukuran template yang disimpan (bytes).
     */
    protected function storeTemplates(Student $student, FingerprintDevice $device, array $templates): int
    {
        $size = 0;

        foreach ($templates as $template) {
            StudentBiometricTemplate::updateOrCreate(
                ['student_id' => $student->id, 'finger_index' => $template['finger_index']],
                [
                    'template_data' => $template['template'],
                    'template_size' => $template['size'],
                    'source_device_id' => $device->id,
                ]
            );

            $size += $template['size'];
        }

        return $size;
    }

    /**
     * @return array{success: bool, templates: array<int, array{finger_index: int, template: string, size: int}>, message: string}
     */
    protected function pullViaSoap(FingerprintDevice $device, string $pin, ?int $actorId): array
    {
        $result = $this->soapService->getUserTemplate($device, $pin, 0, $actorId);

        if (! ($result['success'] ?? false)) {
            return [
                'success' => false,
                'templates' => [],
                'message' => $result['message'] ?? 'Gagal mengambil template sidik jari via SOAP.',
            ];
        }

        return [
            'success' => true,
            'templates' => [[
                'finger_index' => 0,
                'template' => (string) $result['template'],
                'size' => (int) $result['size'],
            ]],
            'message' => (string) ($result['message'] ?? ''),
        ];
    }

    /**
     * @return array{success: bool, templates: array<int, array{finger_index: int, template: string, size: int}>, message: string}
     */
    protected function pullViaConnector(FingerprintDevice $device, string $pin): array
    {
        $result = $this->connectorService->exportUserTemplate($device, $pin);

        if (! ($result['success'] ?? false)) {
            return [
                'success' => false,
                'templates' => [],
                'message' => $result['message'] ?? 'Gagal mengambil template sidik jari via connector.',
            ];
        }

        $templates = array_map(
            static fn (array $item): array => [
                'finger_index' => (int) $item['finger_id'],
                'template' => (string) $item['template'],
                'size' => (int) $item['size'],
            ],
            $result['templates']
        );

        return [
            'success' => true,
            'templates' => $templates,
            'message' => (string) ($result['message'] ?? ''),
        ];
    }
}
