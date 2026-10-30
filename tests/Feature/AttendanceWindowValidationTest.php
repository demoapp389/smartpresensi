<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AttendanceLog;
use App\Models\DeviceUser;
use App\Models\FingerprintDevice;
use App\Models\SchoolClass;
use App\Models\SchoolSchedule;
use App\Models\Student;
use App\Services\AttendanceProcessingService;
use App\Services\AttendanceReportService;
use App\Services\FingerprintIngestService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceWindowValidationTest extends TestCase
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
            'name' => 'X601 Gerbang',
            'ip' => '192.168.1.212',
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

    public function test_scan_before_check_in_start_is_rejected_without_creating_attendance(): void
    {
        $student = $this->createStudent('7101');
        $log = $this->createLog($student->nis, '2026-10-14 05:59:59');

        $attendance = app(AttendanceProcessingService::class)->processLog($log);

        $this->assertNull($attendance);
        $this->assertSame('rejected', $log->fresh()->status);
        $this->assertStringContainsString('jendela masuk', $log->fresh()->processing_note);
        $this->assertDatabaseCount('attendances', 0);
    }

    public function test_scan_at_check_in_start_is_accepted(): void
    {
        $student = $this->createStudent('7111');
        $log = $this->createLog($student->nis, '2026-10-14 06:00:00');

        $attendance = app(AttendanceProcessingService::class)->processLog($log);

        $this->assertNotNull($attendance);
        $this->assertSame('processed', $log->fresh()->status);
        $this->assertSame('2026-10-14 06:00:00', $attendance->check_in_at->format('Y-m-d H:i:s'));
        $this->assertSame('hadir', $attendance->status);
        $this->assertSame('tepat_waktu', $attendance->check_in_status);
        $this->assertSame(0, $attendance->late_minutes);
    }

    public function test_scan_at_late_after_boundary_is_on_time(): void
    {
        $student = $this->createStudent('7112');
        $log = $this->createLog($student->nis, '2026-10-14 07:15:00');

        $attendance = app(AttendanceProcessingService::class)->processLog($log);

        $this->assertNotNull($attendance);
        $this->assertSame('hadir', $attendance->status);
        $this->assertSame('tepat_waktu', $attendance->check_in_status);
        $this->assertSame(15, $attendance->late_minutes);
    }

    public function test_scan_at_check_out_start_boundary_is_accepted_as_checkout(): void
    {
        $student = $this->createStudent('7113');
        $attendance = Attendance::create([
            'student_id' => $student->id,
            'school_date' => '2026-10-14',
            'check_in_at' => '2026-10-14 07:05:00',
            'check_in_status' => 'tepat_waktu',
            'check_out_status' => 'belum_absen',
            'status' => 'hadir',
            'policy_version' => 'v1.0',
        ]);
        $log = $this->createLog($student->nis, '2026-10-14 14:00:00');

        $result = app(AttendanceProcessingService::class)->processLog($log);

        $this->assertNotNull($result);
        $this->assertSame('processed', $log->fresh()->status);

        $attendance->refresh();
        $this->assertSame('2026-10-14 14:00:00', $attendance->check_out_at->format('Y-m-d H:i:s'));
        $this->assertSame('pulang_cepat', $attendance->check_out_status);
        $this->assertSame(120, $attendance->early_leave_minutes);
    }

    public function test_scan_at_end_at_is_accepted_as_checkout(): void
    {
        $student = $this->createStudent('7102');
        $attendance = Attendance::create([
            'student_id' => $student->id,
            'school_date' => '2026-10-14',
            'check_in_at' => '2026-10-14 07:05:00',
            'check_in_status' => 'tepat_waktu',
            'check_out_status' => 'belum_absen',
            'status' => 'hadir',
            'policy_version' => 'v1.0',
        ]);
        $log = $this->createLog($student->nis, '2026-10-14 16:00:00');

        $result = app(AttendanceProcessingService::class)->processLog($log);

        $this->assertNotNull($result);
        $this->assertSame('processed', $log->fresh()->status);

        $attendance->refresh();
        $this->assertSame('2026-10-14 16:00:00', $attendance->check_out_at->format('Y-m-d H:i:s'));
        $this->assertSame('hadir', $attendance->status);
        $this->assertSame('tepat_waktu', $attendance->check_out_status);
    }

    public function test_scan_after_end_at_is_rejected_without_changing_attendance(): void
    {
        $student = $this->createStudent('7110');
        $attendance = Attendance::create([
            'student_id' => $student->id,
            'school_date' => '2026-10-14',
            'check_in_at' => '2026-10-14 07:05:00',
            'check_in_status' => 'tepat_waktu',
            'check_out_status' => 'belum_absen',
            'status' => 'hadir',
            'policy_version' => 'v1.0',
        ]);
        $log = $this->createLog($student->nis, '2026-10-14 16:00:01');

        $result = app(AttendanceProcessingService::class)->processLog($log);

        $this->assertNull($result);
        $this->assertSame('rejected', $log->fresh()->status);
        $this->assertStringContainsString('jam pulang', $log->fresh()->processing_note);

        $attendance->refresh();
        $this->assertNull($attendance->check_out_at);
        $this->assertSame('hadir', $attendance->status);
        $this->assertSame('belum_absen', $attendance->check_out_status);
    }

    public function test_check_in_only_records_presence_and_minutes_from_schedule_start(): void
    {
        $onTime = $this->createStudent('7103');
        $late = $this->createStudent('7104');
        $processing = app(AttendanceProcessingService::class);

        $processing->processLog($this->createLog($onTime->nis, '2026-10-14 07:05:00'));
        $processing->processLog($this->createLog($late->nis, '2026-10-14 07:16:00'));

        $onTimeAttendance = $this->attendanceFor($onTime);

        $this->assertSame('hadir', $onTimeAttendance->status);
        $this->assertSame('tepat_waktu', $onTimeAttendance->check_in_status);
        $this->assertSame('belum_absen', $onTimeAttendance->check_out_status);
        $this->assertSame(5, $onTimeAttendance->late_minutes);
        $this->assertSame('Scan Pulang Belum Ada', $onTimeAttendance->scan_completeness_label);

        $lateAttendance = $this->attendanceFor($late);

        $this->assertSame('terlambat', $lateAttendance->status);
        $this->assertSame('terlambat', $lateAttendance->check_in_status);
        $this->assertSame(16, $lateAttendance->late_minutes);
    }

    public function test_checkout_only_scan_is_pending_review_and_does_not_trigger_notification(): void
    {
        $student = $this->createStudent('7105');
        $log = $this->createLog($student->nis, '2026-10-14 14:05:00');
        $processing = app(AttendanceProcessingService::class);

        $attendance = $processing->processLog($log);

        $this->assertNotNull($attendance);
        $this->assertNull($attendance->check_in_at);
        $this->assertSame('2026-10-14 14:05:00', $attendance->check_out_at->format('Y-m-d H:i:s'));
        $this->assertSame('perlu_tinjauan', $attendance->status);
        $this->assertSame('tidak_absen', $attendance->check_in_status);
        $this->assertSame('perlu_verifikasi', $attendance->check_out_status);
        $this->assertSame(115, $attendance->early_leave_minutes);
        $this->assertSame('processed', $log->fresh()->status);
        $this->assertNull($processing->notificationEventType($attendance));
    }

    public function test_checkout_only_scan_is_not_counted_as_present_or_alpa_in_daily_report(): void
    {
        $student = $this->createStudent('7107');
        $processing = app(AttendanceProcessingService::class);

        $processing->processLog($this->createLog($student->nis, '2026-10-14 14:05:00'));

        $report = app(AttendanceReportService::class)->getDailyReport([
            'date' => '2026-10-14',
            'class_id' => $this->class->id,
        ]);

        $this->assertSame(0, $report['summary']['hadir']);
        $this->assertSame(0, $report['summary']['terlambat']);
        $this->assertSame(0, $report['summary']['alpa']);
        $this->assertSame(0, $report['summary']['virtual_alpa']);
        $this->assertSame(1, $report['summary']['perlu_tinjauan']);
        $this->assertSame(
            'perlu_tinjauan',
            collect($report['rows'])->firstWhere('student_id', $student->id)['status']
        );
    }

    public function test_check_in_only_uses_check_in_status_for_notification_type(): void
    {
        $onTime = $this->createStudent('7108');
        $late = $this->createStudent('7109');
        $processing = app(AttendanceProcessingService::class);

        $onTimeAttendance = $processing->processLog($this->createLog($onTime->nis, '2026-10-14 07:05:00'));
        $lateAttendance = $processing->processLog($this->createLog($late->nis, '2026-10-14 07:16:00'));

        $this->assertSame('hadir', $onTimeAttendance->status);
        $this->assertSame('check_in', $processing->notificationEventType($onTimeAttendance));
        $this->assertSame('terlambat', $lateAttendance->status);
        $this->assertSame('late', $processing->notificationEventType($lateAttendance));
    }

    public function test_checkout_records_early_leave_without_overwriting_main_status(): void
    {
        $student = $this->createStudent('7106');
        $processing = app(AttendanceProcessingService::class);

        $processing->processLog($this->createLog($student->nis, '2026-10-14 07:16:00'));
        $checkOutLog = $this->createLog($student->nis, '2026-10-14 15:05:00');
        $processing->processLog($checkOutLog);

        $attendance = $this->attendanceFor($student);

        $this->assertSame('terlambat', $attendance->status);
        $this->assertSame('terlambat', $attendance->check_in_status);
        $this->assertSame('pulang_cepat', $attendance->check_out_status);
        $this->assertSame(16, $attendance->late_minutes);
        $this->assertSame(55, $attendance->early_leave_minutes);
        $this->assertSame('processed', $checkOutLog->fresh()->status);
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

    private function createLog(string $nis, string $occurredAt): AttendanceLog
    {
        $scan = Carbon::parse($occurredAt, 'Asia/Jakarta');

        return AttendanceLog::create([
            'device_id' => $this->device->id,
            'machine_user_id' => $nis,
            'occurred_at' => $scan,
            'received_at' => $scan->copy()->addMinute(),
            'source_key' => FingerprintIngestService::canonicalSourceKey($nis, $scan),
            'status' => 'unprocessed',
        ]);
    }

    private function attendanceFor(Student $student): Attendance
    {
        return Attendance::where('student_id', $student->id)
            ->whereDate('school_date', '2026-10-14')
            ->firstOrFail();
    }
}
