<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Jeda kirim WhatsApp dinonaktifkan agar test suite tidak benar-benar
        // menunggu 10-20 detik per pesan. Test pacing meng-override config ini
        // sendiri sebelum menjalankan job.
        config([
            'whatsapp.throttle.min_delay_ms' => 0,
            'whatsapp.throttle.max_delay_ms' => 0,
        ]);
    }
}
