<?php

namespace App\Services\SystemControl;

use App\Models\FingerprintDevice;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Kontrak dan logika bersama penyedia status Pusat Kontrol Sistem.
 *
 * Implementasi konkret menentukan cara membaca status layanan: supervisor
 * Windows atau unit systemd di Linux.
 */
abstract class SystemStatusProvider
{
    abstract public function platform(): string;

    abstract public function platformLabel(): string;

    /**
     * @return array<string, mixed>
     */
    abstract public function getStatus(): array;

    /**
     * @return array{success: bool, message: string}
     */
    abstract public function restart(string $service): array;

    abstract public function supportsRestart(string $service): bool;

    /**
     * @return array<int, string>
     */
    abstract public function getLogs(string $service): array;

    /**
     * @return array{pending_jobs: int, failed_jobs: int}
     */
    protected function queueMetrics(): array
    {
        return [
            'pending_jobs' => DB::table('jobs')->count(),
            'failed_jobs' => DB::table('failed_jobs')->count(),
        ];
    }

    /**
     * @return array{total: int, online: int, list: Collection<int, FingerprintDevice>}
     */
    protected function deviceStatus(): array
    {
        $devices = FingerprintDevice::where('active', true)->get();

        return [
            'total' => $devices->count(),
            'online' => $devices->filter(
                fn (FingerprintDevice $device): bool => $device->displayStatus() === 'active'
            )->count(),
            'list' => $devices,
        ];
    }

    /**
     * Baca sejumlah baris terakhir dari file log tanpa memuat seluruh isi.
     *
     * @return array<int, string>
     */
    protected function tailLines(string $path, int $count = 50): array
    {
        if (! File::exists($path)) {
            return [];
        }

        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return [];
        }

        $buffer = '';
        $lines = [];
        $chunkSize = 8192;

        fseek($handle, 0, SEEK_END);
        $pos = ftell($handle);

        while ($pos > 0 && count($lines) <= $count) {
            $read = (int) min($chunkSize, $pos);
            $pos -= $read;
            fseek($handle, $pos);
            $buffer = fread($handle, $read).$buffer;
            $lines = preg_split("/\r\n|\n|\r/", $buffer) ?: [];
        }

        fclose($handle);

        $lines = array_values(array_filter($lines, fn (string $line): bool => $line !== ''));

        return array_slice($lines, -$count);
    }

    /**
     * @param  array<int, string>  $paths
     * @return array<int, string>
     */
    protected function readFirstAvailableLog(array $paths, string $service): array
    {
        foreach ($paths as $path) {
            $lines = $this->tailLines($path);

            if ($lines !== []) {
                return $lines;
            }
        }

        return ["Belum ada log tercatat untuk service {$service}."];
    }
}
