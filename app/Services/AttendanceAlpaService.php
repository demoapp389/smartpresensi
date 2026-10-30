<?php

namespace App\Services;

use App\Models\SchoolHoliday;
use App\Models\SchoolSchedule;
use Carbon\Carbon;

class AttendanceAlpaService
{
    /**
     * Tanggal efektif yang sudah melewati batas Alpa pada rentang tertentu.
     *
     * @return array<string, Carbon>
     */
    public function getAlpaDateKeys(Carbon $startDate, Carbon $endDate, ?Carbon $now = null): array
    {
        $timezone = $startDate->getTimezone();
        $now ??= now($timezone);

        $schedules = SchoolSchedule::where('is_active', true)
            ->get()
            ->keyBy(fn (SchoolSchedule $schedule): int => (int) $schedule->day_of_week);

        $holidays = SchoolHoliday::active()
            ->whereDate('start_date', '<=', $endDate->toDateString())
            ->whereDate('end_date', '>=', $startDate->toDateString())
            ->get();

        $alpaDates = [];
        $cursor = $startDate->copy()->startOfDay();
        $lastDate = $endDate->copy()->endOfDay();

        while ($cursor->lte($lastDate)) {
            $dateKey = $cursor->toDateString();
            $schedule = $schedules->get((int) $cursor->dayOfWeekIso);

            $isHoliday = $holidays->contains(
                fn (SchoolHoliday $holiday): bool => $dateKey >= $holiday->start_date->toDateString()
                    && $dateKey <= $holiday->end_date->toDateString()
            );

            if ($schedule instanceof SchoolSchedule && $schedule->end_at && ! $isHoliday) {
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
