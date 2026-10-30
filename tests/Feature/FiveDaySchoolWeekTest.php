<?php

namespace Tests\Feature;

use App\Models\SchoolClass;
use App\Models\SchoolSchedule;
use App\Models\Student;
use App\Models\User;
use App\Services\AttendanceReportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FiveDaySchoolWeekTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private SchoolClass $class;

    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();

        $this->class = SchoolClass::create([
            'name' => 'X-RPL 1',
            'academic_year' => '2026/2027',
        ]);

        $this->student = Student::create([
            'nis' => '10001',
            'name' => 'Budi Santoso',
            'class_id' => $this->class->id,
            'active' => true,
        ]);

        // Setup jadwal Senin s.d. Sabtu (1 s.d. 6)
        $days = [
            1 => ['Senin', 'normal'],
            2 => ['Selasa', 'normal'],
            3 => ['Rabu', 'normal'],
            4 => ['Kamis', 'normal'],
            5 => ['Jumat', 'jumat'],
            6 => ['Sabtu', 'sabtu'],
        ];

        foreach ($days as $num => [$name, $pattern]) {
            SchoolSchedule::create([
                'day_of_week' => $num,
                'day_name' => $name,
                'day_pattern' => $pattern,
                'check_in_start' => '06:00:00',
                'start_at' => '07:00:00',
                'late_after' => '07:15:00',
                'check_out_start' => '12:00:00',
                'end_at' => '15:00:00',
                'is_active' => true,
            ]);
        }
    }

    public function test_admin_can_deactivate_saturday_schedule(): void
    {
        $saturday = SchoolSchedule::where('day_of_week', 6)->firstOrFail();

        // 1. Simulasikan browser saat checkbox unchecked (tidak mengirim field is_active sama sekali)
        $response = $this->actingAs($this->admin)->put(route('schedules.update', $saturday), [
            'check_in_start' => '06:00',
            'start_at' => '07:00',
            'late_after' => '07:15',
            'check_out_start' => '12:00',
            'end_at' => '15:00',
            'day_pattern' => 'sabtu',
        ]);

        $response->assertRedirect(route('schedules.index'));
        $this->assertFalse($saturday->fresh()->is_active);

        // 2. Aktifkan kembali
        $this->actingAs($this->admin)->put(route('schedules.update', $saturday), [
            'check_in_start' => '06:00',
            'start_at' => '07:00',
            'late_after' => '07:15',
            'check_out_start' => '12:00',
            'end_at' => '15:00',
            'day_pattern' => 'sabtu',
            'is_active' => '1',
        ]);
        $this->assertTrue($saturday->fresh()->is_active);

        // 3. Simulasikan kirim is_active = 0 via hidden input
        $this->actingAs($this->admin)->put(route('schedules.update', $saturday), [
            'check_in_start' => '06:00',
            'start_at' => '07:00',
            'late_after' => '07:15',
            'check_out_start' => '12:00',
            'end_at' => '15:00',
            'day_pattern' => 'sabtu',
            'is_active' => '0',
        ]);
        $this->assertFalse($saturday->fresh()->is_active);
    }

    public function test_schedule_times_must_be_in_chronological_order(): void
    {
        $schedule = SchoolSchedule::where('day_of_week', 3)->firstOrFail();

        $this->actingAs($this->admin)->put(route('schedules.update', $schedule), [
            'check_in_start' => '07:00',
            'start_at' => '06:00',
            'late_after' => '07:15',
            'check_out_start' => '12:00',
            'end_at' => '15:00',
            'day_pattern' => 'normal',
            'is_active' => '1',
        ])->assertSessionHasErrors('check_in_start');

        $this->assertSame('06:00:00', $schedule->fresh()->check_in_start);
        $this->assertSame('07:00:00', $schedule->fresh()->start_at);
    }

    public function test_schedule_times_allow_equal_boundaries(): void
    {
        $schedule = SchoolSchedule::where('day_of_week', 3)->firstOrFail();

        $this->actingAs($this->admin)->put(route('schedules.update', $schedule), [
            'check_in_start' => '06:00',
            'start_at' => '06:00',
            'late_after' => '06:00',
            'check_out_start' => '15:00',
            'end_at' => '15:00',
            'day_pattern' => 'normal',
            'is_active' => '1',
        ])->assertSessionHasNoErrors();

        $schedule->refresh();

        $this->assertStringStartsWith('06:00', $schedule->check_in_start);
        $this->assertStringStartsWith('15:00', $schedule->end_at);
    }

    public function test_effective_days_in_month_excludes_saturday_when_deactivated(): void
    {
        $service = app(AttendanceReportService::class);
        $start = Carbon::parse('2026-10-01', 'Asia/Jakarta');
        $end = Carbon::parse('2026-10-31', 'Asia/Jakarta');

        // Pada Oktober 2026:
        // Total hari: 31
        // Minggu: 4 (4, 11, 18, 25)
        // Sabtu: 5 (3, 10, 17, 24, 31)
        // Hari kerja Senin-Jumat: 31 - 4 - 5 = 22 hari
        // Hari kerja Senin-Sabtu: 31 - 4 = 27 hari

        // 1. Kondisi 6 hari kerja (Sabtu aktif)
        $sixDayResult = $service->getEffectiveDaysInMonth($start, $end);
        $this->assertEquals(27, $sixDayResult['effective_days']);

        // 2. Nonaktifkan Sabtu (Sekolah 5 hari kerja)
        SchoolSchedule::where('day_of_week', 6)->update(['is_active' => false]);

        $fiveDayResult = $service->getEffectiveDaysInMonth($start, $end);
        $this->assertEquals(22, $fiveDayResult['effective_days']);
    }

    public function test_effective_days_in_month_uses_inclusive_date_range(): void
    {
        $service = app(AttendanceReportService::class);
        $start = Carbon::parse('2026-10-05', 'Asia/Jakarta');
        $end = Carbon::parse('2026-10-15', 'Asia/Jakarta');

        $result = $service->getEffectiveDaysInMonth($start, $end);

        // 5-15 Oktober 2026: 11 hari, hanya Minggu 11 Oktober yang libur.
        $this->assertEquals(10, $result['effective_days']);
    }

    public function test_student_monthly_report_keeps_inactive_saturday_out_of_alpa_with_blank_note(): void
    {
        // Nonaktifkan Sabtu
        SchoolSchedule::where('day_of_week', 6)->update(['is_active' => false]);

        $response = $this->actingAs($this->admin)->get(route('attendances.report.student', [
            'student_id' => $this->student->id,
            'month' => '2026-10',
        ]));

        $response->assertOk();
        $response->assertViewHas('dailyRows', function ($rows) {
            // Cek tanggal 2026-10-03 (Sabtu pertama di Oktober 2026)
            $saturdayRow = collect($rows)->firstWhere('day_number', 3);

            return $saturdayRow !== null
                && $saturdayRow['status'] === 'libur'
                && $saturdayRow['is_holiday'] === true
                && $saturdayRow['note'] === '-';
        });

        // Pastikan total hari efektif dan libur dihitung benar
        $response->assertViewHas('summary', function ($summary) {
            // Karena tidak ada scan, total_effective adalah hari Senin-Jumat (22 hari)
            // dan libur adalah 4 Minggu + 5 Sabtu = 9 hari
            return $summary['libur'] === 9 && $summary['total_effective'] === 22;
        });
    }

    public function test_dashboard_does_not_count_students_as_belum_hadir_on_inactive_saturday(): void
    {
        // Nonaktifkan Sabtu
        SchoolSchedule::where('day_of_week', 6)->update(['is_active' => false]);

        // Kunjungi dashboard pada hari Sabtu 2026-10-03 tanpa ada log presensi
        $response = $this->actingAs($this->admin)->get(route('dashboard', [
            'date' => '2026-10-03',
        ]));

        $response->assertOk();
        $response->assertViewHas('isNonEffectiveDay', true);
        $response->assertViewHas('isSchoolDay', false);
        // Karena hari libur dan belum ada yang scan, belumHadirCount harus 0 (bukan total seluruh siswa)
        $response->assertViewHas('belumHadirCount', 0);
    }

    public function test_alpa_cutoff_must_be_after_schedule_end_time(): void
    {
        $schedule = SchoolSchedule::where('day_of_week', 1)->firstOrFail();

        $this->actingAs($this->admin)->put(route('schedules.update', $schedule), [
            'check_in_start' => '06:00',
            'start_at' => '07:00',
            'late_after' => '07:15',
            'check_out_start' => '12:00',
            'end_at' => '15:00',
            'alpa_cutoff' => '14:59',
            'day_pattern' => 'normal',
            'is_active' => '1',
        ])->assertSessionHasErrors('alpa_cutoff');

        $this->assertNull($schedule->fresh()->alpa_cutoff);

        $this->actingAs($this->admin)->put(route('schedules.update', $schedule), [
            'check_in_start' => '06:00',
            'start_at' => '07:00',
            'late_after' => '07:15',
            'check_out_start' => '12:00',
            'end_at' => '15:00',
            'alpa_cutoff' => '15:05',
            'day_pattern' => 'normal',
            'is_active' => '1',
        ])->assertSessionHasNoErrors();

        $this->assertStringStartsWith('15:05', $schedule->fresh()->alpa_cutoff);
    }
}
