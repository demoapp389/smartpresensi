<?php

namespace Tests\Feature;

use App\Models\FingerprintDevice;
use App\Models\SchoolHoliday;
use App\Models\SchoolSchedule;
use App\Models\User;
use App\Services\FingerprintConnectorService;
use App\Services\SoapFingerprintService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class IngestRoutingAndReconcileTest extends TestCase
{
    use RefreshDatabase;

    private static int $nextDeviceIp = 10;

    protected function device(array $attributes = []): FingerprintDevice
    {
        return FingerprintDevice::create(array_merge([
            'name' => 'X601',
            'ip' => '192.168.1.'.self::$nextDeviceIp++,
            'port' => 4370,
            'comm_key' => '0',
            'timezone' => 'Asia/Jakarta',
            'status' => 'offline',
            'secret_token' => 'tok_'.uniqid(),
            'active' => true,
        ], $attributes));
    }

    public function test_connector_sync_skips_soap_routed_devices(): void
    {
        $connector = $this->device(['name' => 'Connector', 'ingest_driver' => 'connector']);
        $this->device(['name' => 'SOAP', 'ingest_driver' => 'soap']);

        $mock = $this->createMock(FingerprintConnectorService::class);
        $mock->expects($this->once())
            ->method('syncAttendances')
            ->with($this->callback(fn ($device) => $device->id === $connector->id))
            ->willReturn(['success' => true, 'message' => 'ok']);

        $this->app->instance(FingerprintConnectorService::class, $mock);

        $this->artisan('fingerprint:sync')->assertExitCode(0);
    }

    public function test_connector_sync_processes_explicit_device_regardless_of_driver(): void
    {
        $soap = $this->device(['name' => 'SOAP', 'ingest_driver' => 'soap']);

        $mock = $this->createMock(FingerprintConnectorService::class);
        $mock->expects($this->once())
            ->method('syncAttendances')
            ->with($this->callback(fn ($device) => $device->id === $soap->id))
            ->willReturn(['success' => true, 'message' => 'ok']);

        $this->app->instance(FingerprintConnectorService::class, $mock);

        $this->artisan('fingerprint:sync', ['--device' => $soap->id])->assertExitCode(0);
    }

    public function test_soap_pull_only_processes_soap_routed_devices(): void
    {
        $soap = $this->device(['name' => 'SOAP', 'ingest_driver' => 'soap']);
        $this->device(['name' => 'Connector', 'ingest_driver' => 'connector']);

        $mock = $this->createMock(SoapFingerprintService::class);
        $mock->expects($this->once())
            ->method('pullAttendanceLogs')
            ->with($this->callback(fn ($device) => $device->id === $soap->id))
            ->willReturn(['success' => true, 'message' => 'ok']);

        $this->app->instance(SoapFingerprintService::class, $mock);

        $this->artisan('fingerprint:pull-soap')->assertExitCode(0);
    }

    public function test_pull_soap_is_rejected_for_connector_device(): void
    {
        $device = $this->device(['name' => 'Connector', 'ingest_driver' => 'connector']);
        $device->markOnline();

        $soap = $this->createMock(SoapFingerprintService::class);
        $soap->expects($this->never())->method('pullAttendanceLogs');
        $this->app->instance(SoapFingerprintService::class, $soap);

        $response = $this->actingAs(User::factory()->admin()->create())
            ->post(route('devices.pull-soap', $device));

        $response->assertRedirect(route('devices.show', $device));
        $response->assertSessionHas('error', 'Mesin ini memakai connector C#. Gunakan tombol Sinkron Absensi.');
    }

    public function test_reconcile_dispatches_each_device_to_its_driver(): void
    {
        $connector = $this->device(['name' => 'Connector', 'ingest_driver' => 'connector']);
        $soap = $this->device(['name' => 'SOAP', 'ingest_driver' => 'soap']);

        $connectorMock = $this->createMock(FingerprintConnectorService::class);
        $connectorMock->expects($this->once())
            ->method('syncAttendances')
            ->with($this->callback(fn ($device) => $device->id === $connector->id))
            ->willReturn(['success' => true, 'message' => 'ok']);

        $soapMock = $this->createMock(SoapFingerprintService::class);
        $soapMock->expects($this->once())
            ->method('pullAttendanceLogs')
            ->with($this->callback(fn ($device) => $device->id === $soap->id))
            ->willReturn(['success' => true, 'message' => 'ok']);

        $this->app->instance(FingerprintConnectorService::class, $connectorMock);
        $this->app->instance(SoapFingerprintService::class, $soapMock);

        $this->artisan('fingerprint:reconcile', ['--force' => true])->assertExitCode(0);
    }

    public function test_reconcile_skips_outside_window_when_recently_run(): void
    {
        $this->travelTo(Carbon::parse('2026-10-15 20:00:00'));
        SchoolSchedule::create(['day_name' => 'Kamis', 'day_of_week' => 4, 'is_active' => true]);
        $this->device(['name' => 'Connector', 'ingest_driver' => 'connector']);

        Cache::put('fingerprint:reconcile:last_run', now()->subMinutes(6), now()->addDay());

        $mock = $this->createMock(FingerprintConnectorService::class);
        $mock->expects($this->never())->method('syncAttendances');
        $this->app->instance(FingerprintConnectorService::class, $mock);

        $this->artisan('fingerprint:reconcile')
            ->expectsOutputToContain('dilewati')
            ->assertExitCode(0);
    }

    public function test_reconcile_runs_inside_window_even_when_recently_run(): void
    {
        $this->travelTo(Carbon::parse('2026-10-15 06:10:00'));
        SchoolSchedule::create(['day_name' => 'Kamis', 'day_of_week' => 4, 'is_active' => true]);
        $this->device(['name' => 'Connector', 'ingest_driver' => 'connector']);

        Cache::put('fingerprint:reconcile:last_run', now()->subMinutes(6), now()->addDay());

        $mock = $this->createMock(FingerprintConnectorService::class);
        $mock->expects($this->once())
            ->method('syncAttendances')
            ->willReturn(['success' => true, 'message' => 'ok']);
        $this->app->instance(FingerprintConnectorService::class, $mock);

        $this->artisan('fingerprint:reconcile')->assertExitCode(0);
    }

    public function test_reconcile_runs_during_school_hours_for_late_arrivals(): void
    {
        $this->travelTo(Carbon::parse('2026-10-15 10:00:00'));
        SchoolSchedule::create([
            'day_name' => 'Kamis',
            'day_of_week' => 4,
            'is_active' => true,
            'check_in_start' => '06:00:00',
            'late_after' => '07:15:00',
            'check_out_start' => '14:00:00',
            'end_at' => '16:00:00',
        ]);
        $this->device(['name' => 'Connector', 'ingest_driver' => 'connector']);

        Cache::put('fingerprint:reconcile:last_run', now()->subMinutes(6), now()->addDay());

        $mock = $this->createMock(FingerprintConnectorService::class);
        $mock->expects($this->once())
            ->method('syncAttendances')
            ->willReturn(['success' => true, 'message' => 'ok']);
        $this->app->instance(FingerprintConnectorService::class, $mock);

        $this->artisan('fingerprint:reconcile')->assertExitCode(0);
    }

    public function test_reconcile_ignores_legacy_cached_object_and_stores_scalar_timestamp(): void
    {
        $this->travelTo(Carbon::parse('2026-10-15 06:10:00'));
        SchoolSchedule::create(['day_name' => 'Kamis', 'day_of_week' => 4, 'is_active' => true]);
        $this->device(['name' => 'Connector', 'ingest_driver' => 'connector']);

        Cache::put('fingerprint:reconcile:last_run', new \stdClass, now()->addDay());

        $mock = $this->createMock(FingerprintConnectorService::class);
        $mock->expects($this->once())
            ->method('syncAttendances')
            ->willReturn(['success' => true, 'message' => 'ok']);
        $this->app->instance(FingerprintConnectorService::class, $mock);

        $this->artisan('fingerprint:reconcile')
            ->expectsOutputToContain('Rekonsiliasi adaptif selesai')
            ->assertExitCode(0);

        $this->assertSame(now()->getTimestamp(), Cache::get('fingerprint:reconcile:last_run'));
    }

    public function test_reconcile_treats_active_holiday_as_sparse(): void
    {
        $this->travelTo(Carbon::parse('2026-10-15 06:10:00'));
        SchoolHoliday::create([
            'name' => 'Cuti Bersama',
            'start_date' => '2026-10-15',
            'end_date' => '2026-10-15',
            'is_active' => true,
        ]);
        $this->device(['name' => 'Connector', 'ingest_driver' => 'connector']);

        Cache::put('fingerprint:reconcile:last_run', now()->subMinutes(6), now()->addDay());

        $mock = $this->createMock(FingerprintConnectorService::class);
        $mock->expects($this->never())->method('syncAttendances');
        $this->app->instance(FingerprintConnectorService::class, $mock);

        $this->artisan('fingerprint:reconcile')
            ->expectsOutputToContain('dilewati')
            ->assertExitCode(0);
    }

    public function test_probe_sets_connector_driver_and_capabilities(): void
    {
        $device = $this->device(['name' => 'Connector']);

        $mock = $this->createMock(FingerprintConnectorService::class);
        $mock->expects($this->once())
            ->method('probe')
            ->willReturn([
                'success' => true,
                'code' => 0,
                'message' => 'ok',
                'capabilities' => ['buffered_read' => true, 'live_event' => false],
            ]);
        $this->app->instance(FingerprintConnectorService::class, $mock);

        $this->artisan('fingerprint:probe', ['--device' => $device->id])->assertExitCode(0);

        $device->refresh();
        $this->assertSame('connector', $device->ingest_driver);
        $this->assertTrue($device->supports('buffered_read'));
        $this->assertFalse($device->supports('live_event'));
        $this->assertNotNull($device->last_probe_at);
    }

    public function test_probe_falls_back_to_soap_driver(): void
    {
        $device = $this->device(['name' => 'SOAP']);

        $connectorMock = $this->createMock(FingerprintConnectorService::class);
        $connectorMock->method('probe')->willReturn([
            'success' => false,
            'code' => -201,
            'message' => 'unreachable',
            'capabilities' => [],
        ]);

        $soapMock = $this->createMock(SoapFingerprintService::class);
        $soapMock->expects($this->once())
            ->method('testConnection')
            ->willReturn(['success' => true, 'message' => 'soap ok', 'code' => 0]);

        $this->app->instance(FingerprintConnectorService::class, $connectorMock);
        $this->app->instance(SoapFingerprintService::class, $soapMock);

        $this->artisan('fingerprint:probe', ['--device' => $device->id])->assertExitCode(0);

        $this->assertSame('soap', $device->refresh()->ingest_driver);
    }
}
