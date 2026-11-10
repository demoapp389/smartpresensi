<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\DeviceUser;
use App\Models\FingerprintDevice;
use App\Models\SchoolClass;
use App\Models\SchoolSchedule;
use App\Models\Student;
use App\Models\User;
use App\Services\AttendanceProcessingService;
use App\Services\FingerprintIngestService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceTimeReviewBulkTest extends TestCase
{
    use RefreshDatabase;

    private FingerprintDevice $device;

    private SchoolClass $class;

    protected function setUp(): void
    {
        parent::setUp();

        $this->class = SchoolClass::create([
            'name' => 'X-RPL 2',
            'academic_year' => '2026/2027',
        ]);

        $this->device = FingerprintDevice::create([
            'name' => 'X601 Gerbang Utama',
            'ip' => '192.168.1.220',
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

    public function test_admin_can_bulk_approve_pending_time_reviews(): void
    {
        Carbon::setTestNow('2026-10-29 09:00:00');

        $first = $this->createHeldLog('7301', '2026-10-28 07:05:00');
        $second = $this->createHeldLog('7302', '2026-10-28 07:06:00');
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('logs.raw.time-review.bulk-approve'), [
                'ids' => [$first->id, $second->id],
                'review_note' => 'Unggahan terlambat sudah terverifikasi.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(AttendanceLog::STATUS_PROCESSED, $first->fresh()->status);
        $this->assertSame(AttendanceLog::STATUS_PROCESSED, $second->fresh()->status);
        $this->assertDatabaseCount('attendances', 2);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'bulk_approve_attendance_log_time_review',
            'entity_type' => 'AttendanceLog',
        ]);
    }

    public function test_admin_can_bulk_reject_pending_time_reviews(): void
    {
        Carbon::setTestNow('2026-10-29 09:00:00');

        $first = $this->createHeldLog('7303', '2026-10-28 07:05:00');
        $second = $this->createHeldLog('7304', '2026-10-28 07:06:00');
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('logs.raw.time-review.bulk-reject'), [
                'ids' => [$first->id, $second->id],
                'review_note' => 'Waktu scan tidak dapat diverifikasi.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(AttendanceLog::STATUS_TIME_REJECTED, $first->fresh()->status);
        $this->assertSame(AttendanceLog::STATUS_TIME_REJECTED, $second->fresh()->status);
        $this->assertDatabaseCount('attendances', 0);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'bulk_reject_attendance_log_time_review',
            'entity_type' => 'AttendanceLog',
        ]);
    }

    public function test_bulk_approval_skips_logs_whose_status_already_changed(): void
    {
        Carbon::setTestNow('2026-10-29 09:00:00');

        $alreadyApproved = $this->createHeldLog('7305', '2026-10-28 07:05:00');
        $pending = $this->createHeldLog('7306', '2026-10-28 07:06:00');
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('logs.raw.time-review.approve', $alreadyApproved), [
                'review_note' => 'Disetujui lebih dulu.',
            ])
            ->assertRedirect();

        $this->actingAs($admin)
            ->post(route('logs.raw.time-review.bulk-approve'), [
                'ids' => [$alreadyApproved->id, $pending->id],
                'review_note' => 'Persetujuan massal susulan.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success', fn (string $message): bool => str_contains($message, 'dilewati'));

        $this->assertSame(AttendanceLog::STATUS_PROCESSED, $alreadyApproved->fresh()->status);
        $this->assertSame(AttendanceLog::STATUS_PROCESSED, $pending->fresh()->status);
        $this->assertDatabaseCount('attendances', 2);
    }

    public function test_bulk_time_review_requires_at_least_one_selected_scan(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('logs.raw.time-review.bulk-approve'), [
                'ids' => [],
                'review_note' => 'Tanpa pilihan.',
            ])
            ->assertSessionHasErrors('ids');
    }

    public function test_bulk_time_review_rejects_more_than_two_hundred_scans(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('logs.raw.time-review.bulk-reject'), [
                'ids' => range(1, 201),
                'review_note' => 'Terlalu banyak.',
            ])
            ->assertSessionHasErrors('ids');
    }

    public function test_bulk_time_review_requires_review_note(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('logs.raw.time-review.bulk-approve'), [
                'ids' => [1, 2],
            ])
            ->assertSessionHasErrors('review_note');
    }

    public function test_operator_cannot_bulk_approve_time_reviews(): void
    {
        Carbon::setTestNow('2026-10-29 09:00:00');

        $log = $this->createHeldLog('7307', '2026-10-28 07:05:00');
        $operator = User::factory()->operator()->create();

        $this->actingAs($operator)
            ->post(route('logs.raw.time-review.bulk-approve'), [
                'ids' => [$log->id],
                'review_note' => 'Mencoba menyetujui.',
            ])
            ->assertForbidden();

        $this->assertSame(AttendanceLog::STATUS_NEEDS_REVIEW, $log->fresh()->status);
    }

    private function createHeldLog(string $nis, string $occurredAt): AttendanceLog
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

        $scan = Carbon::parse($occurredAt, 'Asia/Jakarta');

        $log = AttendanceLog::create([
            'device_id' => $this->device->id,
            'machine_user_id' => $nis,
            'occurred_at' => $scan,
            'received_at' => Carbon::parse('2026-10-29 08:00:00', 'Asia/Jakarta'),
            'source_key' => FingerprintIngestService::canonicalSourceKey($nis, $scan),
            'status' => AttendanceLog::STATUS_UNPROCESSED,
        ]);

        app(AttendanceProcessingService::class)->processLog($log);

        $this->assertSame(AttendanceLog::STATUS_NEEDS_REVIEW, $log->fresh()->status);

        return $log;
    }
}
