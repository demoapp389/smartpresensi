<?php

namespace App\Jobs;

use App\Models\FingerprintDevice;
use App\Models\TaskProgress;
use App\Services\FingerprintConnectorService;
use App\Support\TaskProgressReporter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Sinkronisasi daftar pengguna (PIN + nama) dari satu mesin fingerprint.
 *
 * Dijalankan di worker queue agar tombol sinkronisasi manual di halaman mesin
 * dapat menampilkan progress bar lewat tabel task_progress.
 */
class SyncDeviceUsers implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(
        public int $deviceId,
        public ?int $actorId,
        public bool $createStudents,
        public string $progressUuid,
    ) {
        $this->onQueue('long-running');
    }

    public function handle(FingerprintConnectorService $connectorService): void
    {
        $reporter = TaskProgressReporter::forTask(
            TaskProgress::where('uuid', $this->progressUuid)->firstOrFail()
        );

        $device = FingerprintDevice::find($this->deviceId);

        if (! $device) {
            $reporter->fail('Mesin tidak ditemukan.');

            return;
        }

        if ($message = $device->offlineActionMessage()) {
            $reporter->fail($message);

            return;
        }

        $reporter->setTotal(0, 'Membaca ID & nama pengguna dari mesin...');

        try {
            $result = $connectorService->syncMachineUsers($device, $this->actorId, $this->createStudents, $reporter);

            if (! ($result['success'] ?? false)) {
                $reporter->fail($result['message'] ?? 'Sinkronisasi pengguna gagal.');

                return;
            }

            $message = $result['message'] ?? 'Sinkronisasi pengguna selesai.';
            $reporter->finish([
                'new' => $result['new'] ?? 0,
                'updated' => $result['updated'] ?? 0,
                'students_created' => $result['students_created'] ?? 0,
                'unmapped' => $result['unmapped'] ?? 0,
            ], $message);
        } catch (\Throwable $e) {
            Log::error('[SyncDeviceUsers] Sinkronisasi gagal', [
                'device_id' => $this->deviceId,
                'error' => $e->getMessage(),
            ]);

            $reporter->fail('Sinkronisasi gagal: '.$e->getMessage());
        }
    }
}
