<?php

namespace App\Console\Commands;

use App\Models\DeviceSyncLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class PruneConnectorResourcesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fingerprint:prune-resources
        {--logs-dir= : Direktori log yang dirotasi (default storage/logs)}
        {--connector-dir= : Direktori connector (default <root>/connector)}
        {--keep-lines=2000 : Jumlah baris terakhir log yang disimpan saat rotasi}
        {--buffer-keep-lines=5000 : Jumlah baris terakhir offline buffer yang disimpan}
        {--log-max-bytes=10485760 : Ambang ukuran log (byte) sebelum dirotasi}
        {--buffer-max-bytes=20971520 : Ambang ukuran offline buffer (byte) sebelum dipangkas}
        {--state-keep-days=14 : Umur maksimum berkas di connector/state (hari)}
        {--sync-log-days=90 : Retensi riwayat sinkronisasi mesin (hari; 0 = nonaktif)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Rotasi log connector/worker, pangkas offline buffer, bersihkan state lama, dan retensi riwayat sinkronisasi (terjadwal harian)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $logsDir = $this->option('logs-dir') ?: storage_path('logs');
        $connectorDir = $this->option('connector-dir') ?: base_path('connector');

        $this->rotateLogs($logsDir);
        $this->pruneBuffer($connectorDir);
        $this->pruneState($connectorDir);
        $this->pruneSyncLogs();

        return self::SUCCESS;
    }

    private function rotateLogs(string $logsDir): void
    {
        $maxBytes = (int) $this->option('log-max-bytes');
        $keep = (int) $this->option('keep-lines');

        foreach (['connector_service.log', 'queue_worker_service.log'] as $name) {
            $path = $logsDir.DIRECTORY_SEPARATOR.$name;

            if (! File::exists($path)) {
                continue;
            }

            if (File::size($path) <= $maxBytes) {
                continue;
            }

            $trimmed = $this->keepLastLines($path, $keep);

            if ($trimmed) {
                $this->info("Log dirotasi: {$name} (disisakan {$keep} baris terakhir).");
            }
        }
    }

    private function pruneBuffer(string $connectorDir): void
    {
        $path = $connectorDir.DIRECTORY_SEPARATOR.'offline_buffer.txt';

        if (! File::exists($path)) {
            return;
        }

        $maxBytes = (int) $this->option('buffer-max-bytes');

        if (File::size($path) <= $maxBytes) {
            return;
        }

        $keep = (int) $this->option('buffer-keep-lines');

        if ($this->keepLastLines($path, $keep)) {
            $this->info("Offline buffer dipangkas (disisakan {$keep} baris terakhir).");
        }
    }

    private function pruneState(string $connectorDir): void
    {
        $stateDir = $connectorDir.DIRECTORY_SEPARATOR.'state';

        if (! File::isDirectory($stateDir)) {
            return;
        }

        $keepDays = (int) $this->option('state-keep-days');
        $threshold = now()->subDays($keepDays)->getTimestamp();
        $deleted = 0;

        foreach (File::files($stateDir) as $file) {
            if ($file->getMTime() < $threshold) {
                File::delete($file->getPathname());
                $deleted++;
            }
        }

        if ($deleted > 0) {
            $this->info("Menghapus {$deleted} berkas state lama (> {$keepDays} hari).");
        }
    }

    private function pruneSyncLogs(): void
    {
        $keepDays = (int) $this->option('sync-log-days');

        if ($keepDays <= 0) {
            return;
        }

        $deleted = 0;

        DeviceSyncLog::where('created_at', '<', now()->subDays($keepDays))
            ->chunkById(1000, function ($logs) use (&$deleted): void {
                $deleted += DeviceSyncLog::whereKey($logs->modelKeys())->delete();
            });

        if ($deleted > 0) {
            $this->info("Menghapus {$deleted} riwayat sinkronisasi (> {$keepDays} hari).");
        }
    }

    /**
     * Pangkas berkas agar hanya menyisakan sejumlah baris terakhir.
     *
     * @return bool true bila berkas benar-benar dipangkas
     */
    private function keepLastLines(string $path, int $keep): bool
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES);

        if ($lines === false || count($lines) <= $keep) {
            return false;
        }

        $kept = array_slice($lines, -$keep);
        File::put($path, implode(PHP_EOL, $kept).PHP_EOL);

        return true;
    }
}
