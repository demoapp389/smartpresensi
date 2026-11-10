<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AttendanceLog;
use App\Models\AttendanceSetting;
use App\Models\DeviceUser;
use App\Models\FingerprintDevice;
use App\Models\SchoolClass;
use App\Models\SchoolSchedule;
use App\Models\Student;
use App\Models\User;
use App\Services\AttendanceAlpaService;
use App\Services\AttendancePeriodService;
use App\Services\AttendanceProcessingService;
use App\Services\AttendanceReportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceStartDateTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_admin_can_set_and_clear_attendance_start_date(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->put(route('schedules.attendance-start.update'), [
                'attendance_start_date' => '2026-10-26',
            ])
            ->assertRedirect(route('schedules.index', ['tab' => 'mulai']));

        $this->assertDatabaseHas('attendance_settings', [
            'attendance_start_date' => '2026-10-26 00:00:00',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'update_attendance_start_date',
        ]);

        $this->actingAs($admin)
            ->put(route('schedules.attendance-start.update'), [
                'attendance_start_date' => '',
            ])
            ->assertRedirect(route('schedules.index', ['tab' => 'mulai']));

        $this->assertDatabaseHas('attendance_settings', [
            'attendance_start_date' => null,
        ]);
    }

    public function test_non_admin_cannot_update_attendance_start_date(): void
    {
        $operator = User::factory()->operator()->create();

        $this->actingAs($operator)
            ->put(route('schedules.attendance-start.update'), [
                'attendance_start_date' => '2026-10-26',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('attendance_settings', 0);
    }

    public function test_alpa_service_never_marks_dates_before_start(): void
    {
        $this->setStartDate('2026-10-26');
        $this->createSchedule(1);

        $keys = app(AttendanceAlpaService::class)->getAlpaDateKeys(
            Carbon::parse('2026-10-19', 'Asia/Jakarta'),
            Carbon::parse('2026-10-27', 'Asia/Jakarta'),
            Carbon::parse('2026-10-27 10:00:00', 'Asia/Jakarta'),
        );

        $this->assertArrayNotHasKey('2026-10-19', $keys);
        $this->assertArrayHasKey('2026-10-26', $keys);
    }

    public function test_sync_pending_skips_materialization_and_auto_alpa_before_start(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-27 00:30:00', 'Asia/Jakarta'));
        $this->setStartDate('2026-10-26');

        $student = $this->createStudent('9001', 'Siswa Sebelum Mulai');
        $attendance = Attendance::create([
            'student_id' => $student->id,
            'school_date' => '2026-10-23',
            'status' => 'perlu_tinjauan',
            'policy_version' => Attendance::POLICY_VERSION_PENDING,
        ]);

        $this->artisan('attendance:sync-pending')->assertSuccessful();

        $this->assertSame('perlu_tinjauan', $attendance->fresh()->status);
        $this->assertDatabaseCount('attendances', 1);

        $this->artisan('attendance:sync-pending', ['--date' => '2026-10-23'])->assertSuccessful();

        $this->assertDatabaseCount('attendances', 1);
    }

    public function test_scan_before_start_is_rejected_without_creating_attendance(): void
    {
        $this->setStartDate('2026-10-26');

        $device = FingerprintDevice::create([
            'name' => 'X601 Gerbang',
            'ip' => '192.168.1.240',
            'port' => 4370,
            'comm_key' => '0',
            'timezone' => 'Asia/Jakarta',
            'active' => true,
        ]);
        $class = SchoolClass::create([
            'name' => 'XII-RPL 1',
            'academic_year' => '2026/2027',
        ]);
        $student = Student::create([
            'nis' => '9002',
            'name' => 'Siswa Scan Lama',
            'gender' => 'L',
            'class_id' => $class->id,
            'active' => true,
        ]);
        DeviceUser::create([
            'device_id' => $device->id,
            'machine_user_id' => '9002',
            'student_id' => $student->id,
        ]);

        $log = AttendanceLog::create([
            'device_id' => $device->id,
            'machine_user_id' => '9002',
            'occurred_at' => '2026-10-20 07:00:00',
            'received_at' => '2026-10-20 07:01:00',
            'source_key' => 'scan-sebelum-mulai',
            'status' => AttendanceLog::STATUS_UNPROCESSED,
        ]);

        $attendance = app(AttendanceProcessingService::class)->processLog($log);

        $this->assertNull($attendance);
        $this->assertSame('rejected', $log->fresh()->status);
        $this->assertSame('Scan sebelum tanggal mulai sistem absensi', $log->fresh()->processing_note);
        $this->assertDatabaseCount('attendances', 0);
    }

    public function test_reports_hide_data_before_start(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-26 06:00:00', 'Asia/Jakarta'));
        $this->setStartDate('2026-10-26');
        $this->createSchedule(1);

        $class = SchoolClass::create([
            'name' => 'XII-RPL 2',
            'academic_year' => '2026/2027',
        ]);
        $student = Student::create([
            'nis' => '9003',
            'name' => 'Siswa Data Lama',
            'gender' => 'P',
            'class_id' => $class->id,
            'active' => true,
        ]);
        Attendance::create([
            'student_id' => $student->id,
            'school_date' => '2026-10-20',
            'status' => 'hadir',
            'policy_version' => 'v1.0',
        ]);

        $service = app(AttendanceReportService::class);

        $daily = $service->getDailyReport(['date' => '2026-10-20']);
        $this->assertSame(0, $daily['total_rows']);
        $this->assertSame(0, $daily['summary']['total_students']);
        $this->assertSame(0, $daily['summary']['hadir']);

        $monthly = $service->getMonthlyReport(['month' => '2026-10']);
        $this->assertSame(0, $monthly['summary']['hadir']);
        $this->assertSame(0, $monthly['summary']['alpa']);
        $this->assertSame(0, $monthly['summary']['virtual_alpa']);

        $studentReport = $service->getStudentMonthlyReport([
            'student_id' => $student->id,
            'month' => '2026-10',
        ]);
        $this->assertSame('-', $studentReport['dailyRows'][0]['status']);
        $this->assertTrue($studentReport['dailyRows'][0]['is_before_start']);
        $this->assertSame(0, $studentReport['summary']['hadir']);
    }

    public function test_dashboard_and_raw_logs_hide_pre_start_data(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-26 06:00:00', 'Asia/Jakarta'));
        $this->setStartDate('2026-10-26');

        $admin = User::factory()->admin()->create();
        $class = SchoolClass::create([
            'name' => 'XII-RPL 3',
            'academic_year' => '2026/2027',
        ]);
        $student = Student::create([
            'nis' => '9004',
            'name' => 'Siswa Dashboard Lama',
            'gender' => 'L',
            'class_id' => $class->id,
            'active' => true,
        ]);
        Attendance::create([
            'student_id' => $student->id,
            'school_date' => '2026-10-20',
            'status' => 'hadir',
            'policy_version' => 'v1.0',
        ]);

        $device = FingerprintDevice::create([
            'name' => 'X601 Log Lama',
            'ip' => '192.168.1.241',
            'port' => 4370,
            'comm_key' => '0',
            'timezone' => 'Asia/Jakarta',
            'active' => true,
        ]);
        AttendanceLog::create([
            'device_id' => $device->id,
            'machine_user_id' => '9004',
            'occurred_at' => '2026-10-20 07:00:00',
            'received_at' => '2026-10-20 07:01:00',
            'source_key' => 'dashboard-log-lama',
            'status' => AttendanceLog::STATUS_PROCESSED,
        ]);

        $this->actingAs($admin)
            ->get(route('dashboard', ['date' => '2026-10-20']))
            ->assertOk()
            ->assertViewHas('isBeforeStart', true)
            ->assertViewHas('attendances', fn ($attendances): bool => $attendances->isEmpty())
            ->assertViewHas('recentLogs', fn ($logs): bool => $logs->isEmpty());

        $this->actingAs($admin)
            ->get(route('logs.raw', ['date' => '2026-10-20']))
            ->assertOk()
            ->assertViewHas('logs', fn ($logs): bool => $logs->isEmpty());
    }

    public function test_manual_input_and_correction_are_blocked_before_start(): void
    {
        $this->setStartDate('2026-10-26');
        $operator = User::factory()->operator()->create();

        $class = SchoolClass::create([
            'name' => 'XII-RPL 4',
            'academic_year' => '2026/2027',
        ]);
        $student = Student::create([
            'nis' => '9005',
            'name' => 'Siswa Koreksi Lama',
            'gender' => 'P',
            'class_id' => $class->id,
            'active' => true,
        ]);
        $attendance = Attendance::create([
            'student_id' => $student->id,
            'school_date' => '2026-10-20',
            'status' => 'perlu_tinjauan',
            'policy_version' => Attendance::POLICY_VERSION_PENDING,
        ]);

        $this->actingAs($operator)
            ->post(route('attendances.manual'), [
                'student_id' => $student->id,
                'school_date' => '2026-10-20',
                'status' => 'izin',
                'override_reason' => 'Surat izin lama.',
            ])
            ->assertSessionHasErrors('school_date');

        $this->assertDatabaseCount('attendances', 1);

        $this->actingAs($operator)
            ->post(route('attendances.correct', $attendance), [
                'status' => 'izin',
                'override_reason' => 'Koreksi lama.',
            ])
            ->assertSessionHasErrors('status');

        $this->assertSame('perlu_tinjauan', $attendance->fresh()->status);
    }

    public function test_records_before_start_are_kept_and_visible_after_clearing(): void
    {
        $this->setStartDate('2026-10-26');
        $admin = User::factory()->admin()->create();

        $class = SchoolClass::create([
            'name' => 'XII-RPL 5',
            'academic_year' => '2026/2027',
        ]);
        $student = Student::create([
            'nis' => '9006',
            'name' => 'Siswa Tetap Tersimpan',
            'gender' => 'L',
            'class_id' => $class->id,
            'active' => true,
        ]);
        Attendance::create([
            'student_id' => $student->id,
            'school_date' => '2026-10-20',
            'status' => 'hadir',
            'policy_version' => 'v1.0',
        ]);

        $this->actingAs($admin)
            ->get(route('attendances.index', ['date' => '2026-10-20']))
            ->assertOk()
            ->assertViewHas('beforeStart', true)
            ->assertViewHas('attendances', fn ($attendances): bool => $attendances->isEmpty());

        AttendanceSetting::current()->update(['attendance_start_date' => null]);
        app(AttendancePeriodService::class)->forget();

        $this->actingAs($admin)
            ->get(route('attendances.index', ['date' => '2026-10-20']))
            ->assertOk()
            ->assertViewHas('beforeStart', false)
            ->assertViewHas('attendances', fn ($attendances): bool => $attendances->count() === 1);

        $this->assertDatabaseHas('attendances', [
            'student_id' => $student->id,
            'school_date' => '2026-10-20 00:00:00',
        ]);
    }

    private function setStartDate(string $date): void
    {
        AttendanceSetting::current()->update(['attendance_start_date' => $date]);
        app(AttendancePeriodService::class)->forget();
    }

    private function createSchedule(int $dayOfWeek): void
    {
        SchoolSchedule::create([
            'day_pattern' => 'regular',
            'day_name' => $dayOfWeek === 1 ? 'Senin' : 'Selasa',
            'day_of_week' => $dayOfWeek,
            'check_in_start' => '06:00:00',
            'start_at' => '07:00:00',
            'late_after' => '07:15:00',
            'check_out_start' => '14:00:00',
            'end_at' => '15:00:00',
            'is_active' => true,
        ]);
    }

    private function createStudent(string $nis, string $name): Student
    {
        $class = SchoolClass::firstOrCreate([
            'name' => 'XII-RPL Default',
            'academic_year' => '2026/2027',
        ]);

        return Student::create([
            'nis' => $nis,
            'name' => $name,
            'gender' => 'L',
            'class_id' => $class->id,
            'active' => true,
        ]);
    }
}
