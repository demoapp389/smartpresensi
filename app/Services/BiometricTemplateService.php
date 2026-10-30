<?php

namespace App\Services;

use App\Models\DeviceUser;
use App\Models\FingerprintDevice;
use App\Models\Student;
use App\Models\StudentBiometricTemplate;

/**
 * Operasi backup & distribusi template sidik jari antara mesin fisik dan server.
 *
 * Memisahkan alur SOAP (tarik/kirim template) dari controller agar dapat diuji
 * dengan service tiruan dan controller tetap tipis.
 */
class BiometricTemplateService
{
    public function __construct(
        protected SoapFingerprintService $soapService,
    ) {}

    /**
     * Backup template sidik jari dari mesin fisik ke database server.
     *
     * @return array{success: bool, message: string}
     */
    public function backupTemplate(FingerprintDevice $device, Student $student, string $pin, int $actorId): array
    {
        $result = $this->soapService->getUserTemplate($device, $pin, 0, $actorId);

        if (! $result['success']) {
            return ['success' => false, 'message' => $result['message']];
        }

        StudentBiometricTemplate::updateOrCreate(
            ['student_id' => $student->id, 'finger_index' => 0],
            [
                'template_data' => $result['template'],
                'template_size' => $result['size'],
                'source_device_id' => $device->id,
            ]
        );

        return [
            'success' => true,
            'message' => "Template sidik jari {$student->name} berhasil di-backup ke database server (Ukuran: {$result['size']} bytes).",
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

        // Ambil template dari database atau ambil on-the-fly dari mesin asal
        $template = StudentBiometricTemplate::where('student_id', $student->id)
            ->where('finger_index', 0)
            ->first();

        if (! $template) {
            $pullResult = $this->soapService->getUserTemplate($mapping->device, $mapping->machine_user_id, 0, $actorId);
            if (! $pullResult['success']) {
                return [
                    'success' => false,
                    'message' => "Gagal mengambil sidik jari dari mesin asal: {$pullResult['message']}",
                ];
            }

            $template = StudentBiometricTemplate::create([
                'student_id' => $student->id,
                'finger_index' => 0,
                'template_data' => $pullResult['template'],
                'template_size' => $pullResult['size'],
                'source_device_id' => $mapping->device_id,
            ]);
        }

        // 1. Kirim nama user ke mesin target
        $this->soapService->pushUserInfo($targetDevice, $mapping->machine_user_id, $student->name, $actorId);

        // 2. Kirim template sidik jari ke mesin target
        $result = $this->soapService->setUserTemplate($targetDevice, $mapping->machine_user_id, $template->finger_index, $template->template_data, $actorId);

        if (! $result['success']) {
            return ['success' => false, 'message' => $result['message']];
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
}
