<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use App\Models\AttendanceLog;
use App\Models\AuditLog;
use App\Models\SchedulePeriod;
use App\Models\SchoolHoliday;
use App\Models\SchoolSchedule;
use App\Models\Student;
use App\Services\AttendancePeriodService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SyncPendingAttendanceCommand extends Command
{
    private const AUTO_ALPA_REASON = 'Auto-Alpa H+1: tidak ada scan masuk dan pulang serta belum ditinjau.';

    protected $signature = 'attendance:sync-pending
                            {--date= : Tanggal sekolah yang dimaterialisasi (Y-m-d)}
                            {--dry-run : Tampilkan jumlah data yang akan diubah tanpa menyimpan}';

    protected $description = 'Materialisasi presensi perlu tinjauan dan finalisasi Alpa H+1 untuk presensi tanpa scan';

    public function handle(AttendancePeriodService $periodService): int
    {
        $timezone = config('app.timezone', 'Asia/Jakarta');
        $today = now($timezone)->startOfDay();
        $dryRun = (bool) $this->option('dry-run');
        $dateOption = $this->option('date');

        if (is_string($dateOption) && $dateOption !== '') {
            $targetDate = Carbon::parse($dateOption, $timezone)->startOfDay();
            $materialized = $this->materializePending($targetDate, true, $dryRun, $periodService);
            $finalized = 0;
        } else {
            $materialized = $this->materializePending($today, false, $dryRun, $periodService);
            $finalized = $this->finalizeExpiredPending($today, $dryRun, $periodService);
        }

        $this->newLine();
        $this->table(
            ['Perubahan', 'Jumlah'],
            [
                ['Baris perlu tinjauan dibuat', $materialized],
                ['Baris tanpa scan menjadi Alpa', $finalized],
                ['Total', $materialized + $finalized],
            ]
        );

        if ($dryRun) {
            $this->info('Dry-run selesai. Tidak ada data yang diubah.');
        } else {
            $this->info('Sinkronisasi presensi pending selesai.');
        }

        return self::SUCCESS;
    }

    private function materializePending(Carbon $date, bool $force, bool $dryRun, AttendancePeriodService $periodService): int
    {
        if ($periodService->isBeforeStart($date)) {
            return 0;
        }

        $schedule = $this->scheduleFor($date);

        if ($schedule === null || $this->isHoliday($date)) {
            return 0;
        }

        if (! $force && now($date->getTimezone())->lt($schedule->alpaCutoffForDate($date))) {
            return 0;
        }

        $studentIds = Student::query()
            ->where('active', true)
            ->orderBy('id')
            ->pluck('id');

        $existingStudentIds = Attendance::query()
            ->whereDate('school_date', $date->toDateString())
            ->pluck('student_id')
            ->map(fn (int|string $id): int => (int) $id)
            ->all();

        $missingStudentIds = $studentIds
            ->reject(fn (int|string $id): bool => in_array((int) $id, $existingStudentIds, true))
            ->values();

        if ($dryRun) {
            return $missingStudentIds->count();
        }

        if ($missingStudentIds->isEmpty()) {
            return 0;
        }

        $now = now();
        $rows = $missingStudentIds
            ->map(fn (int|string $studentId): array => [
                'student_id' => (int) $studentId,
                'school_date' => $date->toDateString(),
                'check_in_status' => null,
                'check_out_status' => null,
                'late_minutes' => 0,
                'early_leave_minutes' => 0,
                'status' => 'perlu_tinjauan',
                'policy_version' => Attendance::POLICY_VERSION_PENDING,
                'is_manual_override' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->all();

        try {
            return Attendance::insertOrIgnore($rows);
        } catch (QueryException) {
            return 0;
        }
    }

    private function finalizeExpiredPending(Carbon $today, bool $dryRun, AttendancePeriodService $periodService): int
    {
        $timezone = config('app.timezone', 'Asia/Jakarta');
        /** @var Collection<int, SchoolSchedule> $schedules */
        $schedules = SchoolSchedule::query()->get();
        /** @var Collection<int, SchedulePeriod> $periods */
        $periods = SchedulePeriod::query()->where('is_active', true)->get();
        $holidays = SchoolHoliday::active()
            ->get(['start_date', 'end_date'])
            ->map(fn (object $holiday): array => [
                Carbon::parse($holiday->start_date, $timezone)->startOfDay(),
                Carbon::parse($holiday->end_date, $timezone)->endOfDay(),
            ]);

        $finalized = 0;
        $firstAttendanceId = null;
        $lastAttendanceId = null;

        $periodService->applyTo(Attendance::query(), 'school_date')
            ->where('status', 'perlu_tinjauan')
            ->whereNull('check_in_at')
            ->whereNull('check_out_at')
            ->where('is_manual_override', false)
            ->whereDate('school_date', '<', $today->toDateString())
            ->orderBy('id')
            ->chunkById(200, function (Collection $attendances) use ($today, $dryRun, $schedules, $periods, $holidays, $timezone, &$finalized, &$firstAttendanceId, &$lastAttendanceId): void {
                foreach ($attendances as $attendance) {
                    if ($dryRun) {
                        if ($this->isReadyForAutoAlpa($attendance, $schedules, $periods, $holidays, $timezone)) {
                            $finalized++;
                        }

                        continue;
                    }

                    $finalized += DB::transaction(function () use ($attendance, $today, $schedules, $periods, $holidays, $timezone, &$firstAttendanceId, &$lastAttendanceId): int {
                        $locked = Attendance::query()
                            ->whereKey($attendance->id)
                            ->where('status', 'perlu_tinjauan')
                            ->whereNull('check_in_at')
                            ->whereNull('check_out_at')
                            ->where('is_manual_override', false)
                            ->whereDate('school_date', '<', $today->toDateString())
                            ->lockForUpdate()
                            ->first();

                        if (! $locked || ! $this->isReadyForAutoAlpa($locked, $schedules, $periods, $holidays, $timezone)) {
                            return 0;
                        }

                        $locked->update([
                            'status' => 'alpa',
                            'check_in_status' => null,
                            'check_out_status' => null,
                            'late_minutes' => 0,
                            'early_leave_minutes' => 0,
                            'override_reason' => self::AUTO_ALPA_REASON,
                            'operator_id' => null,
                            'policy_version' => Attendance::POLICY_VERSION_AUTO_ALPA,
                        ]);

                        $firstAttendanceId ??= $locked->id;
                        $lastAttendanceId = $locked->id;

                        return 1;
                    });
                }
            });

        // Satu baris audit per eksekusi, bukan per siswa. Jejak per siswa sudah
        // melekat pada baris attendances (policy_version + override_reason),
        // sehingga ribuan baris audit harian tidak lagi diperlukan.
        if (! $dryRun && $finalized > 0) {
            AuditLog::create([
                'actor_id' => null,
                'action' => 'auto_alpa_batch',
                'entity_type' => 'Attendance',
                'entity_id' => $firstAttendanceId,
                'before' => null,
                'after' => [
                    'count' => $finalized,
                    'before_school_date' => $today->toDateString(),
                    'first_attendance_id' => $firstAttendanceId,
                    'last_attendance_id' => $lastAttendanceId,
                ],
                'ip_address' => null,
                'created_at' => now(),
            ]);
        }

        return $finalized;
    }

    /**
     * @param  Collection<int, SchoolSchedule>  $schedules
     * @param  Collection<int, SchedulePeriod>  $periods
     * @param  Collection<int, array{0: Carbon, 1: Carbon}>  $holidays
     */
    private function isReadyForAutoAlpa(
        Attendance $attendance,
        Collection $schedules,
        Collection $periods,
        Collection $holidays,
        string $timezone,
    ): bool {
        $schoolDate = Carbon::parse(
            $attendance->school_date->toDateString(),
            $timezone
        );

        $schedule = SchoolSchedule::resolveFrom($schoolDate, $schedules, $periods);

        if ($schedule === null || ! $schedule->is_active) {
            return false;
        }

        $isHoliday = $holidays->contains(
            fn (array $range): bool => $schoolDate->betweenIncluded($range[0], $range[1])
        );

        if ($isHoliday) {
            return false;
        }

        return ! $this->hasUnresolvedScan($attendance);
    }

    private function hasUnresolvedScan(Attendance $attendance): bool
    {
        $schoolDate = $attendance->school_date->toDateString();
        $nis = Student::withTrashed()
            ->whereKey($attendance->student_id)
            ->value('nis');

        return AttendanceLog::query()
            ->whereIn('status', AttendanceLog::AUTO_ALPA_BLOCKING_STATUSES)
            ->whereDate('occurred_at', $schoolDate)
            ->where(function ($query) use ($attendance, $nis): void {
                $query->whereExists(function ($mapped) use ($attendance): void {
                    $mapped->selectRaw('1')
                        ->from('device_users')
                        ->whereColumn('device_users.device_id', 'attendance_logs.device_id')
                        ->whereColumn('device_users.machine_user_id', 'attendance_logs.machine_user_id')
                        ->where('device_users.student_id', $attendance->student_id);
                });

                // PIN yang belum dipetakan tidak punya device_user, dan log yang
                // mesinnya sudah dihapus memiliki device_id NULL sehingga join di
                // atas tidak pernah cocok. Jika PIN sama dengan NIS, tetap anggap
                // sebagai bukti scan siswa tersebut untuk SEMUA status blocking
                // agar auto-alpa tidak berjalan saat masih ada scan menggantung.
                if (is_string($nis) && $nis !== '') {
                    $query->orWhere('machine_user_id', $nis);
                }
            })
            ->exists();
    }

    private function scheduleFor(Carbon $date): ?SchoolSchedule
    {
        return SchoolSchedule::forDate($date);
    }

    private function isHoliday(Carbon $date): bool
    {
        return SchoolHoliday::isHoliday($date);
    }
}
