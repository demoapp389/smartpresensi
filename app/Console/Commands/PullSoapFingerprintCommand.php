<?php

namespace App\Console\Commands;

use App\Models\FingerprintDevice;
use App\Services\DeviceLockService;
use App\Services\SoapFingerprintService;
use Illuminate\Console\Command;

class PullSoapFingerprintCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fingerprint:pull-soap {--device= : ID Perangkat tertentu} {--sync-time : Samakan jam mesin dengan waktu server} {--time-only : Hanya samakan jam, jangan tarik log absensi} {--all : Proses semua mesin tanpa memedulikan jalur ingest}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Tarik log absensi dan sinkronkan jam via Web SOAP SDK (100% Native PHP Port 80, tanpa Windows Service)';

    /**
     * Execute the console command.
     */
    public function handle(SoapFingerprintService $soapService, DeviceLockService $lockService): int
    {
        $deviceId = $this->option('device');
        $shouldSyncTime = (bool) $this->option('sync-time');
        $shouldPullLogs = ! $this->option('time-only');

        $query = FingerprintDevice::where('active', true)->where('status', '!=', 'maintenance');
        if ($deviceId) {
            $query->where('id', $deviceId);
        } elseif (! $this->option('all')) {
            // Web SOAP hanya untuk mesin yang ditetapkan sebagai fallback (ingest_driver=soap),
            // termasuk saat hanya menyinkronkan jam.
            $query->where('ingest_driver', 'soap');
        }

        $devices = $query->get();

        if ($devices->isEmpty()) {
            $this->info('Tidak ada mesin fingerprint aktif yang ditemukan.');

            return 0;
        }

        $action = $shouldPullLogs ? 'penarikan absensi Web SOAP' : 'sinkronisasi jam Web SOAP';
        $this->info("Memulai {$action} untuk {$devices->count()} mesin...");

        foreach ($devices as $device) {
            $this->line("-> Memproses {$device->name} ({$device->ip}:{$device->port})...");

            $outcome = $lockService->run($device, function () use ($soapService, $shouldSyncTime, $shouldPullLogs, $device) {
                if ($shouldSyncTime) {
                    $timeResult = $soapService->syncTime($device);
                    if ($timeResult['success']) {
                        $this->line("   [TIME] {$timeResult['message']}");
                    } else {
                        $this->warn("   [TIME FAIL] {$timeResult['message']}");
                    }
                }

                if (! $shouldPullLogs) {
                    return ['success' => true, 'message' => 'Sinkronisasi jam mesin selesai.'];
                }

                return $soapService->pullAttendanceLogs($device);
            });

            if ($outcome['locked']) {
                $this->warn('   [SKIP] Mesin sedang diproses oleh proses lain (aksi manual/daemon).');

                continue;
            }

            $result = $outcome['result'];

            if ($result['success']) {
                $this->info("   [OK] {$result['message']}");
            } else {
                $this->warn("   [FAIL] {$result['message']}");
            }
        }

        $this->info('Penarikan absensi Web SOAP selesai.');

        return 0;
    }
}
