<?php

namespace App\Console\Commands;

use App\Services\DatabaseBackupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class BackupDatabaseCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'db:backup
        {--path= : Direktori tujuan backup (default storage/app/backups)}
        {--keep-days=14 : Retensi berkas backup (hari; 0 = nonaktif)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Buat dump database terjadwal beserta checksum SHA-256 dan pangkas backup lama';

    public function handle(DatabaseBackupService $backupService): int
    {
        $directory = $this->option('path') ?: storage_path('app'.DIRECTORY_SEPARATOR.'backups');
        File::ensureDirectoryExists($directory, 0750);

        $filename = $backupService->getBackupFilename();
        $path = $directory.DIRECTORY_SEPARATOR.$filename;
        $handle = fopen($path, 'w');

        if ($handle === false) {
            $this->error("Tidak dapat membuka berkas backup: {$path}");

            return self::FAILURE;
        }

        try {
            $tablesCount = $backupService->writeSqlDump($handle);
        } finally {
            fclose($handle);
        }

        $checksum = hash_file('sha256', $path);
        File::put($path.'.sha256', $checksum.'  '.$filename.PHP_EOL);

        $this->info("Backup dibuat: {$filename} ({$tablesCount} tabel, sha256 {$checksum}).");

        $this->pruneOldBackups($directory);

        return self::SUCCESS;
    }

    private function pruneOldBackups(string $directory): void
    {
        $keepDays = (int) $this->option('keep-days');

        if ($keepDays <= 0) {
            return;
        }

        $threshold = now()->subDays($keepDays)->getTimestamp();
        $deleted = 0;

        foreach (File::glob($directory.DIRECTORY_SEPARATOR.'backup_*.sql') ?: [] as $path) {
            if (File::lastModified($path) >= $threshold) {
                continue;
            }

            File::delete($path);
            File::delete($path.'.sha256');
            $deleted++;
        }

        if ($deleted > 0) {
            $this->info("Menghapus {$deleted} backup lama (> {$keepDays} hari).");
        }
    }
}
