<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\SchoolSchedule;
use App\Services\AttendanceStatusResolver;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AttendanceStatusResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_fields_and_processing_note_are_available(): void
    {
        $this->assertTrue(Schema::hasColumns('attendances', [
            'check_in_status',
            'check_out_status',
            'late_minutes',
            'early_leave_minutes',
        ]));
        $this->assertTrue(Schema::hasColumn('school_schedules', 'alpa_cutoff'));
        $this->assertTrue(Schema::hasColumn('attendance_logs', 'processing_note'));
    }

    public function test_late_minutes_are_measured_from_schedule_start(): void
    {
        $resolver = app(AttendanceStatusResolver::class);
        $schedule = $this->createSchedule();

        $onTime = Carbon::parse('2026-10-14 07:15:00', 'Asia/Jakarta');
        $late = Carbon::parse('2026-10-14 07:16:00', 'Asia/Jakarta');
        $halfHourLate = Carbon::parse('2026-10-14 07:30:00', 'Asia/Jakarta');

        $this->assertSame(15, $resolver->lateMinutes($onTime, $schedule));
        $this->assertSame(AttendanceStatusResolver::CHECK_IN_ON_TIME, $resolver->checkInStatus($onTime, $schedule));

        $this->assertSame(16, $resolver->lateMinutes($late, $schedule));
        $this->assertSame(AttendanceStatusResolver::CHECK_IN_LATE, $resolver->checkInStatus($late, $schedule));

        $this->assertSame(30, $resolver->lateMinutes($halfHourLate, $schedule));
    }

    public function test_early_leave_minutes_are_zero_at_or_after_schedule_end(): void
    {
        $resolver = app(AttendanceStatusResolver::class);
        $schedule = $this->createSchedule();

        $early = Carbon::parse('2026-10-14 15:05:00', 'Asia/Jakarta');
        $onTime = Carbon::parse('2026-10-14 16:00:00', 'Asia/Jakarta');

        $this->assertSame(55, $resolver->earlyLeaveMinutes($early, $schedule));
        $this->assertSame(AttendanceStatusResolver::CHECK_OUT_EARLY, $resolver->checkOutStatus($early, $schedule));

        $this->assertSame(0, $resolver->earlyLeaveMinutes($onTime, $schedule));
        $this->assertSame(AttendanceStatusResolver::CHECK_OUT_ON_TIME, $resolver->checkOutStatus($onTime, $schedule));
    }

    public function test_alpa_cutoff_uses_explicit_value_or_end_time_plus_buffer(): void
    {
        $resolver = app(AttendanceStatusResolver::class);
        $date = Carbon::parse('2026-10-14', 'Asia/Jakarta');

        $fallback = $this->createSchedule();
        $custom = $this->createSchedule(['day_of_week' => 4, 'alpa_cutoff' => '16:30:00']);

        $this->assertSame(
            '2026-10-14 16:05:00',
            $resolver->alpaCutoff($fallback, $date)->format('Y-m-d H:i:s')
        );
        $this->assertSame(
            '2026-10-14 16:30:00',
            $resolver->alpaCutoff($custom, $date)->format('Y-m-d H:i:s')
        );
    }

    public function test_attendance_status_labels_cover_new_statuses(): void
    {
        $attendance = new Attendance([
            'status' => 'perlu_tinjauan',
            'check_in_status' => 'tidak_absen',
            'check_out_status' => 'perlu_verifikasi',
        ]);

        $this->assertSame('Perlu Tinjauan', $attendance->status_label);
        $this->assertSame('Tidak Absen', $attendance->check_in_status_label);
        $this->assertSame('Perlu Verifikasi', $attendance->check_out_status_label);
    }

    public function test_attendance_status_source_and_scan_completeness_labels(): void
    {
        $automaticAlpa = new Attendance([
            'status' => 'alpa',
            'is_manual_override' => false,
            'operator_id' => null,
            'policy_version' => Attendance::POLICY_VERSION_AUTO_ALPA,
        ]);

        $manualAlpa = new Attendance([
            'status' => 'alpa',
            'is_manual_override' => true,
            'operator_id' => 7,
            'policy_version' => Attendance::POLICY_VERSION_SCAN_CORRECTION,
        ]);

        $checkInOnly = new Attendance([
            'status' => 'hadir',
            'check_in_at' => '2026-10-14 07:05:00',
            'check_out_at' => null,
        ]);

        $checkOutOnly = new Attendance([
            'status' => 'perlu_tinjauan',
            'check_in_at' => null,
            'check_out_at' => '2026-10-14 15:05:00',
        ]);

        $this->assertTrue($automaticAlpa->isAutomaticAlpa());
        $this->assertSame('Alpa Otomatis', $automaticAlpa->status_display_label);
        $this->assertTrue($manualAlpa->isManualAlpa());
        $this->assertSame('Alpa (Koreksi Manual)', $manualAlpa->status_display_label);
        $this->assertSame('Scan Pulang Belum Ada', $checkInOnly->scan_completeness_label);
        $this->assertSame('Scan Masuk Belum Ada', $checkOutOnly->scan_completeness_label);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createSchedule(array $attributes = []): SchoolSchedule
    {
        return SchoolSchedule::create(array_merge([
            'day_pattern' => 'regular',
            'day_name' => 'Rabu',
            'day_of_week' => 3,
            'check_in_start' => '06:00:00',
            'start_at' => '07:00:00',
            'late_after' => '07:15:00',
            'check_out_start' => '14:00:00',
            'end_at' => '16:00:00',
            'is_active' => true,
        ], $attributes));
    }
}
