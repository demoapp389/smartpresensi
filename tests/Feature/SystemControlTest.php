<?php

namespace Tests\Feature;

use App\Models\FingerprintDevice;
use App\Models\User;
use App\Services\SystemControlService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SystemControlTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected FingerprintDevice $device;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role' => 'admin',
        ]);

        $this->device = FingerprintDevice::create([
            'name' => 'Solution X601 Lab',
            'ip' => '192.168.1.201',
            'port' => 4370,
            'comm_key' => '0',
            'timezone' => 'Asia/Jakarta',
            'status' => 'active',
            'active' => true,
        ]);
    }

    public function test_admin_can_access_system_control_page(): void
    {
        $response = $this->actingAs($this->admin)->get(route('system.control'));

        $response->assertStatus(200);
        $response->assertSee('Pusat Kontrol Sistem');
        $response->assertSee('C# Fingerprint Connector');
        $response->assertSee('Laravel Queue Worker');
        $response->assertSee('Laravel Scheduler');
        $response->assertSee('Solution X601 Lab');
        $response->assertSee('Restart Supervisor');
    }

    public function test_system_control_service_evaluates_heartbeat_status(): void
    {
        Http::fake(['*' => Http::response('', 500)]);

        $hbDir = storage_path('app/heartbeats');
        if (! File::exists($hbDir)) {
            File::makeDirectory($hbDir, 0755, true);
        }

        $hbFile = $hbDir.'/service_status.json';
        File::put($hbFile, json_encode([
            'last_heartbeat' => now()->format('Y-m-d H:i:s'),
            'supervisor' => ['status' => 'running', 'pid' => 1234, 'uptime_seconds' => 300],
            'connector' => ['status' => 'running', 'pid' => 2345, 'restart_count' => 0, 'started_at' => now()->toDateTimeString()],
            'worker' => ['status' => 'running', 'pid' => 3456, 'restart_count' => 0, 'started_at' => now()->toDateTimeString()],
            'scheduler' => ['status' => 'running', 'pid' => 4567, 'restart_count' => 0, 'started_at' => now()->toDateTimeString()],
        ]));

        $service = new SystemControlService;
        $status = $service->getSystemStatus();

        $this->assertEquals('healthy', $status['overall_status']);
        $this->assertTrue($status['heartbeat']['fresh']);
        $this->assertTrue($status['connector']['running']);
        $this->assertTrue($status['worker']['running']);
        $this->assertTrue($status['scheduler']['running']);

        // Clean up test file
        File::delete($hbFile);
    }

    public function test_system_control_service_degrades_when_scheduler_stopped(): void
    {
        Http::fake(['*' => Http::response('', 500)]);

        $hbDir = storage_path('app/heartbeats');
        if (! File::exists($hbDir)) {
            File::makeDirectory($hbDir, 0755, true);
        }

        $hbFile = $hbDir.'/service_status.json';
        File::put($hbFile, json_encode([
            'last_heartbeat' => now()->format('Y-m-d H:i:s'),
            'supervisor' => ['status' => 'running', 'pid' => 1234, 'uptime_seconds' => 300],
            'connector' => ['status' => 'running', 'pid' => 2345, 'restart_count' => 0],
            'worker' => ['status' => 'running', 'pid' => 3456, 'restart_count' => 0],
            'scheduler' => ['status' => 'stopped', 'pid' => 0, 'restart_count' => 2],
        ]));

        try {
            $status = (new SystemControlService)->getSystemStatus();

            $this->assertEquals('degraded', $status['overall_status']);
            $this->assertFalse($status['scheduler']['running']);
        } finally {
            File::delete($hbFile);
        }
    }

    public function test_admin_can_trigger_restart_connector(): void
    {
        $mockService = $this->createMock(SystemControlService::class);
        $mockService->expects($this->once())
            ->method('restartConnector')
            ->willReturn([
                'success' => true,
                'message' => 'Connector berhasil di-restart.',
            ]);

        $this->app->instance(SystemControlService::class, $mockService);

        $response = $this->actingAs($this->admin)->post(route('system.restart-connector'));

        $response->assertRedirect();
        $response->assertSessionHas('success', 'Connector berhasil di-restart.');

        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $this->admin->id,
            'action' => 'restart_connector_service',
        ]);
    }

    public function test_admin_can_trigger_restart_worker(): void
    {
        $mockService = $this->createMock(SystemControlService::class);
        $mockService->expects($this->once())
            ->method('restartQueueWorker')
            ->willReturn([
                'success' => true,
                'message' => 'Queue Worker berhasil di-restart.',
            ]);

        $this->app->instance(SystemControlService::class, $mockService);

        $response = $this->actingAs($this->admin)->post(route('system.restart-worker'));

        $response->assertRedirect();
        $response->assertSessionHas('success', 'Queue Worker berhasil di-restart.');

        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $this->admin->id,
            'action' => 'restart_queue_worker_service',
        ]);
    }

    public function test_admin_can_trigger_restart_supervisor(): void
    {
        $mockService = $this->createMock(SystemControlService::class);
        $mockService->expects($this->once())
            ->method('restartSupervisor')
            ->willReturn([
                'success' => true,
                'message' => 'Restart supervisor dijadwalkan.',
            ]);

        $this->app->instance(SystemControlService::class, $mockService);

        $response = $this->actingAs($this->admin)->post(route('system.restart-supervisor'));

        $response->assertRedirect();
        $response->assertSessionHas('success', 'Restart supervisor dijadwalkan.');

        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $this->admin->id,
            'action' => 'restart_supervisor_service',
        ]);
    }

    public function test_admin_can_fetch_live_logs_via_ajax(): void
    {
        $response = $this->actingAs($this->admin)->get(route('system.logs', ['service' => 'connector']));

        $response->assertStatus(200);
        $response->assertJsonStructure(['service', 'logs', 'timestamp']);
    }

    public function test_get_logs_reads_only_the_last_lines_of_the_file(): void
    {
        Http::fake(['*' => Http::response('', 500)]);

        $logPath = storage_path('logs/connector_service.log');
        $original = File::exists($logPath) ? File::get($logPath) : null;

        $manyLines = [];
        for ($i = 1; $i <= 200; $i++) {
            $manyLines[] = "line-{$i}";
        }

        File::put($logPath, implode("\n", $manyLines)."\n");

        try {
            $logs = (new SystemControlService)->getLogs('connector');

            $this->assertCount(50, $logs);
            $this->assertSame('line-151', $logs[0]);
            $this->assertSame('line-200', $logs[49]);
        } finally {
            if ($original === null) {
                File::delete($logPath);
            } else {
                File::put($logPath, $original);
            }
        }
    }
}
