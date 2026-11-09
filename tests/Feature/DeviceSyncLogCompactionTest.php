<?php

namespace Tests\Feature;

use App\Models\DeviceSyncLog;
use App\Models\FingerprintDevice;
use App\Models\User;
use App\Services\DeviceSyncLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceSyncLogCompactionTest extends TestCase
{
    use RefreshDatabase;

    private FingerprintDevice $device;

    protected function setUp(): void
    {
        parent::setUp();

        $this->device = FingerprintDevice::create([
            'name' => 'X601 Gerbang',
            'ip' => '192.168.1.211',
            'port' => 4370,
            'comm_key' => '0',
            'timezone' => 'Asia/Jakarta',
            'active' => true,
        ]);
    }

    public function test_automatic_failures_are_recorded_at_most_once_per_hour(): void
    {
        $service = app(DeviceSyncLogService::class);

        $service->recordAttendanceSync($this->device, 'failed', 0, 'Mesin offline.');
        $service->recordAttendanceSync($this->device, 'failed', 0, 'Mesin offline.');
        $service->recordAttendanceSync($this->device, 'failed', 0, 'Mesin offline.');

        $this->assertSame(1, DeviceSyncLog::where('device_id', $this->device->id)->count());

        $this->travel(61)->minutes();

        $service->recordAttendanceSync($this->device, 'failed', 0, 'Mesin masih offline.');

        $this->assertSame(2, DeviceSyncLog::where('device_id', $this->device->id)->count());
    }

    public function test_automatic_empty_success_is_skipped_except_as_recovery_signal(): void
    {
        $service = app(DeviceSyncLogService::class);

        $this->assertNull($service->recordAttendanceSync($this->device, 'success', 0, 'Tidak ada log baru.'));
        $this->assertSame(0, DeviceSyncLog::count());

        $service->recordAttendanceSync($this->device, 'failed', 0, 'Mesin offline.');
        $recovery = $service->recordAttendanceSync($this->device, 'success', 0, 'Mesin pulih.');

        $this->assertNotNull($recovery);
        $this->assertSame(2, DeviceSyncLog::count());
        $this->assertSame('success', DeviceSyncLog::latest('id')->first()->status);

        $this->assertNull($service->recordAttendanceSync($this->device, 'success', 0, 'Masih tidak ada log baru.'));
        $this->assertSame(2, DeviceSyncLog::count());

        $service->recordAttendanceSync($this->device, 'success', 15, 'Ada 15 log baru.');
        $this->assertSame(3, DeviceSyncLog::count());

        $this->assertNull($service->recordAttendanceSync($this->device, 'success', 0, 'Tidak ada log baru.'));
        $this->assertSame(3, DeviceSyncLog::count());
    }

    public function test_manual_sync_history_is_always_recorded(): void
    {
        $operator = User::factory()->create();
        $service = app(DeviceSyncLogService::class);

        $service->recordAttendanceSync($this->device, 'success', 0, 'Sinkron manual.', [], $operator->id);
        $service->recordAttendanceSync($this->device, 'success', 0, 'Sinkron manual lagi.', [], $operator->id);
        $service->recordAttendanceSync($this->device, 'failed', 0, 'Gagal manual.', [], $operator->id);
        $service->recordAttendanceSync($this->device, 'failed', 0, 'Gagal manual lagi.', [], $operator->id);

        $this->assertSame(4, DeviceSyncLog::where('device_id', $this->device->id)->count());
    }

    public function test_automatic_success_with_few_records_is_throttled(): void
    {
        $service = app(DeviceSyncLogService::class);

        $this->assertNotNull($service->recordAttendanceSync($this->device, 'success', 1, 'Ada 1 log baru.'));
        $this->assertNull($service->recordAttendanceSync($this->device, 'success', 2, 'Ada 2 log baru.'));
        $this->assertNull($service->recordAttendanceSync($this->device, 'success', 3, 'Ada 3 log baru.'));
        $this->assertSame(1, DeviceSyncLog::count());

        // Backlog besar tetap dicatat walau baru saja ada sinkronisasi.
        $this->assertNotNull($service->recordAttendanceSync($this->device, 'success', 20, 'Ada 20 log baru.'));
        $this->assertSame(2, DeviceSyncLog::count());

        // Setelah interval heartbeat lewat, record sedikit dicatat lagi.
        $this->travel(61)->minutes();
        $this->assertNotNull($service->recordAttendanceSync($this->device, 'success', 1, 'Ada 1 log baru.'));
        $this->assertSame(3, DeviceSyncLog::count());
    }

    public function test_automatic_success_after_failure_is_always_recorded(): void
    {
        $service = app(DeviceSyncLogService::class);

        $service->recordAttendanceSync($this->device, 'success', 1, 'Ada 1 log baru.');
        $service->recordAttendanceSync($this->device, 'failed', 0, 'Mesin offline.');
        $recovery = $service->recordAttendanceSync($this->device, 'success', 1, 'Mesin pulih.');

        $this->assertNotNull($recovery);
        $this->assertSame(3, DeviceSyncLog::count());
    }
}
