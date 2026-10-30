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
 * Sinkronisasi absensi ke seluruh mesin fingerprint aktif.
 *
 * Dijalankan berurutan (satu mesin pada satu waktu) karena mesin fingerprint
 * hanya menerima satu koneksi SOAP sekaligus.
 */
class SyncAllDevicesAttendance implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(
        public ?int $actorId,
        public string $progressUuid,
    ) {
        // Antrean terpisah supaya sinkronisasi tidak memblokir notifikasi WhatsApp.
        $this->onQueue('long-running');
    }

    public function handle(FingerprintConnectorService $deviceService): void
    {
        $reporter = TaskProgressReporter::forTask(
            TaskProgress::where('uuid', $this->progressUuid)->firstOrFail()
        );

        $devices = FingerprintDevice::where('active', true)->get();

        if ($devices->isEmpty()) {
            $reporter->fail('Tidak ada mesin fingerprint aktif yang terdaftar.');

            return;
        }

        $reporter->setTotal($devices->count(), 'Sinkronisasi '.count($devices).' mesin...');

        $success = 0;
        $failed = 0;
        $totalNewLogs = 0;

        try {
            foreach ($devices as $device) {
                $result = $deviceService->syncAttendances($device, $this->actorId);

                if ($result['success'] ?? false) {
                    $success++;
                    $totalNewLogs += (int) ($result['new_inserted'] ?? 0);
                } else {
                    $failed++;
                }

                $reporter->advance(1, "Mesin {$device->name} selesai diproses.");
            }

            $reporter->finish(
                ['success' => $success, 'failed' => $failed, 'new_logs' => $totalNewLogs],
                "Sinkronisasi massal selesai. {$success} mesin berhasil, {$failed} mesin gagal. "
                ."Total {$totalNewLogs} scan baru dimasukkan."
            );
        } catch (\Throwable $e) {
            Log::error('[SyncAllDevicesAttendance] Sinkronisasi massal gagal', [
                'error' => $e->getMessage(),
            ]);

            $reporter->fail('Sinkronisasi gagal: '.$e->getMessage());
        }
    }
}
