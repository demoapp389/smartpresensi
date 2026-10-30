<?php

namespace App\Console\Commands;

use App\Services\AttendanceLogProcessingService;
use Illuminate\Console\Command;

class RetryAttendanceLogs extends Command
{
    protected $signature = 'attendance:retry-logs {--limit=500 : Jumlah maksimum log per batch}';

    protected $description = 'Proses ulang raw log yang masih pending dan sudah jatuh tempo retry';

    public function handle(AttendanceLogProcessingService $processor): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $totals = [
            'attempted' => 0,
            'processed' => 0,
            'failed' => 0,
            'review' => 0,
        ];

        do {
            $result = $processor->processDue(null, $limit);

            foreach ($totals as $key => $value) {
                $totals[$key] = $value + $result[$key];
            }

            $processedInBatch = $result['attempted'];
            $madeProgress = $result['processed'] + $result['failed'] + $result['review'] > 0;
        } while ($processedInBatch >= $limit && $madeProgress);

        $this->info(
            "Retry raw log selesai: {$totals['attempted']} dicoba, "
            ."{$totals['processed']} diproses, {$totals['review']} perlu tinjauan waktu, "
            ."{$totals['failed']} masih gagal."
        );

        return self::SUCCESS;
    }
}
