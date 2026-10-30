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

        $service->recordAttendanceSync($this->device, 'success', 5, 'Ada 5 log baru.');
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
}
