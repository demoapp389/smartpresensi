<?php

namespace App\Console\Commands;

use App\Jobs\SendWhatsAppNotificationJob;
use App\Models\NotificationLog;
use Illuminate\Console\Command;

class SweepQueuedWhatsAppNotificationsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'whatsapp:sweep-queued
                            {--minutes=10 : Usia minimum status queued sebelum dikirim ulang}
                            {--limit=200 : Jumlah maksimum notifikasi yang diperiksa}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Kirim ulang notifikasi WhatsApp yang tertahan di status queued agar tidak menggantung permanen';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $minutes = max(1, (int) $this->option('minutes'));
        $limit = max(1, (int) $this->option('limit'));
        $cutoff = now()->subMinutes($minutes);
        $maxAttempts = max(1, (int) config('whatsapp.retry_limit', 3));

        $notificationIds = NotificationLog::query()
            ->where('status', 'queued')
            ->where('attempts', '<', $maxAttempts)
            ->where(function ($query) use ($cutoff): void {
                $query->whereNull('queued_at')
                    ->orWhere('queued_at', '<=', $cutoff);
            })
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        foreach ($notificationIds as $notificationId) {
            SendWhatsAppNotificationJob::dispatch((int) $notificationId);
        }

        if ($notificationIds->isEmpty()) {
            $this->info('Tidak ada notifikasi queued yang perlu dikirim ulang.');

            return self::SUCCESS;
        }

        $this->info($notificationIds->count()." notifikasi queued dijadwalkan ulang (ambang {$minutes} menit).");

        return self::SUCCESS;
    }
}
