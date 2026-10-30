<?php

namespace App\Console\Commands;

use App\Models\FingerprintDevice;
use App\Services\DeviceLockService;
use App\Services\FingerprintConnectorService;
use Illuminate\Console\Command;

class SyncFingerprintCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fingerprint:sync {--device= : ID Perangkat spesifik} {--all : Proses semua mesin tanpa memedulikan jalur ingest}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sinkronisasi riwayat absensi dari mesin Solution X601 secara berkala (PRD modul_finger.md #7)';

    /**
     * Execute the console command.
     */
    public function handle(FingerprintConnectorService $connectorService, DeviceLockService $lockService): int
    {
        $deviceId = $this->option('device');

        $query = FingerprintDevice::where('active', true);

        if ($deviceId) {
            $query->where('id', $deviceId);
        } elseif (! $this->option('all')) {
            // Jalur ingest utama: mesin connector (default) — mesin SOAP ditangani fingerprint:pull-soap.
            $query->where(function ($q) {
                $q->whereNull('ingest_driver')->orWhere('ingest_driver', 'connector');
            });
        }

        $devices = $query->get();

        if ($devices->isEmpty()) {
            $this->info('Tidak ada mesin fingerprint aktif yang ditemukan.');

            return 0;
        }

        $this->info("Memulai sinkronisasi otomatis untuk {$devices->count()} mesin...");

        foreach ($devices as $device) {
            $this->line("-> Memproses {$device->name} ({$device->ip}:{$device->port})...");

            $outcome = $lockService->run($device, fn () => $connectorService->syncAttendances($device));

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

        $this->info('Sinkronisasi selesai.');

        return 0;
    }
}
