<?php

namespace App\Console\Commands;

use App\Models\FingerprintDevice;
use App\Services\DeviceLockService;
use App\Services\FingerprintConnectorService;
use App\Services\SoapFingerprintService;
use Illuminate\Console\Command;

class FingerprintProbeCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fingerprint:probe {--device= : ID Perangkat spesifik}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Deteksi kapabilitas tiap mesin dan tetapkan jalur ingest utama (connector/SOAP) berdasarkan hasil probe';

    /**
     * Execute the console command.
     */
    public function handle(
        FingerprintConnectorService $connectorService,
        SoapFingerprintService $soapService,
        DeviceLockService $lockService
    ): int {
        $deviceId = $this->option('device');

        $query = FingerprintDevice::where('active', true)->where('status', '!=', 'maintenance');
        if ($deviceId) {
            $query->where('id', $deviceId);
        }

        $devices = $query->get();

        if ($devices->isEmpty()) {
            $this->info('Tidak ada mesin fingerprint aktif yang perlu diprobe.');

            return 0;
        }

        $this->info("Memprobe kapabilitas {$devices->count()} mesin...");

        foreach ($devices as $device) {
            $outcome = $lockService->run($device, function () use ($device, $connectorService, $soapService) {
                $probe = $connectorService->probe($device);

                if ($probe['success']) {
                    return [
                        'driver' => 'connector',
                        'capabilities' => $probe['capabilities'],
                        'message' => $probe['message'],
                    ];
                }

                $soap = $soapService->testConnection($device);

                if ($soap['success']) {
                    return [
                        'driver' => 'soap',
                        'capabilities' => [],
                        'message' => $soap['message'],
                    ];
                }

                return [
                    'driver' => null,
                    'capabilities' => [],
                    'message' => $probe['message'],
                ];
            });

            if ($outcome['locked']) {
                $this->warn("   [SKIP] {$device->name} sedang diproses oleh proses lain.");

                continue;
            }

            /** @var array{driver: ?string, capabilities: array<string, mixed>, message: string} $result */
            $result = $outcome['result'];

            if ($result['driver'] === null) {
                $device->update(['last_probe_at' => now()]);
                $this->warn("   [FAIL] {$device->name}: {$result['message']}");

                continue;
            }

            $device->update([
                'ingest_driver' => $result['driver'],
                'capabilities' => $result['capabilities'] ?: $device->capabilities,
                'last_probe_at' => now(),
            ]);

            $this->info("   [OK] {$device->name} → {$result['driver']} ({$result['message']})");
        }

        $this->info('Probe kapabilitas selesai.');

        return 0;
    }
}
