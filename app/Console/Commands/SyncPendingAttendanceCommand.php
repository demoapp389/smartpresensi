<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use App\Models\AttendanceLog;
use App\Models\AuditLog;
use App\Models\SchoolHoliday;
use App\Models\SchoolSchedule;
use App\Models\Student;
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

    public function handle(): int
    {
        $timezone = config('app.timezone', 'Asia/Jakarta');
        $today = now($timezone)->startOfDay();
        $dryRun = (bool) $this->option('dry-run');
        $dateOption = $this->option('date');

        if (is_string($dateOption) && $dateOption !== '') {
            $targetDate = Carbon::parse($dateOption, $timezone)->startOfDay();
            $materialized = $this->materializePending($targetDate, true, $dryRun);
            $finalized = 0;
        } else {
            $materialized = $this->materializePending($today, false, $dryRun);
            $finalized = $this->finalizeExpiredPending($today, $dryRun);
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

    private function materializePending(Carbon $date, bool $force, bool $dryRun): int
    {
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

    private function finalizeExpiredPending(Carbon $today, bool $dryRun): int
    {
        $timezone = config('app.timezone', 'Asia/Jakarta');
        $schedules = SchoolSchedule::query()
            ->where('is_active', true)
            ->get()
            ->keyBy(fn (SchoolSchedule $schedule): int => (int) $schedule->day_of_week);
        $holidays = SchoolHoliday::active()
            ->get(['start_date', 'end_date'])
            ->map(fn (object $holiday): array => [
                Carbon::parse($holiday->start_date, $timezone)->startOfDay(),
                Carbon::parse($holiday->end_date, $timezone)->endOfDay(),
            ]);

        $finalized = 0;

        Attendance::query()
            ->where('status', 'perlu_tinjauan')
            ->whereNull('check_in_at')
            ->whereNull('check_out_at')
            ->where('is_manual_override', false)
            ->whereDate('school_date', '<', $today->toDateString())
            ->orderBy('id')
            ->chunkById(200, function (Collection $attendances) use ($today, $dryRun, $schedules, $holidays, $timezone, &$finalized): void {
                foreach ($attendances as $attendance) {
                    if ($dryRun) {
                        if ($this->isReadyForAutoAlpa($attendance, $schedules, $holidays, $timezone)) {
                            $finalized++;
                        }

                        continue;
                    }

                    $finalized += DB::transaction(function () use ($attendance, $today, $schedules, $holidays, $timezone): int {
                        $locked = Attendance::query()
                            ->whereKey($attendance->id)
                            ->where('status', 'perlu_tinjauan')
                            ->whereNull('check_in_at')
                            ->whereNull('check_out_at')
                            ->where('is_manual_override', false)
                            ->whereDate('school_date', '<', $today->toDateString())
                            ->lockForUpdate()
                            ->first();

                        if (! $locked || ! $this->isReadyForAutoAlpa($locked, $schedules, $holidays, $timezone)) {
                            return 0;
                        }

                        $before = $this->auditState($locked);

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

                        AuditLog::create([
                            'actor_id' => null,
                            'action' => 'auto_alpa_pending_attendance',
                            'entity_type' => 'Attendance',
                            'entity_id' => $locked->id,
                            'before' => $before,
                            'after' => $this->auditState($locked),
                            'ip_address' => null,
                            'created_at' => now(),
                        ]);

                        return 1;
                    });
                }
            });

        return $finalized;
    }

    /**
     * @param  Collection<int, SchoolSchedule>  $schedules
     * @param  Collection<int, array{0: Carbon, 1: Carbon}>  $holidays
     */
    private function isReadyForAutoAlpa(
        Attendance $attendance,
        Collection $schedules,
        Collection $holidays,
        string $timezone,
    ): bool {
        $schoolDate = Carbon::parse(
            $attendance->school_date->toDateString(),
            $timezone
        );

        if (! $schedules->has($schoolDate->dayOfWeekIso)) {
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

                // PIN yang belum dipetakan tidak punya device_user. Jika PIN sama
                // dengan NIS, tetap anggap sebagai bukti scan siswa tersebut.
                if (is_string($nis) && $nis !== '') {
                    $query->orWhere(function ($unmapped) use ($nis): void {
                        $unmapped->where('status', AttendanceLog::STATUS_UNMAPPED)
                            ->where('machine_user_id', $nis);
                    });
                }
            })
            ->exists();
    }

    private function scheduleFor(Carbon $date): ?SchoolSchedule
    {
        return SchoolSchedule::query()
            ->where('day_of_week', $date->dayOfWeekIso)
            ->where('is_active', true)
            ->first();
    }

    private function isHoliday(Carbon $date): bool
    {
        return SchoolHoliday::isHoliday($date);
    }

    /**
     * @return array<string, mixed>
     */
    private function auditState(Attendance $attendance): array
    {
        return [
            'status' => $attendance->status,
            'check_in_at' => $attendance->check_in_at?->format('Y-m-d H:i:s'),
            'check_out_at' => $attendance->check_out_at?->format('Y-m-d H:i:s'),
            'check_in_status' => $attendance->check_in_status,
            'check_out_status' => $attendance->check_out_status,
            'late_minutes' => $attendance->late_minutes,
            'early_leave_minutes' => $attendance->early_leave_minutes,
            'is_manual_override' => $attendance->is_manual_override,
            'override_reason' => $attendance->override_reason,
            'operator_id' => $attendance->operator_id,
            'policy_version' => $attendance->policy_version,
        ];
    }
}
