<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\SchoolClass;
use App\Models\SchoolHoliday;
use App\Models\SchoolSchedule;
use App\Models\Student;
use App\Models\User;
use App\Services\AttendanceAlpaService;
use App\Services\AttendanceReportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceVirtualAlpaTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private SchoolClass $class;

    private Student $student1;

    private Student $student2;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-22 17:00:00', 'Asia/Jakarta'));

        $this->admin = User::factory()->admin()->create();
        $this->class = SchoolClass::create(['name' => 'X-RPL 1', 'academic_year' => '2026/2027']);

        $this->student1 = Student::create([
            'nis' => '10001',
            'name' => 'Ahmad Dahlan',
            'class_id' => $this->class->id,
            'active' => true,
        ]);

        $this->student2 = Student::create([
            'nis' => '10002',
            'name' => 'Budi Santoso',
            'class_id' => $this->class->id,
            'active' => true,
        ]);

        foreach ([1, 2, 3, 4, 5, 6] as $day) {
            SchoolSchedule::create([
                'day_of_week' => $day,
                'day_name' => 'Hari '.$day,
                'day_pattern' => 'regular',
                'check_in_start' => '06:00:00',
                'start_at' => '07:00:00',
                'late_after' => '07:15:00',
                'check_out_start' => '12:00:00',
                'end_at' => '15:00:00',
                'is_active' => true,
            ]);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_legacy_finalize_command_no_longer_writes_attendance_rows(): void
    {
        $this->artisan('attendance:finalize')
            ->expectsOutputToContain('dinonaktifkan')
            ->assertSuccessful();

        $this->assertDatabaseCount('attendances', 0);
    }

    public function test_daily_report_marks_missing_students_as_virtual_alpa_after_cutoff(): void
    {
        Attendance::create([
            'student_id' => $this->student1->id,
            'school_date' => '2026-10-21',
            'check_in_at' => '2026-10-21 06:55:00',
            'status' => 'hadir',
            'policy_version' => 'v1.0',
        ]);

        $report = app(AttendanceReportService::class)->getDailyReport([
            'date' => '2026-10-21',
            'class_id' => $this->class->id,
        ]);

        $this->assertSame(1, $report['summary']['hadir']);
        $this->assertSame(1, $report['summary']['alpa']);
        $this->assertSame(1, $report['summary']['virtual_alpa']);
        $this->assertSame(0, $report['summary']['belum_hadir']);

        $virtualRow = collect($report['rows'])->firstWhere('student_id', $this->student2->id);

        $this->assertNotNull($virtualRow);
        $this->assertSame('alpa', $virtualRow['status']);
        $this->assertTrue($virtualRow['is_virtual_alpa']);
        $this->assertNull($virtualRow['id']);
    }

    public function test_virtual_alpa_is_not_persisted_to_attendances_table(): void
    {
        Attendance::create([
            'student_id' => $this->student1->id,
            'school_date' => '2026-10-21',
            'check_in_at' => '2026-10-21 06:55:00',
            'status' => 'hadir',
            'policy_version' => 'v1.0',
        ]);

        $report = app(AttendanceReportService::class)->getDailyReport([
            'date' => '2026-10-21',
            'class_id' => $this->class->id,
        ]);

        $this->actingAs($this->admin)
            ->get(route('dashboard', ['date' => '2026-10-21']))
            ->assertOk();

        $this->assertSame(1, $report['summary']['virtual_alpa']);
        $this->assertDatabaseCount('attendances', 1);
        $this->assertSame(0, Attendance::where('status', 'alpa')->count());
        $this->assertSame(0, Attendance::where('student_id', $this->student2->id)->count());
    }

    public function test_daily_report_does_not_mark_alpa_before_cutoff_or_on_holiday(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-22 14:00:00', 'Asia/Jakarta'));

        $beforeCutoff = app(AttendanceReportService::class)->getDailyReport([
            'date' => '2026-10-22',
            'class_id' => $this->class->id,
        ]);

        $this->assertSame(0, $beforeCutoff['summary']['alpa']);
        $this->assertSame(0, $beforeCutoff['summary']['virtual_alpa']);
        $this->assertSame(2, $beforeCutoff['summary']['belum_hadir']);

        SchoolHoliday::create([
            'name' => 'Libur Sekolah',
            'start_date' => '2026-10-21',
            'end_date' => '2026-10-21',
            'is_active' => true,
        ]);

        $holiday = app(AttendanceReportService::class)->getDailyReport([
            'date' => '2026-10-21',
            'class_id' => $this->class->id,
        ]);

        $this->assertSame(0, $holiday['summary']['alpa']);
        $this->assertSame(0, $holiday['summary']['virtual_alpa']);
        $this->assertSame(0, $holiday['summary']['belum_hadir']);
    }

    public function test_monthly_report_uses_virtual_alpa_and_excludes_pending_review(): void
    {
        Attendance::create([
            'student_id' => $this->student1->id,
            'school_date' => '2026-10-21',
            'check_in_at' => '2026-10-21 06:55:00',
            'status' => 'hadir',
            'policy_version' => 'v1.0',
        ]);

        Attendance::create([
            'student_id' => $this->student1->id,
            'school_date' => '2026-10-20',
            'check_out_at' => '2026-10-20 14:00:00',
            'status' => 'perlu_tinjauan',
            'check_in_status' => 'tidak_absen',
            'check_out_status' => 'perlu_verifikasi',
            'policy_version' => 'v1.0',
        ]);

        $report = app(AttendanceReportService::class)->getMonthlyReport([
            'month' => '2026-10',
            'class_id' => $this->class->id,
        ]);

        $row = collect($report['rows'])->firstWhere('student_name', $this->student1->name);

        $this->assertSame(27, $report['summary']['effective_days']);
        $this->assertSame(1, $row['hadir']);
        $this->assertSame(1, $row['perlu_tinjauan']);
        $this->assertSame(17, $row['virtual_alpa']);
        $this->assertSame(17, $row['alpa']);
        $this->assertSame(1, $row['total_hadir']);
    }

    public function test_student_monthly_report_marks_only_elapsed_effective_days_as_alpa(): void
    {
        $report = app(AttendanceReportService::class)->getStudentMonthlyReport([
            'student_id' => $this->student1->id,
            'month' => '2026-10',
        ]);

        $rowsByDate = collect($report['dailyRows'])->keyBy(fn (array $row): string => $row['date']->toDateString());

        $this->assertSame('alpa', $rowsByDate->get('2026-10-21')['status']);
        $this->assertTrue($rowsByDate->get('2026-10-21')['is_virtual_alpa']);
        $this->assertSame('alpa', $rowsByDate->get('2026-10-22')['status']);
        $this->assertSame('-', $rowsByDate->get('2026-10-23')['status']);
        $this->assertSame('libur', $rowsByDate->get('2026-10-18')['status']);
        $this->assertSame(19, $report['summary']['alpa']);
        $this->assertSame(19, $report['summary']['virtual_alpa']);
    }

    public function test_dashboard_uses_virtual_alpa_after_cutoff(): void
    {
        $response = $this->actingAs($this->admin)->get(route('dashboard', [
            'date' => '2026-10-22',
        ]));

        $response->assertOk();
        $response->assertViewHas('alpaCount', 2);
        $response->assertViewHas('virtualAlpaCount', 2);
        $response->assertViewHas('belumHadirCount', 0);
        $response->assertViewHas('attendanceRate', fn (float $rate): bool => $rate === 0.0);
    }

    public function test_dashboard_counts_recorded_students_as_present_in_kpi_and_trend(): void
    {
        Attendance::create([
            'student_id' => $this->student1->id,
            'school_date' => '2026-10-22',
            'check_in_at' => '2026-10-22 06:55:00',
            'status' => 'hadir',
            'policy_version' => 'v1.0',
        ]);

        $response = $this->actingAs($this->admin)->get(route('dashboard', [
            'date' => '2026-10-22',
        ]));

        $response->assertOk();
        $response->assertViewHas('alpaCount', 1);
        $response->assertViewHas('virtualAlpaCount', 1);
        $response->assertViewHas('belumHadirCount', 0);
        $response->assertViewHas('trendChartData', function (array $trend): bool {
            $lastIndex = count($trend['labels']) - 1;

            return $trend['hadir'][$lastIndex] === 1
                && $trend['alpa'][$lastIndex] === 1;
        });
    }

    public function test_dashboard_class_filter_uses_class_student_total(): void
    {
        $otherClass = SchoolClass::create(['name' => 'X-TKJ 1', 'academic_year' => '2026/2027']);

        Student::create([
            'nis' => '10003',
            'name' => 'Siti Aminah',
            'class_id' => $otherClass->id,
            'active' => true,
        ]);

        $response = $this->actingAs($this->admin)->get(route('dashboard', [
            'date' => '2026-10-22',
            'class_id' => $this->class->id,
        ]));

        $response->assertOk();
        $response->assertViewHas('totalStudents', 2);
        $response->assertViewHas('virtualAlpaCount', 2);
        $response->assertViewHas('alpaCount', 2);
        $response->assertViewHas('belumHadirCount', 0);
    }

    public function test_dashboard_keeps_missing_students_as_belum_hadir_before_cutoff(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-22 14:00:00', 'Asia/Jakarta'));

        $response = $this->actingAs($this->admin)->get(route('dashboard', [
            'date' => '2026-10-22',
        ]));

        $response->assertOk();
        $response->assertViewHas('alpaCount', 0);
        $response->assertViewHas('virtualAlpaCount', 0);
        $response->assertViewHas('belumHadirCount', 2);
    }

    public function test_alpa_service_respects_custom_cutoff(): void
    {
        $schedule = SchoolSchedule::where('day_of_week', 4)->firstOrFail();
        $schedule->update(['alpa_cutoff' => '17:00:00']);
        $service = app(AttendanceAlpaService::class);
        $date = Carbon::parse('2026-10-22', 'Asia/Jakarta');

        $this->assertFalse($service->isAlpaDue($date, Carbon::parse('2026-10-22 16:59:00', 'Asia/Jakarta')));
        $this->assertTrue($service->isAlpaDue($date, Carbon::parse('2026-10-22 17:00:00', 'Asia/Jakarta')));
    }
}
