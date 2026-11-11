<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AttendanceLog;
use App\Models\DeviceUser;
use App\Models\FingerprintDevice;
use App\Models\SchedulePeriod;
use App\Models\SchoolClass;
use App\Models\SchoolSchedule;
use App\Models\Student;
use App\Models\User;
use App\Services\AttendanceAlpaService;
use App\Services\AttendanceProcessingService;
use App\Services\FingerprintIngestService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class SchedulePeriodTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_resolver_uses_period_schedule_within_date_range(): void
    {
        $this->createRegularSchedule(3, '07:00:00', '07:15:00');
        $period = $this->createPeriod('2026-03-01', '2026-03-31');
        $this->createPeriodSchedule($period, 3, '08:00:00', '08:15:00');

        $inside = SchoolSchedule::forDate(Carbon::parse('2026-03-11 09:00:00', 'Asia/Jakarta'));
        $outside = SchoolSchedule::forDate(Carbon::parse('2026-04-01 09:00:00', 'Asia/Jakarta'));

        $this->assertNotNull($inside);
        $this->assertSame($period->id, $inside->period_id);
        $this->assertSame('08:00:00', $inside->start_at);

        $this->assertNotNull($outside);
        $this->assertNull($outside->period_id);
        $this->assertSame('07:00:00', $outside->start_at);
    }

    public function test_inactive_period_does_not_override_regular_schedule(): void
    {
        $this->createRegularSchedule(3, '07:00:00', '07:15:00');
        $period = $this->createPeriod('2026-03-01', '2026-03-31', false);
        $this->createPeriodSchedule($period, 3, '08:00:00', '08:15:00');

        $schedule = SchoolSchedule::forDate(Carbon::parse('2026-03-11 09:00:00', 'Asia/Jakarta'));

        $this->assertNotNull($schedule);
        $this->assertNull($schedule->period_id);
        $this->assertSame('07:00:00', $schedule->start_at);
    }

    public function test_date_covered_by_period_without_active_row_is_treated_as_off_day(): void
    {
        $this->createRegularSchedule(3, '07:00:00', '07:15:00');
        $period = $this->createPeriod('2026-03-01', '2026-03-31');
        $this->createPeriodSchedule($period, 3, '08:00:00', '08:15:00', false);

        $date = Carbon::parse('2026-03-11 09:00:00', 'Asia/Jakarta');

        $this->assertNull(SchoolSchedule::forDate($date));
        $this->assertFalse(SchoolSchedule::isSchoolDay($date));
    }

    public function test_scan_on_period_date_uses_special_schedule_times(): void
    {
        Carbon::setTestNow('2026-03-11 09:00:00');

        $this->createRegularSchedule(3, '07:00:00', '07:15:00');
        $period = $this->createPeriod('2026-03-01', '2026-03-31');
        $this->createPeriodSchedule($period, 3, '08:00:00', '08:15:00', true, '12:00:00');

        $device = FingerprintDevice::create([
            'name' => 'X601 Gerbang',
            'ip' => '192.168.1.230',
            'port' => 4370,
            'comm_key' => '0',
            'timezone' => 'Asia/Jakarta',
            'active' => true,
        ]);

        $class = SchoolClass::create([
            'name' => 'XII-IPA 1',
            'academic_year' => '2026/2027',
        ]);

        $student = Student::create([
            'nis' => '7401',
            'name' => 'Siswa Jadwal Khusus',
            'gender' => 'L',
            'class_id' => $class->id,
            'active' => true,
        ]);

        DeviceUser::create([
            'device_id' => $device->id,
            'machine_user_id' => '7401',
            'student_id' => $student->id,
        ]);

        $scan = Carbon::parse('2026-03-11 07:30:00', 'Asia/Jakarta');

        $log = AttendanceLog::create([
            'device_id' => $device->id,
            'machine_user_id' => '7401',
            'occurred_at' => $scan,
            'received_at' => Carbon::parse('2026-03-11 07:31:00', 'Asia/Jakarta'),
            'source_key' => FingerprintIngestService::canonicalSourceKey('7401', $scan),
            'status' => AttendanceLog::STATUS_UNPROCESSED,
        ]);

        $attendance = app(AttendanceProcessingService::class)->processLog($log);

        $this->assertNotNull($attendance);
        $this->assertSame('hadir', $attendance->status);
        $this->assertSame('tepat_waktu', $attendance->check_in_status);
        $this->assertSame(0, $attendance->late_minutes);
    }

    public function test_alpa_cutoff_uses_period_schedule_end_time(): void
    {
        $this->createRegularSchedule(3, '07:00:00', '07:15:00');

        $date = Carbon::parse('2026-03-11', 'Asia/Jakarta');
        $now = Carbon::parse('2026-03-11 13:00:00', 'Asia/Jakarta');
        $service = app(AttendanceAlpaService::class);

        $this->assertArrayNotHasKey(
            '2026-03-11',
            $service->getAlpaDateKeys($date, $date->copy(), $now)
        );

        $period = $this->createPeriod('2026-03-01', '2026-03-31');
        $this->createPeriodSchedule($period, 3, '08:00:00', '08:15:00', true, '12:00:00');

        $this->assertArrayHasKey(
            '2026-03-11',
            $service->getAlpaDateKeys($date, $date->copy(), $now)
        );
    }

    public function test_creating_period_clones_regular_schedule_without_touching_existing_attendance(): void
    {
        $admin = User::factory()->admin()->create();
        $this->createRegularSchedule(3, '07:00:00', '07:15:00');

        $class = SchoolClass::create([
            'name' => 'XII-IPS 1',
            'academic_year' => '2026/2027',
        ]);

        $student = Student::create([
            'nis' => '7402',
            'name' => 'Siswa Presensi Lama',
            'gender' => 'P',
            'class_id' => $class->id,
            'active' => true,
        ]);

        $attendance = Attendance::create([
            'student_id' => $student->id,
            'school_date' => '2026-03-11',
            'status' => 'terlambat',
            'check_in_at' => '2026-03-11 07:20:00',
            'check_in_status' => 'terlambat',
            'late_minutes' => 20,
            'policy_version' => 'v1.0',
        ]);

        $this->actingAs($admin)
            ->post(route('schedules.periods.store'), [
                'name' => 'Jadwal Ramadan 1448 H',
                'start_date' => '2026-03-01',
                'end_date' => '2026-03-31',
                'is_active' => '1',
            ])
            ->assertRedirect();

        $period = SchedulePeriod::firstOrFail();
        $cloned = $period->schedules()->firstOrFail();

        $this->assertSame('07:00:00', $cloned->start_at);
        $this->assertSame(3, $cloned->day_of_week);
        $this->assertNull($cloned->effective_date);

        $attendance->refresh();

        $this->assertSame('terlambat', $attendance->status);
        $this->assertSame('2026-03-11 07:20:00', $attendance->check_in_at->format('Y-m-d H:i:s'));
        $this->assertSame(20, $attendance->late_minutes);
        $this->assertFalse($attendance->is_manual_override);
    }

    public function test_overlapping_active_period_is_rejected(): void
    {
        $admin = User::factory()->admin()->create();
        $this->createRegularSchedule(3, '07:00:00', '07:15:00');
        $this->createPeriod('2026-03-01', '2026-03-31');

        $this->actingAs($admin)
            ->post(route('schedules.periods.store'), [
                'name' => 'Periode Bertumpuk',
                'start_date' => '2026-03-15',
                'end_date' => '2026-04-15',
                'is_active' => '1',
            ])
            ->assertSessionHasErrors('start_date');

        $this->assertSame(1, SchedulePeriod::count());
    }

    public function test_resolved_schedule_is_readable_from_serializing_cache_store(): void
    {
        // Meniru produksi: store yang men-serialize nilai (database/file) dan
        // cache.serializable_classes = false. Objek model yang disimpan ke
        // cache akan kembali sebagai __PHP_Incomplete_Class.
        config([
            'cache.default' => 'file',
            'cache.stores.file.path' => storage_path('framework/testing/cache'),
            'cache.serializable_classes' => false,
        ]);
        app('cache')->purge('file');
        Cache::store('file')->flush();

        $this->createRegularSchedule(3, '07:00:00', '07:15:00');

        $date = Carbon::parse('2026-03-11 09:00:00', 'Asia/Jakarta');

        // Panggilan pertama menulis cache, panggilan kedua membacanya kembali.
        $first = SchoolSchedule::resolveForDate($date);
        $second = SchoolSchedule::resolveForDate($date);

        $this->assertInstanceOf(SchoolSchedule::class, $first);
        $this->assertInstanceOf(SchoolSchedule::class, $second);
        $this->assertSame($first->id, $second->id);
        $this->assertSame('07:00:00', $second->start_at);
    }

    public function test_absent_schedule_is_cached_without_breaking_resolution(): void
    {
        config([
            'cache.default' => 'file',
            'cache.stores.file.path' => storage_path('framework/testing/cache'),
            'cache.serializable_classes' => false,
        ]);
        app('cache')->purge('file');
        Cache::store('file')->flush();

        $this->createRegularSchedule(3, '07:00:00', '07:15:00');

        // 2026-03-15 adalah hari Minggu: tidak ada jadwal.
        $date = Carbon::parse('2026-03-15 09:00:00', 'Asia/Jakarta');

        $this->assertNull(SchoolSchedule::resolveForDate($date));
        $this->assertNull(SchoolSchedule::resolveForDate($date));
        $this->assertNotNull(SchoolSchedule::resolveForDate(Carbon::parse('2026-03-11 09:00:00', 'Asia/Jakarta')));
    }

    private function createRegularSchedule(
        int $dayOfWeek,
        string $startAt,
        string $lateAfter,
        string $checkOutStart = '14:00:00',
        string $endAt = '16:00:00',
    ): SchoolSchedule {
        return SchoolSchedule::create([
            'day_pattern' => 'regular',
            'day_name' => $this->dayName($dayOfWeek),
            'day_of_week' => $dayOfWeek,
            'check_in_start' => '06:00:00',
            'start_at' => $startAt,
            'late_after' => $lateAfter,
            'check_out_start' => $checkOutStart,
            'end_at' => $endAt,
            'is_active' => true,
        ]);
    }

    private function createPeriod(string $startDate, string $endDate, bool $isActive = true): SchedulePeriod
    {
        return SchedulePeriod::create([
            'name' => 'Periode Uji',
            'start_date' => $startDate,
            'end_date' => $endDate,
            'is_active' => $isActive,
        ]);
    }

    private function createPeriodSchedule(
        SchedulePeriod $period,
        int $dayOfWeek,
        string $startAt,
        string $lateAfter,
        bool $isActive = true,
        string $endAt = '16:00:00',
    ): SchoolSchedule {
        return SchoolSchedule::create([
            'period_id' => $period->id,
            'day_pattern' => 'khusus',
            'day_name' => $this->dayName($dayOfWeek),
            'day_of_week' => $dayOfWeek,
            'check_in_start' => '06:00:00',
            'start_at' => $startAt,
            'late_after' => $lateAfter,
            'check_out_start' => $endAt,
            'end_at' => $endAt,
            'is_active' => $isActive,
        ]);
    }

    private function dayName(int $dayOfWeek): string
    {
        return [
            1 => 'Senin',
            2 => 'Selasa',
            3 => 'Rabu',
            4 => 'Kamis',
            5 => 'Jumat',
            6 => 'Sabtu',
            7 => 'Minggu',
        ][$dayOfWeek];
    }
}
