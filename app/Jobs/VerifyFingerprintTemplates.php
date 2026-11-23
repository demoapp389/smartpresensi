<?php

namespace App\Jobs;

use App\Models\FingerprintDevice;
use App\Models\TaskProgress;
use App\Services\DeviceLockService;
use App\Services\FingerprintTemplateVerificationService;
use App\Support\TaskProgressReporter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Verifikasi massal status sidik jari seluruh pengguna satu mesin.
 *
 * Dijalankan di worker queue agar tombol di halaman mesin dapat menampilkan
 * progress bar. Operasi ini hanya membaca mesin, tetapi tetap memegang lease
 * per mesin agar tidak bertabrakan dengan sinkronisasi/ingest.
 */
class VerifyFingerprintTemplates implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(
        public int $deviceId,
        public ?int $actorId,
        public string $progressUuid,
    ) {
        $this->onQueue('long-running');
    }

    public function handle(
        FingerprintTemplateVerificationService $verificationService,
        DeviceLockService $lockService,
    ): void {
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

        try {
            $outcome = $lockService->run(
                $device,
                fn () => $verificationService->verifyDevice($device, $reporter),
                'op',
                $this->timeout
            );

            if ($outcome['locked']) {
                $reporter->fail('Mesin sedang diproses oleh proses lain. Coba lagi sebentar.');

                return;
            }

            $result = $outcome['result'];

            if (! ($result['success'] ?? false)) {
                $reporter->fail($result['message'] ?? 'Verifikasi sidik jari gagal.');

                return;
            }

            $reporter->finish([
                'has_template' => $result['has_template'],
                'missing' => $result['missing'],
                'failed' => $result['failed'],
            ], $result['message']);
        } catch (\Throwable $e) {
            Log::error('[VerifyFingerprintTemplates] Verifikasi gagal', [
                'device_id' => $this->deviceId,
                'error' => $e->getMessage(),
            ]);

            $reporter->fail('Verifikasi sidik jari gagal: '.$e->getMessage());
        }
    }
}
