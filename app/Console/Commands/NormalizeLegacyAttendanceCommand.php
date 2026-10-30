<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use App\Models\SchoolHoliday;
use App\Models\SchoolSchedule;
use App\Services\AttendanceStatusResolver;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class NormalizeLegacyAttendanceCommand extends Command
{
    protected $signature = 'attendance:normalize-legacy
                            {--dry-run : Tampilkan jumlah data yang akan diubah tanpa menyimpan}';

    protected $description = 'Normalisasi status presensi lama (pulang_cepat, scan tidak lengkap, dan Alpa otomatis)';

    /** @var Collection<int, SchoolSchedule> */
    private Collection $schedules;

    /** @var Collection<int, array{0: Carbon, 1: Carbon}> */
    private Collection $holidays;

    private string $timezone;

    public function __construct(
        private readonly AttendanceStatusResolver $statusResolver
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $this->timezone = config('app.timezone', 'Asia/Jakarta');
        $this->schedules = SchoolSchedule::where('is_active', true)
            ->get()
            ->keyBy(fn (SchoolSchedule $schedule): int => (int) $schedule->day_of_week);
        $this->holidays = SchoolHoliday::active()
            ->get(['start_date', 'end_date'])
            ->map(fn (object $holiday): array => [
                Carbon::parse($holiday->start_date, $this->timezone)->startOfDay(),
                Carbon::parse($holiday->end_date, $this->timezone)->endOfDay(),
            ]);

        $counts = [
            'complete_pending' => $this->normalizeCompletePendingScans($dryRun),
            'checkout_only' => $this->normalizeCheckoutOnly($dryRun),
            'checkin_only' => $this->normalizeCheckInOnly($dryRun),
            'pulang_cepat' => $this->normalizePulangCepat($dryRun),
            'auto_alpa_deleted' => $this->deleteAutomaticAlpa($dryRun),
        ];

        $manualHadirWithoutScan = $this->countManualPresenceWithoutScan();
        $manualAbsenceWithScan = $this->countManualAbsenceWithScan();

        $this->newLine();
        $this->table(
            ['Perubahan', 'Jumlah'],
            [
                ['Scan lengkap yang masih pending', $counts['complete_pending']],
                ['Scan pulang tanpa scan masuk', $counts['checkout_only']],
                ['Scan masuk tanpa scan pulang (Hadir/Terlambat)', $counts['checkin_only']],
                ['Status pulang_cepat lama', $counts['pulang_cepat']],
                ['Baris Alpa otomatis dihapus', $counts['auto_alpa_deleted']],
                ['Total perubahan', array_sum($counts)],
                ['Manual hadir tanpa scan (dibiarkan)', $manualHadirWithoutScan],
                ['Koreksi manual dengan scan (perlu audit)', $manualAbsenceWithScan],
            ]
        );

        if ($dryRun) {
            $this->info('Dry-run selesai. Tidak ada data yang diubah.');
        } else {
            $this->info('Normalisasi selesai.');
        }

        return self::SUCCESS;
    }

    private function normalizeCompletePendingScans(bool $dryRun): int
    {
        $changed = 0;

        Attendance::query()
            ->whereNotNull('check_in_at')
            ->whereNotNull('check_out_at')
            ->where('status', 'perlu_tinjauan')
            ->where('is_manual_override', false)
            ->orderBy('id')
            ->chunkById(200, function (Collection $attendances) use ($dryRun, &$changed): void {
                foreach ($attendances as $attendance) {
                    $checkInAt = $this->dateTime($attendance, 'check_in_at');
                    $checkOutAt = $this->dateTime($attendance, 'check_out_at');

                    if ($checkInAt === null || $checkOutAt === null) {
                        continue;
                    }

                    if ($dryRun) {
                        $changed++;

                        continue;
                    }

                    $schedule = $this->scheduleFor($checkInAt);
                    [$checkInStatus, $lateMinutes] = $this->resolveCheckIn($attendance, $checkInAt, $schedule);
                    [$checkOutStatus, $earlyLeaveMinutes] = $this->resolveCheckOut($attendance, $checkOutAt, $schedule);

                    $updated = Attendance::query()
                        ->whereKey($attendance->id)
                        ->whereNotNull('check_in_at')
                        ->whereNotNull('check_out_at')
                        ->where('status', 'perlu_tinjauan')
                        ->where('is_manual_override', false)
                        ->update([
                            'status' => $checkInStatus === AttendanceStatusResolver::CHECK_IN_LATE ? 'terlambat' : 'hadir',
                            'check_in_status' => $checkInStatus,
                            'check_out_status' => $checkOutStatus,
                            'late_minutes' => $lateMinutes,
                            'early_leave_minutes' => $earlyLeaveMinutes,
                        ]);

                    $changed += $updated;
                }
            });

        return $changed;
    }

    private function normalizeCheckoutOnly(bool $dryRun): int
    {
        $changed = 0;

        Attendance::query()
            ->whereNull('check_in_at')
            ->whereNotNull('check_out_at')
            ->where('is_manual_override', false)
            ->whereIn('status', ['hadir', 'terlambat', 'pulang_cepat'])
            ->orderBy('id')
            ->chunkById(200, function (Collection $attendances) use ($dryRun, &$changed): void {
                foreach ($attendances as $attendance) {
                    if ($dryRun) {
                        $changed++;

                        continue;
                    }

                    $checkOutAt = $this->dateTime($attendance, 'check_out_at');
                    $schedule = $checkOutAt ? $this->scheduleFor($checkOutAt) : null;

                    $updated = Attendance::query()
                        ->whereKey($attendance->id)
                        ->whereNull('check_in_at')
                        ->whereNotNull('check_out_at')
                        ->where('is_manual_override', false)
                        ->whereIn('status', ['hadir', 'terlambat', 'pulang_cepat'])
                        ->update([
                            'status' => 'perlu_tinjauan',
                            'check_in_status' => AttendanceStatusResolver::CHECK_IN_MISSING,
                            'check_out_status' => AttendanceStatusResolver::CHECK_OUT_REVIEW,
                            'late_minutes' => 0,
                            'early_leave_minutes' => $checkOutAt && $schedule
                                ? $this->statusResolver->earlyLeaveMinutes($checkOutAt, $schedule)
                                : (int) $attendance->early_leave_minutes,
                            'override_reason' => 'Normalisasi: hanya ada scan pulang, scan masuk belum tersedia.',
                        ]);

                    $changed += $updated;
                }
            });

        return $changed;
    }

    private function normalizeCheckInOnly(bool $dryRun): int
    {
        $changed = 0;

        Attendance::query()
            ->whereNotNull('check_in_at')
            ->whereNull('check_out_at')
            ->where('is_manual_override', false)
            ->whereIn('status', ['perlu_tinjauan', 'hadir', 'terlambat', 'pulang_cepat'])
            ->where(function ($query): void {
                $query->whereIn('status', ['perlu_tinjauan', 'pulang_cepat'])
                    ->orWhereNull('check_in_status')
                    ->orWhereNull('check_out_status')
                    ->orWhere('check_out_status', '!=', AttendanceStatusResolver::CHECK_OUT_MISSING);
            })
            ->orderBy('id')
            ->chunkById(200, function (Collection $attendances) use ($dryRun, &$changed): void {
                foreach ($attendances as $attendance) {
                    $checkInAt = $this->dateTime($attendance, 'check_in_at');

                    if ($checkInAt === null) {
                        continue;
                    }

                    if ($dryRun) {
                        $changed++;

                        continue;
                    }

                    $schedule = $this->scheduleFor($checkInAt);
                    [$checkInStatus, $lateMinutes] = $this->resolveCheckIn($attendance, $checkInAt, $schedule);
                    $attributes = [
                        'status' => $checkInStatus === AttendanceStatusResolver::CHECK_IN_LATE ? 'terlambat' : 'hadir',
                        'check_in_status' => $checkInStatus,
                        'check_out_status' => AttendanceStatusResolver::CHECK_OUT_MISSING,
                        'late_minutes' => $lateMinutes,
                        'early_leave_minutes' => 0,
                    ];

                    if ($attendance->operator_id === null
                        && str_starts_with((string) $attendance->override_reason, 'Normalisasi:')) {
                        $attributes['override_reason'] = null;
                    }

                    $updated = Attendance::query()
                        ->whereKey($attendance->id)
                        ->whereNotNull('check_in_at')
                        ->whereNull('check_out_at')
                        ->where('is_manual_override', false)
                        ->whereIn('status', ['perlu_tinjauan', 'hadir', 'terlambat', 'pulang_cepat'])
                        ->update($attributes);

                    $changed += $updated;
                }
            });

        return $changed;
    }

    private function normalizePulangCepat(bool $dryRun): int
    {
        $changed = 0;

        Attendance::query()
            ->where('status', 'pulang_cepat')
            ->orderBy('id')
            ->chunkById(200, function (Collection $attendances) use ($dryRun, &$changed): void {
                foreach ($attendances as $attendance) {
                    $attributes = $this->pulangCepatAttributes($attendance);

                    if ($dryRun) {
                        $changed++;

                        continue;
                    }

                    $updated = Attendance::query()
                        ->whereKey($attendance->id)
                        ->where('status', 'pulang_cepat')
                        ->update($attributes);

                    $changed += $updated;
                }
            });

        return $changed;
    }

    /**
     * @return array<string, mixed>
     */
    private function pulangCepatAttributes(Attendance $attendance): array
    {
        $checkInAt = $this->dateTime($attendance, 'check_in_at');
        $checkOutAt = $this->dateTime($attendance, 'check_out_at');
        $schedule = $this->scheduleFor($checkInAt ?? $checkOutAt ?? $attendance->school_date);

        if ($checkInAt !== null) {
            [$checkInStatus, $lateMinutes] = $this->resolveCheckIn($attendance, $checkInAt, $schedule);

            if ($checkOutAt === null) {
                return [
                    'status' => $checkInStatus === AttendanceStatusResolver::CHECK_IN_LATE ? 'terlambat' : 'hadir',
                    'check_in_status' => $checkInStatus,
                    'check_out_status' => AttendanceStatusResolver::CHECK_OUT_MISSING,
                    'late_minutes' => $lateMinutes,
                    'early_leave_minutes' => 0,
                ];
            }

            [$checkOutStatus, $earlyLeaveMinutes] = $this->resolveCheckOut($attendance, $checkOutAt, $schedule);

            return [
                'status' => $checkInStatus === AttendanceStatusResolver::CHECK_IN_LATE ? 'terlambat' : 'hadir',
                'check_in_status' => $checkInStatus,
                'check_out_status' => $checkOutStatus,
                'late_minutes' => $lateMinutes,
                'early_leave_minutes' => $earlyLeaveMinutes,
            ];
        }

        if ($checkOutAt !== null) {
            [$checkOutStatus, $earlyLeaveMinutes] = $this->resolveCheckOut($attendance, $checkOutAt, $schedule);

            return [
                'status' => $attendance->is_manual_override ? 'hadir' : 'perlu_tinjauan',
                'check_in_status' => $attendance->is_manual_override
                    ? AttendanceStatusResolver::CHECK_IN_VERIFIED
                    : AttendanceStatusResolver::CHECK_IN_MISSING,
                'check_out_status' => $attendance->is_manual_override
                    ? $checkOutStatus
                    : AttendanceStatusResolver::CHECK_OUT_REVIEW,
                'late_minutes' => 0,
                'early_leave_minutes' => $earlyLeaveMinutes,
                'override_reason' => $attendance->is_manual_override
                    ? $attendance->override_reason
                    : 'Normalisasi: hanya ada scan pulang, scan masuk belum tersedia.',
            ];
        }

        if ($attendance->is_manual_override) {
            return [
                'status' => 'hadir',
                'check_in_status' => AttendanceStatusResolver::CHECK_IN_VERIFIED,
                'check_out_status' => AttendanceStatusResolver::CHECK_OUT_MISSING,
                'late_minutes' => 0,
                'early_leave_minutes' => 0,
            ];
        }

        return [
            'status' => 'perlu_tinjauan',
            'check_in_status' => AttendanceStatusResolver::CHECK_IN_MISSING,
            'check_out_status' => AttendanceStatusResolver::CHECK_OUT_MISSING,
            'late_minutes' => 0,
            'early_leave_minutes' => 0,
        ];
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function resolveCheckIn(Attendance $attendance, Carbon $checkInAt, ?SchoolSchedule $schedule): array
    {
        $storedStatus = $attendance->check_in_status;

        if (in_array($storedStatus, [
            AttendanceStatusResolver::CHECK_IN_ON_TIME,
            AttendanceStatusResolver::CHECK_IN_LATE,
            AttendanceStatusResolver::CHECK_IN_VERIFIED,
        ], true)) {
            $lateMinutes = (int) $attendance->late_minutes;

            if ($storedStatus === AttendanceStatusResolver::CHECK_IN_LATE
                && $lateMinutes === 0
                && $schedule !== null) {
                $lateMinutes = $this->statusResolver->lateMinutes($checkInAt, $schedule);
            }

            return [$storedStatus, $lateMinutes];
        }

        if ($schedule === null) {
            return [AttendanceStatusResolver::CHECK_IN_ON_TIME, 0];
        }

        return [
            $this->statusResolver->checkInStatus($checkInAt, $schedule),
            $this->statusResolver->lateMinutes($checkInAt, $schedule),
        ];
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function resolveCheckOut(Attendance $attendance, ?Carbon $checkOutAt, ?SchoolSchedule $schedule): array
    {
        if ($checkOutAt === null) {
            return [AttendanceStatusResolver::CHECK_OUT_MISSING, 0];
        }

        if ($schedule !== null) {
            return [
                $this->statusResolver->checkOutStatus($checkOutAt, $schedule),
                $this->statusResolver->earlyLeaveMinutes($checkOutAt, $schedule),
            ];
        }

        if (in_array($attendance->check_out_status, [
            AttendanceStatusResolver::CHECK_OUT_ON_TIME,
            AttendanceStatusResolver::CHECK_OUT_EARLY,
        ], true)) {
            return [$attendance->check_out_status, (int) $attendance->early_leave_minutes];
        }

        return [
            (int) $attendance->early_leave_minutes > 0
                ? AttendanceStatusResolver::CHECK_OUT_EARLY
                : AttendanceStatusResolver::CHECK_OUT_ON_TIME,
            (int) $attendance->early_leave_minutes,
        ];
    }

    private function countManualPresenceWithoutScan(): int
    {
        return Attendance::query()
            ->where('status', 'hadir')
            ->where('is_manual_override', true)
            ->whereNull('check_in_at')
            ->whereNull('check_out_at')
            ->count();
    }

    private function countManualAbsenceWithScan(): int
    {
        return Attendance::query()
            ->whereIn('status', ['izin', 'sakit', 'alpa'])
            ->where('is_manual_override', true)
            ->where(function ($query): void {
                $query->whereNotNull('check_in_at')
                    ->orWhereNotNull('check_out_at');
            })
            ->count();
    }

    private function deleteAutomaticAlpa(bool $dryRun): int
    {
        $deleted = 0;

        Attendance::query()
            ->where('status', 'alpa')
            ->where('is_manual_override', false)
            ->whereNull('operator_id')
            ->whereNull('override_reason')
            ->whereNull('check_in_at')
            ->whereNull('check_out_at')
            ->whereNull('check_in_log_id')
            ->whereNull('check_out_log_id')
            ->whereDoesntHave('notifications')
            ->orderBy('id')
            ->chunkById(200, function (Collection $attendances) use ($dryRun, &$deleted): void {
                foreach ($attendances as $attendance) {
                    if ($dryRun) {
                        $deleted++;

                        continue;
                    }

                    $deleted += Attendance::query()
                        ->whereKey($attendance->id)
                        ->where('status', 'alpa')
                        ->where('is_manual_override', false)
                        ->whereNull('operator_id')
                        ->whereNull('override_reason')
                        ->whereNull('check_in_at')
                        ->whereNull('check_out_at')
                        ->whereNull('check_in_log_id')
                        ->whereNull('check_out_log_id')
                        ->whereDoesntHave('notifications')
                        ->delete();
                }
            });

        return $deleted;
    }

    private function dateTime(Attendance $attendance, string $column): ?Carbon
    {
        $raw = $attendance->getRawOriginal($column);

        return $raw ? Carbon::parse($raw, $this->timezone) : null;
    }

    private function scheduleFor(Carbon $date): ?SchoolSchedule
    {
        $dateKey = $date->toDateString();
        $isHoliday = $this->holidays->contains(
            fn (array $range): bool => $date->betweenIncluded($range[0], $range[1])
        );

        if ($isHoliday) {
            return null;
        }

        $schedule = $this->schedules->get((int) $date->dayOfWeekIso);

        return $schedule instanceof SchoolSchedule ? $schedule : null;
    }
}
