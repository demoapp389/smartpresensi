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
     *   setelah kegagalan terakhir.
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

        if ($isAutomatic && $status === 'success' && $recordsCount === 0 && $latest?->status !== 'failed') {
            return null;
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
