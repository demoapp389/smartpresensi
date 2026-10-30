<?php

namespace Tests\Feature;

use App\Jobs\SendWhatsAppNotificationJob;
use App\Models\Attendance;
use App\Models\AttendanceLog;
use App\Models\DeviceUser;
use App\Models\FingerprintDevice;
use App\Models\Guardian;
use App\Models\SchoolClass;
use App\Models\SchoolSchedule;
use App\Models\Student;
use App\Models\User;
use App\Services\AttendanceLogProcessingService;
use App\Services\AttendanceProcessingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class AttendanceLogRetryTest extends TestCase
{
    use RefreshDatabase;

    private FingerprintDevice $device;

    private SchoolClass $class;

    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->class = SchoolClass::create([
            'name' => 'X-RPL 1',
            'academic_year' => '2026/2027',
        ]);

        $this->device = FingerprintDevice::create([
            'name' => 'X601 Gerbang',
            'ip' => '192.168.1.212',
            'port' => 4370,
            'comm_key' => '0',
            'timezone' => 'Asia/Jakarta',
            'active' => true,
        ]);

        $this->student = Student::create([
            'nis' => '8801',
            'name' => 'Siswa Retry',
            'gender' => 'L',
            'class_id' => $this->class->id,
            'active' => true,
        ]);

        DeviceUser::create([
            'device_id' => $this->device->id,
            'machine_user_id' => $this->student->nis,
            'student_id' => $this->student->id,
        ]);

        SchoolSchedule::create([
            'day_pattern' => 'regular',
            'day_name' => 'Rabu',
            'day_of_week' => 3,
            'check_in_start' => '06:00:00',
            'start_at' => '07:00:00',
            'late_after' => '07:15:00',
            'check_out_start' => '14:00:00',
            'end_at' => '16:00:00',
            'is_active' => true,
        ]);

        config([
            'attendance.log_processing_max_attempts' => 2,
            'attendance.log_processing_backoff_minutes' => [1, 5],
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_processing_is_retried_then_marked_failed_with_audit(): void
    {
        $log = $this->createLog('2026-10-14 07:05:00');

        $this->mock(AttendanceProcessingService::class, function ($mock): void {
            $mock->shouldReceive('processLog')->andThrow(new RuntimeException('Simulasi gagal proses.'));
        });

        $processor = app(AttendanceLogProcessingService::class);

        $this->assertNull($processor->processLog($log));
        $log->refresh();
        $this->assertSame('unprocessed', $log->status);
        $this->assertSame(1, $log->processing_attempts);
        $this->assertNotNull($log->next_retry_at);

        $this->assertNull($processor->processLog($log));
        $log->refresh();
        $this->assertSame('failed', $log->status);
        $this->assertSame(2, $log->processing_attempts);
        $this->assertNull($log->next_retry_at);
        $this->assertStringContainsString('Simulasi gagal proses', $log->processing_note);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'attendance_log_processing_failed',
            'entity_type' => 'AttendanceLog',
            'entity_id' => $log->id,
        ]);
    }

    public function test_retry_command_processes_due_logs_only(): void
    {
        Carbon::setTestNow('2026-10-14 08:00:00');

        $dueLog = $this->createLog('2026-10-14 07:05:00');
        $futureLog = $this->createLog('2026-10-14 07:06:00');
        $futureLog->update(['next_retry_at' => now()->addHour()]);

        $this->artisan('attendance:retry-logs')->assertSuccessful();

        $this->assertSame('processed', $dueLog->fresh()->status);
        $this->assertSame('unprocessed', $futureLog->fresh()->status);
    }

    public function test_admin_can_retry_failed_log_from_raw_log_page(): void
    {
        Carbon::setTestNow('2026-10-14 08:00:00');

        $log = $this->createLog('2026-10-14 07:05:00', 'failed');
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get(route('logs.raw'))
            ->assertOk()
            ->assertSee('Gagal Permanen')
            ->assertSee('Proses Ulang');

        $this->actingAs($admin)
            ->post(route('logs.raw.retry', $log))
            ->assertSessionHas('success');

        $this->assertSame('processed', $log->fresh()->status);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'retry_attendance_log',
            'entity_type' => 'AttendanceLog',
            'entity_id' => $log->id,
        ]);
    }

    public function test_scan_after_manual_override_preserves_status_and_skips_notification(): void
    {
        Carbon::setTestNow('2026-10-14 08:00:00');
        Queue::fake();

        Guardian::create([
            'student_id' => $this->student->id,
            'name' => 'Wali Retry',
            'relationship' => 'Ayah',
            'wa_number' => '081234567890',
            'wa_status' => 'valid',
            'wa_validated_at' => now(),
            'consent_at' => now(),
            'active' => true,
        ]);

        $attendance = Attendance::create([
            'student_id' => $this->student->id,
            'school_date' => '2026-10-14',
            'status' => 'izin',
            'is_manual_override' => true,
            'override_reason' => 'Surat izin resmi.',
            'operator_id' => User::factory()->operator()->create()->id,
            'policy_version' => Attendance::POLICY_VERSION_BASE,
        ]);

        $log = $this->createLog('2026-10-14 07:05:00');

        $processed = app(AttendanceLogProcessingService::class)
            ->processDueForDevice($this->device);

        $this->assertSame(1, $processed['processed']);

        $attendance->refresh();
        $log->refresh();

        $this->assertSame('izin', $attendance->status);
        $this->assertTrue($attendance->is_manual_override);
        $this->assertSame('2026-10-14 07:05:00', $attendance->check_in_at->format('Y-m-d H:i:s'));
        $this->assertSame('processed', $log->status);
        $this->assertSame('Scan disimpan; koreksi manual dipertahankan.', $log->processing_note);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'scan_after_manual_override',
            'entity_id' => $attendance->id,
        ]);
        $this->assertDatabaseCount('notification_logs', 0);
        Queue::assertNotPushed(SendWhatsAppNotificationJob::class);
    }

    public function test_failed_log_blocks_auto_alpa_until_resolved(): void
    {
        Carbon::setTestNow('2026-10-15 08:00:00');

        $attendance = Attendance::create([
            'student_id' => $this->student->id,
            'school_date' => '2026-10-14',
            'status' => 'perlu_tinjauan',
            'policy_version' => Attendance::POLICY_VERSION_PENDING,
            'is_manual_override' => false,
        ]);

        $this->createLog('2026-10-14 07:05:00', 'failed');

        $this->artisan('attendance:sync-pending')->assertSuccessful();

        $attendance->refresh();

        $this->assertSame('perlu_tinjauan', $attendance->status);
        $this->assertSame(Attendance::POLICY_VERSION_PENDING, $attendance->policy_version);
        $this->assertNull($attendance->override_reason);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'auto_alpa_pending_attendance',
            'entity_id' => $attendance->id,
        ]);
    }

    private function createLog(string $occurredAt, string $status = 'unprocessed'): AttendanceLog
    {
        return AttendanceLog::create([
            'device_id' => $this->device->id,
            'machine_user_id' => $this->student->nis,
            'occurred_at' => Carbon::parse($occurredAt, 'Asia/Jakarta'),
            'received_at' => now(),
            'source_key' => 'retry-'.$this->student->nis.'-'.$occurredAt,
            'status' => $status,
        ]);
    }
}
