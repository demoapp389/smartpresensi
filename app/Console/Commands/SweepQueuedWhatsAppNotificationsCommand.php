<?php

namespace App\Console\Commands;

use App\Jobs\SendWhatsAppNotificationJob;
use App\Models\NotificationLog;
use App\Services\WhatsApp\WhatsAppSendPacer;
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
    public function handle(WhatsAppSendPacer $pacer): int
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

        $offsetSeconds = 0;

        foreach ($notificationIds as $notificationId) {
            $offsetSeconds += $pacer->nextGapSeconds();
            $scheduledAt = now()->addSeconds($offsetSeconds);

            // Tandai waktu kirim terjadwal agar sapuan berikutnya tidak
            // mendispatch ulang pesan yang masih menunggu giliran.
            NotificationLog::whereKey($notificationId)->update(['queued_at' => $scheduledAt]);

            SendWhatsAppNotificationJob::dispatch((int) $notificationId)->delay($scheduledAt);
        }

        if ($notificationIds->isEmpty()) {
            $this->info('Tidak ada notifikasi queued yang perlu dikirim ulang.');

            return self::SUCCESS;
        }

        $this->info($notificationIds->count()." notifikasi queued dijadwalkan ulang (ambang {$minutes} menit).");

        return self::SUCCESS;
    }
}
