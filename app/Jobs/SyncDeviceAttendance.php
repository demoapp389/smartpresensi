<?php

namespace App\Jobs;

use App\Models\FingerprintDevice;
use App\Models\TaskProgress;
use App\Services\DeviceLockService;
use App\Services\FingerprintConnectorService;
use App\Services\SoapFingerprintService;
use App\Support\TaskProgressReporter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Sinkronisasi absensi dari satu mesin fingerprint sesuai `ingest_driver`
 * perangkat (connector C# atau Web SOAP), tanpa fallback lintas driver.
 *
 * Dijalankan di worker queue agar tombol sinkronisasi manual di halaman mesin
 * dapat menampilkan progress bar lewat tabel task_progress.
 */
class SyncDeviceAttendance implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(
        public int $deviceId,
        public ?int $actorId,
        public string $progressUuid,
    ) {
        $this->onQueue('long-running');
    }

    public function handle(
        FingerprintConnectorService $connectorService,
        SoapFingerprintService $soapService,
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

        $driver = $device->ingestDriver();

        // Pre-flight soket hanya untuk connector; mesin SOAP memakai port web sendiri.
        if ($driver === 'connector' && ! $connectorService->ensureAttendanceReachable($device, $this->actorId)) {
            $reporter->fail('Mesin '.$device->name.' tidak terhubung.');

            return;
        }

        $reporter->setTotal(0, 'Menarik riwayat absensi dari mesin...');

        try {
            $outcome = $lockService->run($device, function () use ($connectorService, $soapService, $device, $reporter, $driver) {
                if ($driver === 'soap') {
                    return [
                        'driver' => 'soap',
                        'result' => $soapService->pullAttendanceLogs($device, $this->actorId, $reporter),
                    ];
                }

                return [
                    'driver' => 'connector',
                    'result' => $connectorService->syncAttendances($device, $this->actorId, $reporter),
                ];
            });

            if ($outcome['locked']) {
                $reporter->fail('Mesin sedang diproses oleh proses lain. Coba lagi sebentar.');

                return;
            }

            $data = $outcome['result'];
            $result = $data['result'];

            if (! ($result['success'] ?? false)) {
                $reporter->fail(($result['busy'] ?? false)
                    ? 'Mesin sedang sibuk diproses connector. Coba lagi sebentar.'
                    : ($result['message'] ?? 'Gagal menarik absensi.'));

                return;
            }

            $this->finish(
                $reporter,
                $result,
                $data['driver'] === 'soap' ? '(via Web SOAP)' : '(via C# Connector)'
            );
        } catch (\Throwable $e) {
            Log::error('[SyncDeviceAttendance] Sinkronisasi gagal', [
                'device_id' => $this->deviceId,
                'error' => $e->getMessage(),
            ]);

            $reporter->fail('Sinkronisasi gagal: '.$e->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function finish(TaskProgressReporter $reporter, array $result, string $suffix): void
    {
        $message = ($result['message'] ?? 'Sinkronisasi absensi selesai.').' '.$suffix;
        $reporter->finish([
            'new_inserted' => $result['new_inserted'] ?? 0,
            'updated' => $result['updated'] ?? 0,
        ], $message);
    }
}
