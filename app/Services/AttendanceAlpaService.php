<?php

namespace App\Services;

use App\Models\SchedulePeriod;
use App\Models\SchoolHoliday;
use App\Models\SchoolSchedule;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class AttendanceAlpaService
{
    public function __construct(
        protected AttendancePeriodService $periodService
    ) {}

    /**
     * Tanggal efektif yang sudah melewati batas Alpa pada rentang tertentu.
     *
     * Tanggal sebelum tanggal mulai sistem absensi tidak pernah dianggap
     * sebagai hari Alpa, termasuk saat rentang dimulai lebih awal.
     *
     * @return array<string, Carbon>
     */
    public function getAlpaDateKeys(Carbon $startDate, Carbon $endDate, ?Carbon $now = null): array
    {
        $startDate = $this->periodService->clamp($startDate->copy()->startOfDay());

        if ($startDate->gt($endDate->copy()->endOfDay())) {
            return [];
        }

        $timezone = $startDate->getTimezone();
        $now ??= now($timezone);

        /** @var Collection<int, SchoolSchedule> $schedules */
        $schedules = SchoolSchedule::query()->get();
        /** @var Collection<int, SchedulePeriod> $periods */
        $periods = SchedulePeriod::query()->where('is_active', true)->get();

        $holidays = SchoolHoliday::active()
            ->whereDate('start_date', '<=', $endDate->toDateString())
            ->whereDate('end_date', '>=', $startDate->toDateString())
            ->get();

        $alpaDates = [];
        $cursor = $startDate->copy()->startOfDay();
        $lastDate = $endDate->copy()->endOfDay();

        while ($cursor->lte($lastDate)) {
            $dateKey = $cursor->toDateString();
            $schedule = SchoolSchedule::resolveFrom($cursor, $schedules, $periods);

            $isHoliday = $holidays->contains(
                fn (SchoolHoliday $holiday): bool => $dateKey >= $holiday->start_date->toDateString()
                    && $dateKey <= $holiday->end_date->toDateString()
            );

            if ($schedule instanceof SchoolSchedule && $schedule->is_active && $schedule->end_at && ! $isHoliday) {
                $cutoff = $schedule->alpaCutoffForDate($cursor);

                if ($now->greaterThanOrEqualTo($cutoff)) {
                    $alpaDates[$dateKey] = $cursor->copy();
                }
            }

            $cursor->addDay();
        }

        return $alpaDates;
    }

    public function isAlpaDue(Carbon $date, ?Carbon $now = null): bool
    {
        return array_key_exists(
            $date->toDateString(),
            $this->getAlpaDateKeys($date->copy()->startOfDay(), $date->copy()->startOfDay(), $now)
        );
    }
}
