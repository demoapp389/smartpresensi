<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\DeviceUser;
use App\Models\FingerprintDevice;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Services\AttendanceProcessingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BUG-04: klasifikasi waktu memakai zona waktu perangkat, bukan hardcode.
 * DB-03: menghapus perangkat tidak menghapus jejak log absensi.
 */
class AttendanceTimezoneAndLogRetentionTest extends TestCase
{
    use RefreshDatabase;

    private FingerprintDevice $device;

    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();

        $class = SchoolClass::create(['name' => 'X-IPA-1', 'academic_year' => '2026/2027']);

        $this->student = Student::create([
            'nis' => '1001',
            'name' => 'Budi Santoso',
            'gender' => 'L',
            'class_id' => $class->id,
            'active' => true,
        ]);

        $this->device = FingerprintDevice::create([
            'name' => 'X601 Timur',
            'ip' => '192.168.1.231',
            'port' => 4370,
            'comm_key' => '0',
            'timezone' => 'Pacific/Kiritimati',
            'active' => true,
        ]);

        DeviceUser::create([
            'device_id' => $this->device->id,
            'machine_user_id' => '1001',
            'student_id' => $this->student->id,
        ]);
    }

    private function makeLog(string $occurredAt, string $sourceKey = 'x601_1001_ts'): AttendanceLog
    {
        return AttendanceLog::create([
            'device_id' => $this->device->id,
            'machine_user_id' => '1001',
            'occurred_at' => $occurredAt,
            'received_at' => Carbon::parse($occurredAt, $this->device->timezone)
                ->setTimezone(config('app.timezone'))
                ->addMinute(),
            'source_key' => $sourceKey,
            'status' => 'unprocessed',
        ]);
    }

    public function test_scan_time_uses_device_timezone_without_shifting_wall_clock(): void
    {
        $log = $this->makeLog('2026-10-14 23:30:00');

        $scanTime = app(AttendanceProcessingService::class)->resolveScanTime($log->fresh());

        $this->assertSame('Pacific/Kiritimati', $scanTime->timezoneName);
        // Jam dinding dipertahankan apa adanya (bukan digeser).
        $this->assertSame('2026-10-14 23:30:00', $scanTime->format('Y-m-d H:i:s'));
    }

    public function test_process_log_classifies_using_device_local_time(): void
    {
        $log = $this->makeLog('2026-10-14 06:45:00');

        $attendance = app(AttendanceProcessingService::class)->processLog($log);

        $this->assertNotNull($attendance);
        $this->assertSame('2026-10-14', $attendance->school_date->toDateString());
        $this->assertSame('06:45:00', $attendance->check_in_at->format('H:i:s'));
    }

    public function test_deleting_device_keeps_attendance_logs_and_nulls_device_id(): void
    {
        $log = $this->makeLog('2026-10-14 07:00:00', 'x601_retention_1');

        $this->device->delete();

        $fresh = AttendanceLog::find($log->id);

        $this->assertNotNull($fresh, 'Log absensi tidak boleh ikut terhapus bersama perangkat.');
        $this->assertNull($fresh->device_id);
    }
}
