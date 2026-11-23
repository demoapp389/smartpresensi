<?php

namespace App\Services\WhatsApp;

use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Pengatur jeda pengiriman WhatsApp agar nomor sekolah tidak diblokir provider.
 *
 * Jeda diambil acak di antara batas konfigurasi (default 10-20 detik) dan
 * ditegakkan lintas job/worker memakai cache store 'locks', sehingga beberapa
 * proses yang berjalan bersamaan tidak mengirim pesan secara beruntun.
 */
class WhatsAppSendPacer
{
    private const LOCK_KEY = 'whatsapp:send-slot';

    private const LAST_SENT_KEY = 'whatsapp:last-send-at';

    public function minDelayMs(): int
    {
        return max(0, (int) config('whatsapp.throttle.min_delay_ms', 10000));
    }

    public function maxDelayMs(): int
    {
        return max($this->minDelayMs(), (int) config('whatsapp.throttle.max_delay_ms', 20000));
    }

    /**
     * Jeda acak satu pesan dalam detik (dipakai untuk menjadwalkan delay job).
     */
    public function nextGapSeconds(): int
    {
        $gapMs = random_int($this->minDelayMs(), $this->maxDelayMs());

        return (int) ceil($gapMs / 1000);
    }

    /**
     * Rata-rata jeda per pesan dalam detik (untuk estimasi durasi siaran).
     */
    public function averageGapSeconds(): float
    {
        return (($this->minDelayMs() + $this->maxDelayMs()) / 2) / 1000;
    }

    /**
     * Jalankan pengiriman setelah jeda aman sejak pesan terakhir.
     *
     * Lock dipegang selama menunggu dan mengirim agar dua worker tidak bisa
     * melewati jeda secara bersamaan.
     *
     * @template TReturn
     *
     * @param  Closure():TReturn  $send
     * @return TReturn
     */
    public function send(Closure $send): mixed
    {
        if ($this->maxDelayMs() <= 0) {
            return $send();
        }

        $store = Cache::store('locks');
        $lock = $store->lock(self::LOCK_KEY, 120);
        $lock->block(120);

        try {
            $lastSentAt = (float) $store->get(self::LAST_SENT_KEY, 0);
            $waitSeconds = ($lastSentAt + $this->nextGapSeconds()) - microtime(true);

            if ($waitSeconds > 0) {
                usleep((int) ($waitSeconds * 1_000_000));
            }

            $result = $send();

            $store->put(self::LAST_SENT_KEY, microtime(true), 3600);

            return $result;
        } finally {
            $lock->release();
        }
    }
}
