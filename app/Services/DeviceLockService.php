<?php

namespace App\Services;

use App\Models\FingerprintDevice;
use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Serialisasi operasi per mesin fingerprint.
 *
 * Mencegah scheduler, tombol aksi manual, dan proses latar saling menimpa pada
 * mesin yang sama (bentrok baca/tulis, ingest ganda). Menggunakan store cache
 * 'locks' (driver database) agar lease tetap koheren antar-proses meskipun
 * CACHE_STORE utama diset ke 'file'.
 */
class DeviceLockService
{
    public function __construct(private int $defaultTtlSeconds = 300) {}

    /**
     * Jalankan callback sambil memegang lease mesin.
     *
     * @param  Closure():mixed  $callback
     * @return array{locked: bool, result: mixed}
     */
    public function run(FingerprintDevice $device, Closure $callback, string $scope = 'op', ?int $ttlSeconds = null): array
    {
        $lock = Cache::store('locks')->lock(
            "device:{$device->id}:{$scope}",
            $ttlSeconds ?? $this->defaultTtlSeconds
        );

        if (! $lock->get()) {
            return ['locked' => true, 'result' => null];
        }

        try {
            return ['locked' => false, 'result' => $callback()];
        } finally {
            $lock->release();
        }
    }
}
