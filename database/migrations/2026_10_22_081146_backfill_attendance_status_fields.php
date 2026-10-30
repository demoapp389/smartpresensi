<?php

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $timezone = config('app.timezone', 'Asia/Jakarta');

        $schedules = DB::table('school_schedules')
            ->where('is_active', true)
            ->get()
            ->keyBy('day_of_week');

        $holidays = DB::table('school_holidays')
            ->where('is_active', true)
            ->get(['start_date', 'end_date'])
            ->map(fn (object $holiday): array => [
                Carbon::parse($holiday->start_date, $timezone)->startOfDay(),
                Carbon::parse($holiday->end_date, $timezone)->endOfDay(),
            ]);

        $statusesThatRequireCheckout = ['hadir', 'terlambat', 'pulang_cepat', 'perlu_tinjauan'];

        DB::table('attendances')
            ->select(['id', 'school_date', 'check_in_at', 'check_out_at', 'status'])
            ->orderBy('id')
            ->chunkById(200, function ($attendances) use ($schedules, $holidays, $statusesThatRequireCheckout, $timezone): void {
                foreach ($attendances as $attendance) {
                    $date = Carbon::parse($attendance->school_date, $timezone);
                    $schedule = $schedules->get($date->dayOfWeekIso);
                    $isHoliday = $holidays->contains(
                        fn (array $range): bool => $date->betweenIncluded($range[0], $range[1])
                    );

                    $checkInAt = $attendance->check_in_at
                        ? Carbon::parse($attendance->check_in_at, $timezone)
                        : null;
                    $checkOutAt = $attendance->check_out_at
                        ? Carbon::parse($attendance->check_out_at, $timezone)
                        : null;

                    [$checkInStatus, $lateMinutes] = $this->resolveCheckIn(
                        $checkInAt,
                        $checkOutAt,
                        $schedule,
                        $isHoliday,
                        $date
                    );

                    [$checkOutStatus, $earlyLeaveMinutes] = $this->resolveCheckOut(
                        $checkInAt,
                        $checkOutAt,
                        $schedule,
                        $isHoliday,
                        $date,
                        $attendance->status,
                        $statusesThatRequireCheckout
                    );

                    DB::table('attendances')
                        ->where('id', $attendance->id)
                        ->update([
                            'check_in_status' => $checkInStatus,
                            'check_out_status' => $checkOutStatus,
                            'late_minutes' => $lateMinutes,
                            'early_leave_minutes' => $earlyLeaveMinutes,
                        ]);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Data hasil backfill tidak dapat dikembalikan ke kondisi kosong.
    }

    /**
     * @return array{0: string|null, 1: int}
     */
    private function resolveCheckIn(
        ?Carbon $checkInAt,
        ?Carbon $checkOutAt,
        ?object $schedule,
        bool $isHoliday,
        Carbon $date
    ): array {
        if ($checkInAt === null) {
            return [$checkOutAt !== null ? 'tidak_absen' : null, 0];
        }

        if ($schedule === null || $isHoliday) {
            return ['tepat_waktu', 0];
        }

        $lateAfter = $this->scheduleMoment($schedule, 'late_after', $date);
        $startAt = $this->scheduleMoment($schedule, 'start_at', $date);
        $lateMinutes = $checkInAt->greaterThan($startAt)
            ? (int) floor(abs($checkInAt->diffInMinutes($startAt)))
            : 0;

        return [
            $checkInAt->lessThanOrEqualTo($lateAfter) ? 'tepat_waktu' : 'terlambat',
            $lateMinutes,
        ];
    }

    /**
     * @param  array<int, string>  $statusesThatRequireCheckout
     * @return array{0: string|null, 1: int}
     */
    private function resolveCheckOut(
        ?Carbon $checkInAt,
        ?Carbon $checkOutAt,
        ?object $schedule,
        bool $isHoliday,
        Carbon $date,
        string $status,
        array $statusesThatRequireCheckout
    ): array {
        if ($checkOutAt === null) {
            $requiresCheckout = $checkInAt !== null
                || in_array($status, $statusesThatRequireCheckout, true);

            return [$requiresCheckout ? 'belum_absen' : null, 0];
        }

        if ($schedule === null || $isHoliday) {
            return ['tepat_waktu', 0];
        }

        $endAt = $this->scheduleMoment($schedule, 'end_at', $date);

        if ($checkOutAt->greaterThanOrEqualTo($endAt)) {
            return ['tepat_waktu', 0];
        }

        return ['pulang_cepat', (int) floor(abs($endAt->diffInMinutes($checkOutAt)))];
    }

    private function scheduleMoment(object $schedule, string $column, CarbonInterface $date): Carbon
    {
        return Carbon::parse(
            $date->toDateString().' '.$schedule->{$column},
            $date->getTimezone()
        );
    }
};
