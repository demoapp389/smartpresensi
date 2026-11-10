<?php

namespace Tests\Feature;

use App\Models\FingerprintDevice;
use App\Models\User;
use App\Services\SystemControlService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\FakeProcessResult;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class SystemControlTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected FingerprintDevice $device;

    protected function setUp(): void
    {
        parent::setUp();

        config(['system_control.driver' => 'windows']);

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

    public function test_linux_provider_reports_running_systemd_units_as_healthy(): void
    {
        config(['system_control.driver' => 'linux']);
        $this->fakeSystemdProcesses();

        $status = (new SystemControlService)->getSystemStatus();

        $this->assertSame('linux', $status['platform']);
        $this->assertSame('Linux (systemd)', $status['platform_label']);
        $this->assertSame('healthy', $status['overall_status']);
        $this->assertSame('running', $status['connector']['status']);
        $this->assertSame('running', $status['worker']['status']);
        $this->assertSame('systemd', $status['scheduler']['mode']);
        $this->assertSame('running', $status['scheduler']['status']);
        $this->assertNull($status['unavailable_reason']);
    }

    public function test_linux_provider_degrades_when_connector_unit_is_inactive(): void
    {
        config(['system_control.driver' => 'linux']);
        $this->fakeSystemdProcesses([
            config('system_control.units.connector') => 'inactive',
        ]);

        $status = (new SystemControlService)->getSystemStatus();

        $this->assertSame('degraded', $status['overall_status']);
        $this->assertSame('stopped', $status['connector']['status']);
        $this->assertFalse($status['connector']['running']);
        $this->assertSame('running', $status['worker']['status']);
    }

    public function test_linux_scheduler_falls_back_to_cron_file(): void
    {
        config(['system_control.driver' => 'linux']);

        $cronFile = storage_path('framework/testing/smartpresensi-scheduler.cron');
        File::ensureDirectoryExists(dirname($cronFile));
        File::put($cronFile, "* * * * * www-data php artisan schedule:run\n");

        config(['system_control.scheduler_cron_file' => $cronFile]);

        try {
            $this->fakeSystemdProcesses([
                config('system_control.units.scheduler') => 'inactive',
            ], [
                config('system_control.units.scheduler') => ['LoadState' => 'not-found'],
            ]);

            $status = (new SystemControlService)->getSystemStatus();

            $this->assertSame('cron', $status['scheduler']['mode']);
            $this->assertSame('scheduled', $status['scheduler']['status']);
            $this->assertTrue($status['scheduler']['running']);
            $this->assertSame('healthy', $status['overall_status']);
        } finally {
            File::delete($cronFile);
        }
    }

    public function test_linux_provider_reports_unavailable_when_systemctl_cannot_run(): void
    {
        config(['system_control.driver' => 'linux']);
        config(['system_control.scheduler_cron_file' => storage_path('framework/testing/missing-scheduler.cron')]);

        Process::fake(fn (): FakeProcessResult => Process::result('', 'systemctl: command not found', 127));

        $status = (new SystemControlService)->getSystemStatus();

        $this->assertSame('degraded', $status['overall_status']);
        $this->assertSame('unavailable', $status['connector']['status']);
        $this->assertSame('unavailable', $status['worker']['status']);
        $this->assertNotNull($status['unavailable_reason']);
        $this->assertStringContainsString('systemctl', $status['unavailable_reason']);
        $this->assertFalse($status['heartbeat']['fresh']);
    }

    public function test_linux_restart_uses_restricted_helper(): void
    {
        $helper = storage_path('framework/testing/smartpresensi-service');
        File::ensureDirectoryExists(dirname($helper));
        File::put($helper, "#!/usr/bin/env bash\n");

        config([
            'system_control.driver' => 'linux',
            'system_control.restart_helper' => $helper,
        ]);

        try {
            Process::fake(['*' => Process::result('', '', 0)]);

            $result = (new SystemControlService)->restartConnector();

            $this->assertTrue($result['success']);

            Process::assertRan(fn (PendingProcess $process): bool => $process->command === [
                '/usr/bin/sudo',
                '-n',
                $helper,
                'restart',
                'connector',
            ]);
        } finally {
            File::delete($helper);
        }
    }

    public function test_linux_restart_reports_missing_helper_with_manual_command(): void
    {
        config([
            'system_control.driver' => 'linux',
            'system_control.restart_helper' => storage_path('framework/testing/missing-helper'),
        ]);

        $result = (new SystemControlService)->restartAll();

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('belum terpasang', $result['message']);
        $this->assertStringContainsString('sudo systemctl restart', $result['message']);
    }

    public function test_linux_restart_supervisor_is_not_supported(): void
    {
        config(['system_control.driver' => 'linux']);

        $result = (new SystemControlService)->restartSupervisor();

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Windows', $result['message']);
    }

    public function test_linux_control_page_shows_systemd_services_without_supervisor_restart(): void
    {
        config(['system_control.driver' => 'linux']);
        $this->fakeSystemdProcesses();

        $response = $this->actingAs($this->admin)->get(route('system.control'));

        $response->assertStatus(200);
        $response->assertSee('Pusat Kontrol Sistem');
        $response->assertSee('Linux (systemd)');
        $response->assertSee('Python Fingerprint Connector');
        $response->assertSee('systemd');
        $response->assertDontSee('Restart Supervisor');
    }

    public function test_linux_logs_are_read_from_configured_service_log(): void
    {
        $logPath = storage_path('framework/testing/linux-connector.log');
        File::ensureDirectoryExists(dirname($logPath));
        File::put($logPath, "linux-line-1\nlinux-line-2\n");

        config([
            'system_control.driver' => 'linux',
            'system_control.logs.connector' => $logPath,
        ]);

        try {
            $logs = (new SystemControlService)->getLogs('connector');

            $this->assertSame(['linux-line-1', 'linux-line-2'], $logs);
        } finally {
            File::delete($logPath);
        }
    }

    public function test_linux_logs_explain_journalctl_when_log_file_is_missing(): void
    {
        config([
            'system_control.driver' => 'linux',
            'system_control.logs.worker' => storage_path('framework/testing/missing-worker.log'),
        ]);

        $logs = (new SystemControlService)->getLogs('worker');

        $this->assertNotEmpty($logs);
        $this->assertStringContainsString('journalctl -u smartpresensi-worker', implode("\n", $logs));
    }

    /**
     * @param  array<string, string>  $states
     * @param  array<string, array<string, string>>  $properties
     */
    private function fakeSystemdProcesses(array $states = [], array $properties = []): void
    {
        Process::fake(function (PendingProcess $process) use ($states, $properties): FakeProcessResult {
            $command = implode(' ', (array) $process->command);
            $unit = (string) last((array) $process->command);

            if (str_contains($command, 'is-active')) {
                return Process::result(($states[$unit] ?? 'active')."\n");
            }

            $values = array_merge([
                'LoadState' => 'loaded',
                'MainPID' => '1234',
                'ActiveEnterTimestamp' => 'Mon 2026-11-10 08:00:00 WIB',
                'NRestarts' => '0',
            ], $properties[$unit] ?? []);

            $output = '';

            foreach ($values as $key => $value) {
                $output .= "{$key}={$value}\n";
            }

            return Process::result($output);
        });
    }
}
