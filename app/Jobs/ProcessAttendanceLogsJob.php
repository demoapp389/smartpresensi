<?php

namespace App\Jobs;

use App\Models\FingerprintDevice;
use App\Services\AttendanceLogProcessingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Memproses raw log absensi di latar belakang setelah endpoint ingest membalas
 * connector. Connector hanya menunggu 8 detik; pemrosesan absensi (termasuk
 * pembuatan notifikasi) tidak boleh menahan request HTTP tersebut.
 */
class ProcessAttendanceLogsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        public int $deviceId,
        public int $limit = 200,
    ) {
        $this->onQueue('default');
    }

    public function handle(AttendanceLogProcessingService $logProcessor): void
    {
        $device = FingerprintDevice::find($this->deviceId);

        if (! $device) {
            return;
        }

        $logProcessor->processDueForDevice($device, null, $this->limit);
    }
}
