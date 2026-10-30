<?php

namespace App\Services;

use App\Models\SchoolSchedule;
use Carbon\Carbon;
use Carbon\CarbonInterface;

class AttendanceStatusResolver
{
    public const CHECK_IN_ON_TIME = 'tepat_waktu';

    public const CHECK_IN_LATE = 'terlambat';

    public const CHECK_IN_MISSING = 'tidak_absen';

    public const CHECK_IN_VERIFIED = 'diverifikasi';

    public const CHECK_OUT_ON_TIME = 'tepat_waktu';

    public const CHECK_OUT_EARLY = 'pulang_cepat';

    public const CHECK_OUT_MISSING = 'belum_absen';

    public const CHECK_OUT_REVIEW = 'perlu_verifikasi';

    public function lateMinutes(CarbonInterface $checkInAt, SchoolSchedule $schedule): int
    {
        $startAt = $this->scheduleMoment($schedule, 'start_at', $checkInAt);

        if ($checkInAt->lessThanOrEqualTo($startAt)) {
            return 0;
        }

        return (int) floor(abs($checkInAt->diffInMinutes($startAt)));
    }

    public function checkInStatus(CarbonInterface $checkInAt, SchoolSchedule $schedule): string
    {
        $lateAfter = $this->scheduleMoment($schedule, 'late_after', $checkInAt);

        return $checkInAt->greaterThan($lateAfter)
            ? self::CHECK_IN_LATE
            : self::CHECK_IN_ON_TIME;
    }

    public function earlyLeaveMinutes(CarbonInterface $checkOutAt, SchoolSchedule $schedule): int
    {
        $endAt = $this->scheduleMoment($schedule, 'end_at', $checkOutAt);

        if ($checkOutAt->greaterThanOrEqualTo($endAt)) {
            return 0;
        }

        return (int) floor(abs($endAt->diffInMinutes($checkOutAt)));
    }

    public function checkOutStatus(CarbonInterface $checkOutAt, SchoolSchedule $schedule): string
    {
        return $this->earlyLeaveMinutes($checkOutAt, $schedule) > 0
            ? self::CHECK_OUT_EARLY
            : self::CHECK_OUT_ON_TIME;
    }

    public function alpaCutoff(SchoolSchedule $schedule, CarbonInterface $date): Carbon
    {
        return $schedule->alpaCutoffForDate($date);
    }

    private function scheduleMoment(
        SchoolSchedule $schedule,
        string $column,
        CarbonInterface $date
    ): Carbon {
        return Carbon::parse(
            $date->toDateString().' '.$schedule->{$column},
            $date->getTimezone()
        );
    }
}
