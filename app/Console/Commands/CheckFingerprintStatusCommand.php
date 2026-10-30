<?php

namespace App\Console\Commands;

use App\Models\FingerprintDevice;
use App\Services\FingerprintConnectorService;
use Illuminate\Console\Command;

class CheckFingerprintStatusCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fingerprint:health-check {--device= : ID Perangkat spesifik}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Verifikasi konektivitas fisik riil mesin fingerprint dan perbarui status online/offline (heartbeat terjadwal)';

    /**
     * Execute the console command.
     */
    public function handle(FingerprintConnectorService $connectorService): int
    {
        $deviceId = $this->option('device');

        $query = FingerprintDevice::where('active', true)
            ->where('status', '!=', 'maintenance');

        if ($deviceId) {
            $query->where('id', $deviceId);
        }

        $devices = $query->get();

        if ($devices->isEmpty()) {
            $this->info('Tidak ada mesin fingerprint aktif yang perlu dicek.');

            return 0;
        }

        $this->info("Memeriksa konektivitas {$devices->count()} mesin...");

        foreach ($devices as $device) {
            $reachable = $connectorService->checkConnectivity($device);

            if ($reachable) {
                $device->markOnline();
                $this->info("   [ONLINE]  {$device->name} ({$device->ip}:{$device->port})");
            } else {
                $device->markOffline();
                $this->warn("   [OFFLINE] {$device->name} ({$device->ip}:{$device->port})");
            }
        }

        $this->info('Pengecekan status selesai.');

        return 0;
    }
}
