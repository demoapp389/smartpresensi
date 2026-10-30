<?php

namespace App\Services;

use App\Models\FingerprintDevice;
use Illuminate\Support\Str;

/**
 * Menyusun isi berkas `connector_config.ini` untuk connector LAN.
 *
 * Dipisahkan dari controller agar logika pembentukan konfigurasi (termasuk
 * pembuatan key unik per mesin) dapat diuji terpisah tanpa HTTP request.
 */
class ConnectorConfigBuilder
{
    /**
     * Bangun isi berkas konfigurasi connector siap unduh.
     */
    public function build(FingerprintDevice $device): string
    {
        $lines = [
            '# Konfigurasi Connector Solution X601 (LAN, multi-mesin)',
            '# Dihasilkan otomatis dari panel admin. JANGAN di-commit (berisi token rahasia).',
            '[default]',
            'api_url='.url('/api/v1/fingerprint/ingest'),
            'poll_interval_seconds=5',
            'reconnect_interval_seconds=5',
            'local_api_port=4371',
            'local_api_token='.config('services.connector.local_api_token', ''),
            'batch_size=20',
            'clock_sync_minutes=60',
            'clock_max_drift_seconds=5',
            'clock_allow_backward=false',
            '',
        ];

        foreach ($this->devices($device) as $row) {
            $lines[] = '[device:'.$row['key'].']';
            $lines[] = '# '.$row['name'];
            $lines[] = 'ip='.$row['ip'];
            $lines[] = 'port='.$row['port'];
            $lines[] = 'comm_key='.$row['comm_key'];
            $lines[] = 'token='.$row['token'];
            $lines[] = 'enabled='.($row['enabled'] ? 'true' : 'false');
            $lines[] = 'mode=poll';
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * Daftar mesin yang disertakan pada file konfigurasi connector.
     *
     * Mesin yang diunduh selalu disertakan (agar operator yang mengunduh dari mesin
     * nonaktif sekalipun tetap mendapat konfigurasi mesin itu). Mesin aktif lain ikut
     * disertakan supaya satu connector dapat melayani seluruh jaringan.
     *
     * @return array<int, array{key: string, name: string, ip: string, port: int, comm_key: string, token: string, enabled: bool}>
     */
    public function devices(FingerprintDevice $device): array
    {
        $devices = FingerprintDevice::query()
            ->where(function ($query) use ($device) {
                $query->where('active', true)->orWhere('id', $device->id);
            })
            ->orderBy('id')
            ->get();

        $usedKeys = [];

        return $devices->map(function (FingerprintDevice $item) use (&$usedKeys) {
            $base = Str::slug($item->name) ?: 'device';
            if ($base === 'default') {
                $base = 'default-device';
            }

            $key = $base;
            $suffix = 2;
            while (in_array($key, $usedKeys, true)) {
                $key = $base.'-'.$suffix;
                $suffix++;
            }
            $usedKeys[] = $key;

            return [
                'key' => $key,
                'name' => $item->name,
                'ip' => $item->ip,
                'port' => (int) $item->port,
                'comm_key' => $item->comm_key ?? '0',
                'token' => $item->secret_token,
                'enabled' => (bool) $item->active,
            ];
        })->all();
    }
}
