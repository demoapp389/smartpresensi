<?php

namespace App\Services;

use App\Concerns\LogsAuditTrail;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DatabaseBackupService
{
    use LogsAuditTrail;

    /**
     * Tabel yang tidak diikutkan ke dump: data sesi/token tidak diperlukan untuk
     * restore dan berisiko memuat rahasia.
     *
     * @var array<int, string>
     */
    protected array $excludedTables = ['sessions', 'password_reset_tokens', 'personal_access_tokens'];

    /**
     * Kolom rahasia yang nilainya diganti NULL pada dump, per nama tabel.
     *
     * @var array<string, array<int, string>>
     */
    protected array $maskedColumns = [
        'users' => ['password', 'remember_token'],
        'fingerprint_devices' => ['secret_token'],
    ];

    /**
     * Dapatkan nama file backup SQL dengan timestamp lokal.
     */
    public function getBackupFilename(): string
    {
        $timestamp = now('Asia/Jakarta')->format('Y-m-d_His');
        $dbName = config('database.connections.'.config('database.default').'.database') ?? 'absensi_siswa';
        $safeDbName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', basename($dbName));

        return "backup_{$safeDbName}_{$timestamp}.sql";
    }

    /**
     * Stream unduhan file dump SQL langsung ke browser.
     */
    public function streamDownload(string $filename, ?string $clientIp = null): StreamedResponse
    {
        $actorId = Auth::id();

        return response()->streamDownload(function () use ($filename, $actorId, $clientIp): void {
            $handle = fopen('php://output', 'w');
            if ($handle === false) {
                return;
            }

            $tablesCount = $this->writeSqlDump($handle);
            fclose($handle);

            // Catat ke Audit Log sistem
            try {
                $this->logAudit('backup_database', 'Database', null, null, [
                    'filename' => $filename,
                    'tables_count' => $tablesCount,
                    'driver' => DB::connection()->getDriverName(),
                ], $actorId, $clientIp);
            } catch (\Throwable $e) {
                // Abaikan jika audit log gagal agar stream tidak corrupt
            }
        }, $filename, [
            'Content-Type' => 'application/sql; charset=utf-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }

    /**
     * Tulis struktur dan data SQL ke resource stream.
     *
     * @param  resource  $handle
     * @return int Jumlah tabel yang di-dump
     */
    public function writeSqlDump($handle): int
    {
        $driver = DB::connection()->getDriverName();
        $timestamp = now('Asia/Jakarta')->format('Y-m-d H:i:s');
        $pdo = DB::connection()->getPdo();

        $header = "-- ============================================================\n"
            ."-- Smart Presensi Siswa - Database Backup Dump\n"
            ."-- Tanggal Pembuatan : {$timestamp} WIB\n"
            ."-- Database Driver   : {$driver}\n"
            ."-- Aplikasi          : Smart Presensi Solution X601\n"
            ."-- ============================================================\n\n";

        if ($driver === 'mysql') {
            $header .= "/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;\n"
                ."/*!40101 SET NAMES utf8mb4 */;\n"
                ."/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;\n"
                ."/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;\n\n";
        } elseif ($driver === 'sqlite') {
            $header .= "PRAGMA foreign_keys = OFF;\n\n";
        }

        fwrite($handle, $header);

        $tablesCount = 0;

        if ($driver === 'mysql') {
            $tablesCount = $this->dumpMySql($handle, $pdo);
        } elseif ($driver === 'sqlite') {
            $tablesCount = $this->dumpSqlite($handle, $pdo);
        } else {
            // Generic PDO fallback
            $tablesCount = $this->dumpGeneric($handle, $pdo);
        }

        $footer = "\n-- ============================================================\n"
            ."-- Akhir Dump Database ({$tablesCount} Tabel Diproses)\n";

        if ($driver === 'mysql') {
            $footer .= "/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;\n"
                ."/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;\n"
                ."/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;\n";
        } elseif ($driver === 'sqlite') {
            $footer .= "PRAGMA foreign_keys = ON;\n";
        }

        $footer .= '-- Dump Selesai: '.now('Asia/Jakarta')->format('Y-m-d H:i:s')." WIB\n"
            ."-- ============================================================\n";

        fwrite($handle, $footer);

        return $tablesCount;
    }

    /**
     * Dump khusus MySQL.
     *
     * @param  resource  $handle
     */
    protected function dumpMySql($handle, \PDO $pdo): int
    {
        $tablesRaw = DB::select('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"');
        $tablesCount = 0;

        foreach ($tablesRaw as $tblObj) {
            $table = array_values((array) $tblObj)[0];

            if ($this->skipTable($table)) {
                continue;
            }

            $tablesCount++;

            fwrite($handle, "\n--\n-- Struktur Tabel: `{$table}`\n--\n");
            fwrite($handle, "DROP TABLE IF EXISTS `{$table}`;\n");

            $createRes = DB::select("SHOW CREATE TABLE `{$table}`");
            $createSql = $createRes[0]->{'Create Table'} ?? array_values((array) $createRes[0])[1];
            fwrite($handle, $createSql.";\n\n");

            fwrite($handle, "--\n-- Data Tabel: `{$table}`\n--\n");
            $this->dumpTableData($handle, $pdo, $table, '`');
        }

        return $tablesCount;
    }

    /**
     * Dump khusus SQLite (untuk pengujian atau environment SQLite).
     *
     * @param  resource  $handle
     */
    protected function dumpSqlite($handle, \PDO $pdo): int
    {
        $tables = DB::select("SELECT name, sql FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
        $tablesCount = 0;

        foreach ($tables as $t) {
            $table = $t->name;

            if ($this->skipTable($table)) {
                continue;
            }

            $createSql = $t->sql;
            $tablesCount++;

            fwrite($handle, "\n--\n-- Struktur Tabel: \"{$table}\"\n--\n");
            fwrite($handle, "DROP TABLE IF EXISTS \"{$table}\";\n");
            fwrite($handle, $createSql.";\n\n");

            fwrite($handle, "--\n-- Data Tabel: \"{$table}\"\n--\n");
            $this->dumpTableData($handle, $pdo, $table, '"');
        }

        return $tablesCount;
    }

    /**
     * Dump generik untuk koneksi basis data lainnya.
     *
     * @param  resource  $handle
     */
    protected function dumpGeneric($handle, \PDO $pdo): int
    {
        $schema = DB::connection()->getSchemaBuilder();
        $tables = $schema->getTableListing();
        $tablesCount = 0;

        foreach ($tables as $table) {
            if ($this->skipTable($table)) {
                continue;
            }

            $tablesCount++;
            fwrite($handle, "\n-- Data Tabel: {$table}\n");
            $this->dumpTableData($handle, $pdo, $table, '');
        }

        return $tablesCount;
    }

    /**
     * Tentukan apakah tabel dilewati saat dump (karena dikecualikan atau namanya
     * tidak aman untuk diinterpolasi ke SQL).
     */
    protected function skipTable(string $table): bool
    {
        if (in_array($table, $this->excludedTables, true)) {
            return true;
        }

        // Nama tabel berasal dari metadata DB, tetapi tetap divalidasi sebelum
        // diinterpolasi ke "SHOW CREATE TABLE `{$table}`" (pertahanan berlapis).
        return ! preg_match('/^[A-Za-z0-9_]+$/', $table);
    }

    /**
     * Dump baris-baris data pada sebuah tabel dalam batch aman memori.
     *
     * @param  resource  $handle
     */
    protected function dumpTableData($handle, \PDO $pdo, string $table, string $quoteChar = '`'): void
    {
        $totalRows = DB::table($table)->count();
        if ($totalRows === 0) {
            fwrite($handle, "-- (Tabel kosong)\n\n");

            return;
        }

        $batch = [];
        $batchSize = 100;
        $columns = [];
        $maskedColumns = $this->maskedColumns[$table] ?? [];

        foreach (DB::table($table)->cursor() as $row) {
            $rowArray = (array) $row;
            if (empty($columns)) {
                $columns = array_keys($rowArray);
            }

            $escapedValues = [];
            foreach ($rowArray as $column => $value) {
                // Kolom rahasia diganti NULL agar tidak bocor ke file backup.
                if (in_array($column, $maskedColumns, true)) {
                    $escapedValues[] = 'NULL';

                    continue;
                }

                if (is_null($value)) {
                    $escapedValues[] = 'NULL';
                } elseif (is_int($value) || is_float($value)) {
                    $escapedValues[] = (string) $value;
                } elseif (is_bool($value)) {
                    $escapedValues[] = $value ? '1' : '0';
                } else {
                    $escapedValues[] = $pdo->quote((string) $value);
                }
            }

            $batch[] = '('.implode(', ', $escapedValues).')';

            if (count($batch) >= $batchSize) {
                $this->writeInsertStatement($handle, $table, $columns, $batch, $quoteChar);
                $batch = [];
            }
        }

        if (! empty($batch)) {
            $this->writeInsertStatement($handle, $table, $columns, $batch, $quoteChar);
        }

        fwrite($handle, "\n");
    }

    /**
     * Tulis blok INSERT INTO ke file dump.
     *
     * @param  resource  $handle
     * @param  array<string>  $columns
     * @param  array<string>  $valuesList
     */
    protected function writeInsertStatement($handle, string $table, array $columns, array $valuesList, string $quoteChar): void
    {
        $quotedColumns = array_map(fn ($col) => "{$quoteChar}{$col}{$quoteChar}", $columns);
        $colStr = implode(', ', $quotedColumns);
        $valStr = implode(",\n  ", $valuesList);

        $sql = "INSERT INTO {$quoteChar}{$table}{$quoteChar} ({$colStr}) VALUES\n  {$valStr};\n";
        fwrite($handle, $sql);
    }
}
