<?php

namespace App\Jobs;

use App\Models\DeviceUser;
use App\Models\FingerprintDevice;
use App\Models\StudentBiometricTemplate;
use App\Models\TaskProgress;
use App\Services\SoapFingerprintService;
use App\Support\TaskProgressReporter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Operasi massal terhadap mesin fingerprint.
 *
 * Ketiga operasi (kirim nama, backup template, kloning antar mesin) memakai
 * loop SOAP per siswa yang bisa berjalan belasan menit, sehingga dipindahkan
 * ke worker queue dan dilaporkan progresnya lewat tabel task_progress.
 */
class BulkDeviceOperation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const OP_PUSH_NAMES = 'push_names';

    public const OP_BACKUP_FINGERPRINTS = 'backup_fingerprints';

    public const OP_CLONE = 'clone';

    /**
     * Operasi ini contacting perangkat keras dan bisa berjalan sangat lama.
     */
    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(
        public string $operation,
        public int $sourceDeviceId,
        public ?int $targetDeviceId,
        public ?int $actorId,
        public string $progressUuid,
    ) {
        // Antrean terpisah supaya operasi mesin yang lama tidak memblokir notifikasi WhatsApp.
        $this->onQueue('long-running');
    }

    public function handle(SoapFingerprintService $soapService): void
    {
        $reporter = TaskProgressReporter::forTask(
            TaskProgress::where('uuid', $this->progressUuid)->firstOrFail()
        );

        $source = FingerprintDevice::find($this->sourceDeviceId);

        if (! $source) {
            $reporter->fail('Mesin sumber tidak ditemukan.');

            return;
        }

        if ($message = $source->offlineActionMessage()) {
            $reporter->fail($message);

            return;
        }

        $target = $this->targetDeviceId
            ? FingerprintDevice::find($this->targetDeviceId)
            : null;

        if ($this->operation === self::OP_CLONE) {
            if (! $target) {
                $reporter->fail('Mesin target tidak ditemukan.');

                return;
            }

            if ($message = $target->offlineActionMessage('Mesin target')) {
                $reporter->fail($message);

                return;
            }
        }

        $mappings = DeviceUser::where('device_id', $source->id)
            ->with(['student', 'student.biometricTemplates'])
            ->get();

        if ($mappings->isEmpty()) {
            $reporter->fail("Belum ada siswa yang dipetakan pada mesin {$source->name}.");

            return;
        }

        $reporter->setTotal($mappings->count(), 'Menyiapkan '.count($mappings).' siswa...');

        $success = 0;
        $failed = 0;

        try {
            foreach ($mappings as $mapping) {
                if (! $mapping->student) {
                    $reporter->advance();

                    continue;
                }

                $outcome = match ($this->operation) {
                    self::OP_PUSH_NAMES => $this->pushName($soapService, $source, $mapping, $this->actorId),
                    self::OP_BACKUP_FINGERPRINTS => $this->backupTemplate($soapService, $source, $mapping, $this->actorId),
                    self::OP_CLONE => $this->cloneStudent($soapService, $source, $target, $mapping, $this->actorId),
                    default => false,
                };

                $outcome ? $success++ : $failed++;
                $reporter->advance(1, 'Memproses PIN '.$mapping->machine_user_id.'...');
            }

            $reporter->finish(
                ['success' => $success, 'failed' => $failed],
                $this->summarize($source, $target, $success, $failed)
            );
        } catch (\Throwable $e) {
            Log::error('[BulkDeviceOperation] Operasi massal gagal', [
                'operation' => $this->operation,
                'device_id' => $this->sourceDeviceId,
                'error' => $e->getMessage(),
            ]);

            $reporter->fail('Operasi gagal: '.$e->getMessage());
        }
    }

    protected function pushName(SoapFingerprintService $soap, FingerprintDevice $device, DeviceUser $mapping, ?int $actorId): bool
    {
        $result = $soap->pushUserInfo($device, $mapping->machine_user_id, $mapping->student->name, $actorId);

        return (bool) ($result['success'] ?? false);
    }

    protected function backupTemplate(SoapFingerprintService $soap, FingerprintDevice $device, DeviceUser $mapping, ?int $actorId): bool
    {
        $result = $soap->getUserTemplate($device, $mapping->machine_user_id, 0, $actorId);

        if (! ($result['success'] ?? false)) {
            return false;
        }

        StudentBiometricTemplate::updateOrCreate(
            ['student_id' => $mapping->student_id, 'finger_index' => 0],
            [
                'template_data' => $result['template'],
                'template_size' => $result['size'],
                'source_device_id' => $device->id,
            ]
        );

        return true;
    }

    protected function cloneStudent(
        SoapFingerprintService $soap,
        FingerprintDevice $source,
        ?FingerprintDevice $target,
        DeviceUser $mapping,
        ?int $actorId
    ): bool {
        if (! $target) {
            return false;
        }

        $student = $mapping->student;

        $soap->pushUserInfo($target, $mapping->machine_user_id, $student->name, $actorId);

        $template = $student->biometricTemplates->firstWhere('finger_index', 0);

        if (! $template) {
            $pullResult = $soap->getUserTemplate($source, $mapping->machine_user_id, 0, $actorId);

            if ($pullResult['success'] ?? false) {
                $template = StudentBiometricTemplate::create([
                    'student_id' => $student->id,
                    'finger_index' => 0,
                    'template_data' => $pullResult['template'],
                    'template_size' => $pullResult['size'],
                    'source_device_id' => $source->id,
                ]);
            }
        }

        if ($template) {
            $soap->setUserTemplate(
                $target,
                $mapping->machine_user_id,
                $template->finger_index,
                $template->template_data,
                $actorId
            );
        }

        DeviceUser::firstOrCreate(
            ['device_id' => $target->id, 'student_id' => $student->id],
            ['machine_user_id' => $mapping->machine_user_id]
        );

        return true;
    }

    protected function summarize(FingerprintDevice $source, ?FingerprintDevice $target, int $success, int $failed): string
    {
        return match ($this->operation) {
            self::OP_PUSH_NAMES => "Berhasil mengirim {$success} nama siswa ke layar LCD mesin {$source->name}."
                .($failed > 0 ? " ({$failed} siswa gagal dikirim)." : ''),
            self::OP_BACKUP_FINGERPRINTS => "Backup sidik jari selesai: {$success} template siswa tersimpan di server"
                .($failed > 0 ? " ({$failed} siswa belum rekam jari di mesin)." : '.'),
            self::OP_CLONE => "Sukses mengkloning {$success} siswa & sidik jari dari mesin {$source->name} ke mesin "
                .($target?->name ?? 'mesin target').($failed > 0 ? " ({$failed} siswa gagal)." : '.'),
            default => 'Operasi selesai.',
        };
    }
}
