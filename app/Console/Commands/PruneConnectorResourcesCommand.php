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
        {--buffer-archive-keep-days=14 : Retensi arsip baris buffer yang dibuang (hari; 0 = nonaktif)}
        {--log-max-bytes=10485760 : Ambang ukuran log (byte) sebelum dirotasi}
        {--buffer-max-bytes=20971520 : Ambang ukuran offline buffer (byte) sebelum dipangkas}
        {--state-keep-days=14 : Umur maksimum berkas di connector/state (hari)}
        {--sync-log-days=90 : Retensi riwayat sinkronisasi mesin (hari; 0 = nonaktif)}
        {--laravel-log-keep-days=14 : Retensi berkas laravel-*.log harian (hari; 0 = nonaktif)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Rotasi log connector/worker/Laravel, pangkas offline buffer, bersihkan state lama, dan retensi riwayat sinkronisasi (terjadwal harian)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $logsDir = $this->option('logs-dir') ?: storage_path('logs');
        $connectorDir = $this->option('connector-dir') ?: base_path('connector');

        $this->rotateLogs($logsDir);
        $this->pruneLaravelLogs($logsDir);
        $this->pruneBuffer($connectorDir);
        $this->pruneState($connectorDir);
        $this->pruneSyncLogs();

        return self::SUCCESS;
    }

    private function rotateLogs(string $logsDir): void
    {
        $maxBytes = (int) $this->option('log-max-bytes');
        $keep = (int) $this->option('keep-lines');

        // Log Laravel (single maupun harian) ikut dirotasi agar tidak tumbuh
        // tanpa batas; berkas harian yang sudah tua dihapus oleh pruneLaravelLogs().
        $laravelLogs = File::glob($logsDir.DIRECTORY_SEPARATOR.'laravel*.log') ?: [];

        foreach (array_merge(
            ['connector_service.log', 'queue_worker_service.log'],
            array_map(fn (string $path): string => basename($path), $laravelLogs)
        ) as $name) {
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

    private function pruneLaravelLogs(string $logsDir): void
    {
        $keepDays = (int) $this->option('laravel-log-keep-days');

        if ($keepDays <= 0) {
            return;
        }

        $threshold = now()->subDays($keepDays)->getTimestamp();
        $deleted = 0;

        foreach (File::glob($logsDir.DIRECTORY_SEPARATOR.'laravel-*.log') ?: [] as $path) {
            if (File::lastModified($path) < $threshold) {
                File::delete($path);
                $deleted++;
            }
        }

        if ($deleted > 0) {
            $this->info("Menghapus {$deleted} berkas log Laravel lama (> {$keepDays} hari).");
        }
    }

    private function pruneBuffer(string $connectorDir): void
    {
        // Buffer bisa berada di root (mesin legacy) atau di state/<key>/ untuk
        // konfigurasi multi-mesin. Keduanya harus dipangkas agar tidak tumbuh
        // tanpa batas saat connector lama offline.
        $paths = array_merge(
            [$connectorDir.DIRECTORY_SEPARATOR.'offline_buffer.txt'],
            File::glob($connectorDir.DIRECTORY_SEPARATOR.'state'.DIRECTORY_SEPARATOR.'*'.DIRECTORY_SEPARATOR.'offline_buffer.txt') ?: []
        );

        $maxBytes = (int) $this->option('buffer-max-bytes');
        $keep = (int) $this->option('buffer-keep-lines');
        $archiveSuffix = '.discarded-'.now()->format('YmdHis');

        foreach ($paths as $path) {
            if (! File::exists($path) || File::size($path) <= $maxBytes) {
                continue;
            }

            // Baris yang dibuang diarsipkan dulu agar masih bisa diperiksa
            // manual; baru setelah retensi habis berkas arsip dihapus.
            if ($this->keepLastLines($path, $keep, $path.$archiveSuffix)) {
                // Pemangkasan membuang scan tertua yang belum sempat terkirim.
                // Tampilkan peringatan agar operator tahu ada data yang hilang.
                $this->warn('Offline buffer dipangkas (disisakan '.$keep.' baris terakhir): '.basename(dirname($path)).'/'.basename($path));
                $this->warn('Baris yang dibuang diarsipkan ke: '.basename($path.$archiveSuffix));
            }
        }

        $this->pruneBufferArchives($connectorDir);
    }

    private function pruneBufferArchives(string $connectorDir): void
    {
        $keepDays = (int) $this->option('buffer-archive-keep-days');

        if ($keepDays <= 0) {
            return;
        }

        $threshold = now()->subDays($keepDays)->getTimestamp();
        $deleted = 0;

        $archives = array_merge(
            File::glob($connectorDir.DIRECTORY_SEPARATOR.'offline_buffer.txt.discarded-*') ?: [],
            File::glob($connectorDir.DIRECTORY_SEPARATOR.'state'.DIRECTORY_SEPARATOR.'*'.DIRECTORY_SEPARATOR.'offline_buffer.txt.discarded-*') ?: []
        );

        foreach ($archives as $path) {
            if (File::lastModified($path) < $threshold) {
                File::delete($path);
                $deleted++;
            }
        }

        if ($deleted > 0) {
            $this->info("Menghapus {$deleted} arsip buffer lama (> {$keepDays} hari).");
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

        // allFiles() menelusuri state/<key>/ milik tiap mesin, bukan hanya
        // berkas di root state. Offline buffer dikecualikan karena berisi scan
        // yang belum terkirim; berkas itu hanya dipangkas berdasarkan ukuran.
        foreach (File::allFiles($stateDir) as $file) {
            if ($file->getFilename() === 'offline_buffer.txt') {
                continue;
            }

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
     * @param  string|null  $archivePath  Bila diisi, baris yang dibuang ditulis ke berkas ini.
     * @return bool true bila berkas benar-benar dipangkas
     */
    private function keepLastLines(string $path, int $keep, ?string $archivePath = null): bool
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES);

        if ($lines === false || count($lines) <= $keep) {
            return false;
        }

        $discarded = array_slice($lines, 0, -$keep);
        $kept = array_slice($lines, -$keep);

        if ($archivePath !== null && $discarded !== []) {
            File::append($archivePath, implode(PHP_EOL, $discarded).PHP_EOL);
        }

        File::put($path, implode(PHP_EOL, $kept).PHP_EOL);

        return true;
    }
}
