<?php

namespace App\Console\Commands;

use App\Models\FingerprintDevice;
use App\Models\SchoolHoliday;
use App\Models\SchoolSchedule;
use App\Services\DeviceLockService;
use App\Services\FingerprintConnectorService;
use App\Services\SoapFingerprintService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class FingerprintReconcileCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fingerprint:reconcile {--device= : ID Perangkat spesifik} {--force : Abaikan interval rekonsiliasi adaptif}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Rekonsiliasi absensi adaptif: rapat (5 menit) sepanjang jam sekolah, jarang (30 menit) di luar jam sekolah/hari libur';

    /**
     * Interval rekonsiliasi rapat di dalam jendela absensi (menit).
     */
    protected const DENSE_INTERVAL_MINUTES = 5;

    /**
     * Interval rekonsiliasi di luar jendela/jam efektif (menit).
     */
    protected const SPARSE_INTERVAL_MINUTES = 30;

    /**
     * Toleransi di sekitar jendela absensi (menit).
     */
    protected const WINDOW_BUFFER_MINUTES = 5;

    /**
     * Execute the console command.
     */
    public function handle(
        FingerprintConnectorService $connectorService,
        SoapFingerprintService $soapService,
        DeviceLockService $lockService
    ): int {
        $now = now();
        $deviceId = $this->option('device');

        if (! $this->option('force') && ! $deviceId) {
            $interval = $this->reconciliationIntervalMinutes($now);
            $lastRun = Cache::get('fingerprint:reconcile:last_run');
            $lastRunAt = match (true) {
                $lastRun instanceof \DateTimeInterface => Carbon::instance($lastRun),
                is_numeric($lastRun) => Carbon::createFromTimestamp((int) $lastRun),
                default => null,
            };

            if ($lastRunAt && $now->diffInMinutes($lastRunAt, true) < $interval) {
                $this->info("Rekonsiliasi dilewati (interval adaptif {$interval} menit; terakhir {$lastRunAt->diffForHumans()}).");

                return 0;
            }
        }

        $query = FingerprintDevice::where('active', true)->where('status', '!=', 'maintenance');
        if ($deviceId) {
            $query->where('id', $deviceId);
        }

        $devices = $query->get();

        if ($devices->isEmpty()) {
            $this->info('Tidak ada mesin fingerprint aktif yang perlu direkonsiliasi.');

            return 0;
        }

        $this->info("Rekonsiliasi adaptif untuk {$devices->count()} mesin...");

        foreach ($devices as $device) {
            $driver = $device->ingestDriver();

            $outcome = $lockService->run($device, function () use ($device, $driver, $connectorService, $soapService) {
                return $driver === 'soap'
                    ? $soapService->pullAttendanceLogs($device)
                    : $connectorService->syncAttendances($device);
            });

            if ($outcome['locked']) {
                $this->warn("   [SKIP] {$device->name} sedang diproses oleh proses lain.");

                continue;
            }

            /** @var array{success: bool, message: string} $result */
            $result = $outcome['result'];

            if ($result['success']) {
                $this->info("   [OK] {$device->name} ({$driver}): {$result['message']}");
            } elseif ($result['busy'] ?? false) {
                $this->line("   [SKIP] {$device->name} ({$driver}): {$result['message']}");
            } else {
                $this->warn("   [FAIL] {$device->name} ({$driver}): {$result['message']}");
            }
        }

        Cache::put('fingerprint:reconcile:last_run', $now->getTimestamp(), now()->addDay());

        $this->info('Rekonsiliasi adaptif selesai.');

        return 0;
    }

    /**
     * Tentukan interval rekonsiliasi (menit) berdasarkan jam efektif hari ini.
     *
     * Hari libur aktif → jarang. Di dalam (atau tepat di sekitar) jam sekolah
     * (scan masuk sampai akhir jam pulang) → rapat, termasuk siswa yang datang
     * terlambat setelah batas `late_after`. Di luar jam sekolah → jarang.
     * Bila jadwal tidak tersedia, default rapat agar absensi tidak tertunda.
     */
    protected function reconciliationIntervalMinutes(Carbon $now): int
    {
        if (SchoolHoliday::isHoliday($now)) {
            return self::SPARSE_INTERVAL_MINUTES;
        }

        $schedule = SchoolSchedule::query()
            ->where('day_of_week', $now->dayOfWeekIso)
            ->where('is_active', true)
            ->first();

        if ($schedule === null) {
            // Jika ada hari lain yang aktif tapi hari ini tidak memiliki jadwal aktif,
            // berarti hari ini adalah hari libur mingguan (gunakan interval jarang).
            $hasActiveSchedules = SchoolSchedule::query()->where('is_active', true)->exists();

            return $hasActiveSchedules ? self::SPARSE_INTERVAL_MINUTES : self::DENSE_INTERVAL_MINUTES;
        }

        $withinSchoolHours = $this->isNearWindow($now, $schedule->check_in_start, $schedule->end_at);

        return $withinSchoolHours ? self::DENSE_INTERVAL_MINUTES : self::SPARSE_INTERVAL_MINUTES;
    }

    /**
     * Cek apakah waktu sekarang berada dalam jendela (termasuk toleransi) waktu mulai/selesai.
     */
    protected function isNearWindow(Carbon $now, ?string $start, ?string $end, int $bufferMinutes = self::WINDOW_BUFFER_MINUTES): bool
    {
        if (! $start || ! $end) {
            return false;
        }

        $from = $now->copy()->setTimeFromTimeString($start)->subMinutes($bufferMinutes);
        $until = $now->copy()->setTimeFromTimeString($end)->addMinutes($bufferMinutes);

        return $now->between($from, $until);
    }
}
