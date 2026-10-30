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
use App\Services\AttendanceProcessingService;
use App\Services\FingerprintIngestService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AttendanceTimeReviewTest extends TestCase
{
    use RefreshDatabase;

    private FingerprintDevice $device;

    private SchoolClass $class;

    protected function setUp(): void
    {
        parent::setUp();

        $this->class = SchoolClass::create([
            'name' => 'X-RPL 1',
            'academic_year' => '2026/2027',
        ]);

        $this->device = FingerprintDevice::create([
            'name' => 'X601 Gerbang',
            'ip' => '192.168.1.210',
            'port' => 4370,
            'comm_key' => '0',
            'timezone' => 'Asia/Jakarta',
            'active' => true,
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
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_prior_date_scan_is_held_for_time_review(): void
    {
        Carbon::setTestNow('2026-10-29 09:00:00');
        $student = $this->createStudent('7201');
        $log = $this->createLog($student->nis, '2026-10-28 07:05:00', '2026-10-29 08:00:00');

        $attendance = app(AttendanceProcessingService::class)->processLog($log);

        $this->assertNull($attendance);
        $this->assertSame(AttendanceLog::STATUS_NEEDS_REVIEW, $log->fresh()->status);
        $this->assertStringContainsString('diterima server', $log->fresh()->processing_note);
        $this->assertDatabaseCount('attendances', 0);
    }

    public function test_same_day_scan_over_delay_limit_is_held_for_time_review(): void
    {
        Carbon::setTestNow('2026-10-28 09:00:00');
        $student = $this->createStudent('7202');
        $log = $this->createLog($student->nis, '2026-10-28 07:05:00', '2026-10-28 08:06:00');

        $attendance = app(AttendanceProcessingService::class)->processLog($log);

        $this->assertNull($attendance);
        $this->assertSame(AttendanceLog::STATUS_NEEDS_REVIEW, $log->fresh()->status);
        $this->assertStringContainsString('melebihi batas 60 menit', $log->fresh()->processing_note);
    }

    public function test_future_scan_outside_tolerance_is_held_for_time_review(): void
    {
        Carbon::setTestNow('2026-10-28 09:00:00');
        $student = $this->createStudent('7203');
        $log = $this->createLog($student->nis, '2026-10-28 07:06:00', '2026-10-28 07:00:00');

        $attendance = app(AttendanceProcessingService::class)->processLog($log);

        $this->assertNull($attendance);
        $this->assertSame(AttendanceLog::STATUS_NEEDS_REVIEW, $log->fresh()->status);
        $this->assertStringContainsString('masa depan', $log->fresh()->processing_note);
    }

    public function test_scan_within_time_tolerance_is_processed_normally(): void
    {
        Carbon::setTestNow('2026-10-28 07:10:00');
        $student = $this->createStudent('7204');
        $log = $this->createLog($student->nis, '2026-10-28 07:05:00', '2026-10-28 07:10:00');

        $attendance = app(AttendanceProcessingService::class)->processLog($log);

        $this->assertNotNull($attendance);
        $this->assertSame(AttendanceLog::STATUS_PROCESSED, $log->fresh()->status);
        $this->assertSame('hadir', $attendance->status);
    }

    public function test_admin_can_approve_held_historical_scan_and_attendance_is_applied(): void
    {
        Carbon::setTestNow('2026-10-29 09:00:00');
        $student = $this->createStudent('7205');
        $log = $this->createLog($student->nis, '2026-10-28 07:05:00', '2026-10-29 08:00:00');
        app(AttendanceProcessingService::class)->processLog($log);

        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('logs.raw.time-review.approve', $log), [
                'review_note' => 'Scan terbukti dari mesin, terlambat diunggah.',
            ])
            ->assertRedirect();

        $this->assertSame(AttendanceLog::STATUS_PROCESSED, $log->fresh()->status);
        $this->assertDatabaseHas('attendances', [
            'student_id' => $student->id,
            'school_date' => '2026-10-28 00:00:00',
            'status' => 'hadir',
        ]);
        $this->assertDatabaseCount('notification_logs', 0);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'approve_attendance_log_time_review',
            'entity_id' => $log->id,
        ]);
    }

    public function test_same_day_delayed_scan_approval_does_not_queue_whatsapp(): void
    {
        Queue::fake();

        Carbon::setTestNow('2026-10-28 09:00:00');
        $student = $this->createStudent('7209');
        Guardian::create([
            'student_id' => $student->id,
            'name' => 'Wali Siswa',
            'relationship' => 'Ayah',
            'wa_number' => '6281234567890',
            'wa_status' => 'valid',
            'wa_validated_at' => now(),
            'consent_at' => now(),
            'active' => true,
        ]);
        $log = $this->createLog($student->nis, '2026-10-28 07:05:00', '2026-10-28 08:06:00');
        app(AttendanceProcessingService::class)->processLog($log);

        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('logs.raw.time-review.approve', $log), [
                'review_note' => 'Keterlambatan unggah sudah dikonfirmasi.',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('attendances', [
            'student_id' => $student->id,
            'school_date' => '2026-10-28 00:00:00',
            'status' => 'hadir',
        ]);
        $this->assertDatabaseCount('notification_logs', 0);
        Queue::assertNotPushed(SendWhatsAppNotificationJob::class);
    }

    public function test_historical_approval_uses_schedule_for_scan_date(): void
    {
        Carbon::setTestNow('2026-10-29 09:00:00');
        SchoolSchedule::create([
            'day_pattern' => 'regular',
            'day_name' => 'Kamis',
            'day_of_week' => 4,
            'check_in_start' => '08:00:00',
            'start_at' => '09:00:00',
            'late_after' => '09:30:00',
            'check_out_start' => '15:00:00',
            'end_at' => '17:00:00',
            'is_active' => true,
        ]);

        $student = $this->createStudent('7210');
        $log = $this->createLog($student->nis, '2026-10-28 07:16:00', '2026-10-29 08:00:00');
        app(AttendanceProcessingService::class)->processLog($log);

        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('logs.raw.time-review.approve', $log), [
                'review_note' => 'Scan Rabu terlambat diunggah pada Kamis.',
            ])
            ->assertRedirect();

        $attendance = Attendance::where('student_id', $student->id)
            ->whereDate('school_date', '2026-10-28')
            ->firstOrFail();

        $this->assertSame('terlambat', $attendance->status);
        $this->assertSame('2026-10-28 07:16:00', $attendance->check_in_at->format('Y-m-d H:i:s'));
        $this->assertSame(16, $attendance->late_minutes);
        $this->assertDatabaseCount('notification_logs', 0);
    }

    public function test_historical_approval_preserves_manual_override(): void
    {
        Carbon::setTestNow('2026-10-29 09:00:00');
        $student = $this->createStudent('7211');
        $attendance = Attendance::create([
            'student_id' => $student->id,
            'school_date' => '2026-10-28',
            'status' => 'sakit',
            'is_manual_override' => true,
            'operator_id' => User::factory()->operator()->create()->id,
            'override_reason' => 'Surat sakit dari orang tua.',
            'policy_version' => 'v1.0',
        ]);
        $log = $this->createLog($student->nis, '2026-10-28 07:05:00', '2026-10-29 08:00:00');
        app(AttendanceProcessingService::class)->processLog($log);

        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('logs.raw.time-review.approve', $log), [
                'review_note' => 'Scan masuk terkonfirmasi.',
            ])
            ->assertRedirect();

        $attendance->refresh();

        $this->assertSame('sakit', $attendance->status);
        $this->assertTrue($attendance->is_manual_override);
        $this->assertSame('Surat sakit dari orang tua.', $attendance->override_reason);
        $this->assertSame('2026-10-28 07:05:00', $attendance->check_in_at->format('Y-m-d H:i:s'));
        $this->assertSame(AttendanceLog::STATUS_PROCESSED, $log->fresh()->status);
        $this->assertDatabaseCount('notification_logs', 0);
    }

    public function test_admin_rejection_marks_log_time_rejected_without_attendance(): void
    {
        Carbon::setTestNow('2026-10-29 09:00:00');
        $student = $this->createStudent('7206');
        $log = $this->createLog($student->nis, '2026-10-28 07:05:00', '2026-10-29 08:00:00');
        app(AttendanceProcessingService::class)->processLog($log);

        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('logs.raw.time-review.reject', $log), [
                'review_note' => 'Waktu scan tidak dapat diverifikasi.',
            ])
            ->assertRedirect();

        $this->assertSame(AttendanceLog::STATUS_TIME_REJECTED, $log->fresh()->status);
        $this->assertDatabaseCount('attendances', 0);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'reject_attendance_log_time_review',
            'entity_id' => $log->id,
        ]);
    }

    public function test_operator_cannot_approve_time_review(): void
    {
        Carbon::setTestNow('2026-10-29 09:00:00');
        $student = $this->createStudent('7207');
        $log = $this->createLog($student->nis, '2026-10-28 07:05:00', '2026-10-29 08:00:00');
        app(AttendanceProcessingService::class)->processLog($log);

        $operator = User::factory()->operator()->create();

        $this->actingAs($operator)
            ->post(route('logs.raw.time-review.approve', $log), [
                'review_note' => 'Mencoba menyetujui.',
            ])
            ->assertForbidden();
    }

    public function test_needs_review_scan_blocks_auto_alpa_until_rejected(): void
    {
        Carbon::setTestNow('2026-10-29 09:00:00');
        $student = $this->createStudent('7208');
        $log = $this->createLog($student->nis, '2026-10-28 07:05:00', '2026-10-29 08:00:00');
        app(AttendanceProcessingService::class)->processLog($log);

        $attendance = Attendance::create([
            'student_id' => $student->id,
            'school_date' => '2026-10-28',
            'check_in_status' => null,
            'check_out_status' => null,
            'late_minutes' => 0,
            'early_leave_minutes' => 0,
            'status' => 'perlu_tinjauan',
            'policy_version' => Attendance::POLICY_VERSION_PENDING,
            'is_manual_override' => false,
        ]);

        $this->artisan('attendance:sync-pending')->assertExitCode(0);
        $this->assertSame('perlu_tinjauan', $attendance->fresh()->status);

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)
            ->post(route('logs.raw.time-review.reject', $log), [
                'review_note' => 'Scan tidak dapat diverifikasi.',
            ])
            ->assertRedirect();

        $this->artisan('attendance:sync-pending')->assertExitCode(0);
        $this->assertSame('alpa', $attendance->fresh()->status);
    }

    private function createStudent(string $nis): Student
    {
        $student = Student::create([
            'nis' => $nis,
            'name' => 'Siswa '.$nis,
            'gender' => 'L',
            'class_id' => $this->class->id,
            'active' => true,
        ]);

        DeviceUser::create([
            'device_id' => $this->device->id,
            'machine_user_id' => $nis,
            'student_id' => $student->id,
        ]);

        return $student;
    }

    private function createLog(string $nis, string $occurredAt, string $receivedAt): AttendanceLog
    {
        $scan = Carbon::parse($occurredAt, 'Asia/Jakarta');

        return AttendanceLog::create([
            'device_id' => $this->device->id,
            'machine_user_id' => $nis,
            'occurred_at' => $scan,
            'received_at' => Carbon::parse($receivedAt, 'Asia/Jakarta'),
            'source_key' => FingerprintIngestService::canonicalSourceKey($nis, $scan),
            'status' => AttendanceLog::STATUS_UNPROCESSED,
        ]);
    }
}
