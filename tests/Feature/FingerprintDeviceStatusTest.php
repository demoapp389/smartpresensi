<?php

namespace Tests\Feature;

use App\Models\FingerprintDevice;
use App\Models\User;
use App\Services\FingerprintConnectorService;
use App\Services\FingerprintIngestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FingerprintDeviceStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function createDevice(array $attributes = []): FingerprintDevice
    {
        return FingerprintDevice::create(array_merge([
            'name' => 'Solution X601 Utama',
            'ip' => '192.168.1.201',
            'port' => 4370,
            'comm_key' => '0',
            'timezone' => 'Asia/Jakarta',
            'status' => 'offline',
            'secret_token' => 'test_sec_token_status',
            'active' => true,
        ], $attributes));
    }

    public function test_health_check_marks_reachable_device_online(): void
    {
        $device = $this->createDevice(['status' => 'offline', 'last_seen_at' => null]);

        $mockService = $this->createMock(FingerprintConnectorService::class);
        $mockService->expects($this->once())
            ->method('checkConnectivity')
            ->with($this->callback(fn ($d) => $d->id === $device->id))
            ->willReturn(true);

        $this->app->instance(FingerprintConnectorService::class, $mockService);

        $this->artisan('fingerprint:health-check')->assertExitCode(0);

        $device->refresh();
        $this->assertSame('active', $device->status);
        $this->assertNotNull($device->last_seen_at);
    }

    public function test_health_check_marks_unreachable_device_offline(): void
    {
        $device = $this->createDevice(['status' => 'active', 'last_seen_at' => now()->subMinutes(30)]);

        $mockService = $this->createMock(FingerprintConnectorService::class);
        $mockService->expects($this->once())
            ->method('checkConnectivity')
            ->willReturn(false);

        $this->app->instance(FingerprintConnectorService::class, $mockService);

        $this->artisan('fingerprint:health-check')->assertExitCode(0);

        $this->assertSame('offline', $device->refresh()->status);
    }

    public function test_health_check_does_not_probe_or_override_maintenance_device(): void
    {
        $device = $this->createDevice(['status' => 'maintenance']);

        $mockService = $this->createMock(FingerprintConnectorService::class);
        $mockService->expects($this->never())->method('checkConnectivity');

        $this->app->instance(FingerprintConnectorService::class, $mockService);

        $this->artisan('fingerprint:health-check')->assertExitCode(0);

        $this->assertSame('maintenance', $device->refresh()->status);
    }

    public function test_health_check_ignores_inactive_device(): void
    {
        $device = $this->createDevice(['status' => 'offline', 'active' => false]);

        $mockService = $this->createMock(FingerprintConnectorService::class);
        $mockService->expects($this->never())->method('checkConnectivity');

        $this->app->instance(FingerprintConnectorService::class, $mockService);

        $this->artisan('fingerprint:health-check')->assertExitCode(0);

        $this->assertSame('offline', $device->refresh()->status);
    }

    public function test_ping_endpoint_marks_device_online(): void
    {
        $device = $this->createDevice(['status' => 'offline', 'last_seen_at' => null]);

        $response = $this->withHeaders([
            'X-Device-Token' => $device->secret_token,
        ])->getJson(route('api.fingerprint.ping'));

        $response->assertStatus(200)
            ->assertJson(['status' => 'ok']);

        $device->refresh();
        $this->assertSame('active', $device->status);
        $this->assertNotNull($device->last_seen_at);
    }

    public function test_ping_endpoint_rejects_inactive_device(): void
    {
        $device = $this->createDevice(['status' => 'offline', 'active' => false]);

        $this->withHeaders([
            'X-Device-Token' => $device->secret_token,
        ])->getJson(route('api.fingerprint.ping'))
            ->assertStatus(401)
            ->assertJson(['status' => 'unauthorized']);

        $this->assertSame('offline', $device->refresh()->status);
    }

    public function test_log_ingest_marks_device_online(): void
    {
        $device = $this->createDevice(['status' => 'offline', 'last_seen_at' => null]);

        app(FingerprintIngestService::class)->ingestBatch($device, []);

        $device->refresh();
        $this->assertSame('active', $device->status);
        $this->assertNotNull($device->last_seen_at);
    }

    public function test_heartbeat_does_not_override_maintenance_status(): void
    {
        $device = $this->createDevice(['status' => 'maintenance']);

        app(FingerprintIngestService::class)->ingestBatch($device, []);

        $this->assertSame('maintenance', $device->refresh()->status);
    }

    public function test_status_endpoint_returns_cached_online_status_without_probing(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $lastSeenAt = now()->subSeconds(30)->startOfSecond();
        $device = $this->createDevice(['status' => 'active', 'last_seen_at' => $lastSeenAt]);

        $mockService = $this->createMock(FingerprintConnectorService::class);
        $mockService->expects($this->never())->method('checkConnectivity');
        $this->app->instance(FingerprintConnectorService::class, $mockService);

        $response = $this->actingAs($admin)->getJson(route('devices.status', $device));

        $response->assertOk()
            ->assertJson([
                'device_id' => $device->id,
                'status' => 'active',
                'display_status' => 'active',
            ]);

        $this->assertStringContainsString('Online', $response->json('html'));

        $device->refresh();
        $this->assertSame('active', $device->status);
        $this->assertTrue($lastSeenAt->equalTo($device->last_seen_at));
    }

    public function test_status_endpoint_returns_cached_offline_status_without_probing(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $device = $this->createDevice(['status' => 'offline', 'last_seen_at' => null]);

        $mockService = $this->createMock(FingerprintConnectorService::class);
        $mockService->expects($this->never())->method('checkConnectivity');
        $this->app->instance(FingerprintConnectorService::class, $mockService);

        $this->actingAs($admin)
            ->getJson(route('devices.status', $device))
            ->assertOk()
            ->assertJson([
                'device_id' => $device->id,
                'status' => 'offline',
                'display_status' => 'offline',
            ]);

        $device->refresh();
        $this->assertSame('offline', $device->status);
        $this->assertNull($device->last_seen_at);
    }

    public function test_status_endpoint_reports_stale_active_device_as_offline_without_probing(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $lastSeenAt = now()->subMinutes(3)->startOfSecond();
        $device = $this->createDevice(['status' => 'active', 'last_seen_at' => $lastSeenAt]);

        $mockService = $this->createMock(FingerprintConnectorService::class);
        $mockService->expects($this->never())->method('checkConnectivity');
        $this->app->instance(FingerprintConnectorService::class, $mockService);

        $response = $this->actingAs($admin)->getJson(route('devices.status', $device));

        $response->assertOk()
            ->assertJson([
                'device_id' => $device->id,
                'status' => 'active',
                'display_status' => 'offline',
            ]);

        $this->assertStringContainsString('Offline', $response->json('html'));

        $device->refresh();
        $this->assertSame('active', $device->status);
        $this->assertTrue($lastSeenAt->equalTo($device->last_seen_at));
    }

    public function test_status_endpoint_does_not_probe_maintenance_device(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $device = $this->createDevice(['status' => 'maintenance']);

        $mockService = $this->createMock(FingerprintConnectorService::class);
        $mockService->expects($this->never())->method('checkConnectivity');
        $this->app->instance(FingerprintConnectorService::class, $mockService);

        $this->actingAs($admin)
            ->getJson(route('devices.status', $device))
            ->assertOk()
            ->assertJson([
                'status' => 'maintenance',
                'display_status' => 'maintenance',
            ]);

        $this->assertSame('maintenance', $device->refresh()->status);
    }

    public function test_index_displays_stale_active_device_as_offline(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->createDevice([
            'status' => 'active',
            'last_seen_at' => now()->subMinutes(3),
        ]);

        $this->actingAs($admin)
            ->get(route('devices.index'))
            ->assertOk()
            ->assertSee('Offline')
            ->assertDontSee('Online');
    }
}
