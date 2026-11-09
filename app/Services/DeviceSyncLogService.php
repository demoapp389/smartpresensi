<?php

namespace App\Services;

use App\Models\DeviceSyncLog;
use App\Models\FingerprintDevice;
use Illuminate\Support\Carbon;

class DeviceSyncLogService
{
    /**
     * Catat riwayat sinkronisasi absensi dengan peredaman log otomatis.
     *
     * Log manual selalu disimpan. Untuk proses otomatis:
     * - kegagalan yang berulang dalam satu jam hanya dicatat sekali;
     * - keberhasilan tanpa record baru hanya dicatat saat menjadi sinyal pemulihan
     *   setelah kegagalan terakhir;
     * - keberhasilan dengan record sedikit dibatasi menjadi heartbeat berkala,
     *   sedangkan backlog besar dan pemulihan selalu dicatat.
     *
     * @param  array<string, mixed>  $details
     */
    public function recordAttendanceSync(
        FingerprintDevice $device,
        string $status,
        int $recordsCount,
        string $message,
        array $details = [],
        ?int $operatorId = null,
    ): ?DeviceSyncLog {
        $isAutomatic = $operatorId === null;
        $latest = DeviceSyncLog::query()
            ->where('device_id', $device->id)
            ->where('sync_type', 'sync_attendances')
            ->latest('id')
            ->first();

        if ($isAutomatic && $status === 'failed') {
            $lastFailedRecently = $latest?->status === 'failed'
                && $latest->created_at instanceof Carbon
                && $latest->created_at->gte(now()->subHour());

            if ($lastFailedRecently) {
                return null;
            }
        }

        if ($isAutomatic && $status === 'success') {
            $isRecovery = $latest?->status === 'failed';

            if (! $isRecovery) {
                if ($recordsCount === 0) {
                    return null;
                }

                $significantRecords = (int) config('attendance.sync_log_significant_records', 10);
                $intervalMinutes = (int) config('attendance.sync_log_success_interval_minutes', 60);

                $lastSuccessRecently = $latest?->status === 'success'
                    && $latest->created_at instanceof Carbon
                    && $latest->created_at->gte(now()->subMinutes($intervalMinutes));

                if ($recordsCount < $significantRecords && $lastSuccessRecently) {
                    return null;
                }
            }
        }

        return DeviceSyncLog::create([
            'device_id' => $device->id,
            'sync_type' => 'sync_attendances',
            'status' => $status,
            'records_count' => $recordsCount,
            'message' => $message,
            'details' => $details,
            'operator_id' => $operatorId,
        ]);
    }
}
