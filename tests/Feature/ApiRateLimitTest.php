<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiRateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_ingest_endpoint_is_rate_limited_per_device_token(): void
    {
        $headers = ['X-Device-Token' => 'rate-limit-test-token'];

        $firstStatus = null;
        $lastStatus = null;
        for ($i = 0; $i < 121; $i++) {
            $lastStatus = $this->withHeaders($headers)
                ->postJson(route('api.fingerprint.ingest'), [])
                ->getStatusCode();

            $firstStatus ??= $lastStatus;
        }

        $this->assertNotSame(429, $firstStatus);
        $this->assertSame(429, $lastStatus);
    }

    public function test_progress_endpoint_is_rate_limited_per_ip(): void
    {
        $firstStatus = null;
        $lastStatus = null;
        for ($i = 0; $i < 121; $i++) {
            $lastStatus = $this->getJson(route('api.progress.show', ['uuid' => 'missing-uuid']))
                ->getStatusCode();

            $firstStatus ??= $lastStatus;
        }

        $this->assertNotSame(429, $firstStatus);
        $this->assertSame(429, $lastStatus);
    }
}
