<?php

namespace App\Services;

use App\Models\AttendanceLog;
use Carbon\CarbonInterface;

/**
 * Menentukan apakah waktu scan dari mesin cukup dipercaya untuk diproses
 * otomatis. Waktu terima server (received_at) menjadi acuan utama.
 */
class ScanTimeReviewService
{
    public function reviewReason(AttendanceLog $log, CarbonInterface $scanTime): ?string
    {
        if (! config('attendance.scan_time_review_enabled', true)) {
            return null;
        }

        $timezone = $scanTime->getTimezone();
        $receivedAt = $log->received_at?->copy()->setTimezone($timezone) ?? now($timezone);
        $scanDate = $scanTime->toDateString();
        $receivedDate = $receivedAt->toDateString();

        if ($scanDate < $receivedDate) {
            return "Scan tanggal {$scanDate} diterima server tanggal {$receivedDate}; perlu persetujuan admin.";
        }

        if ($scanDate > $receivedDate) {
            return "Scan tanggal {$scanDate} melewati tanggal server {$receivedDate}; perlu persetujuan admin.";
        }

        $futureTolerance = max(
            0,
            (int) config('attendance.future_scan_tolerance_minutes', 5)
        );

        if ($scanTime->greaterThan($receivedAt->copy()->addMinutes($futureTolerance))) {
            return 'Waktu scan berada di masa depan dibanding waktu server; perlu persetujuan admin.';
        }

        $maxDelay = max(
            0,
            (int) config('attendance.max_same_day_scan_delay_minutes', 60)
        );
        $delayMinutes = intdiv(
            max(0, $receivedAt->getTimestamp() - $scanTime->getTimestamp()),
            60
        );

        if ($delayMinutes > $maxDelay) {
            return "Scan diterima {$delayMinutes} menit setelah waktu scan; melebihi batas {$maxDelay} menit.";
        }

        return null;
    }
}
