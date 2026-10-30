<?php

namespace App\Jobs;

use App\Models\NotificationLog;
use App\Services\WhatsApp\WhatsAppManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Job antrean untuk mengirim notifikasi WhatsApp secara asinkron (PRD FR-12, FR-13, FR-14).
 * Mendukung retry otomatis dengan exponential backoff dan penandaan status permanen jika gagal.
 */
class SendWhatsAppNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 60, 180];

    public int $notificationId;

    /**
     * Create a new job instance.
     */
    public function __construct(int $notificationId)
    {
        $this->notificationId = $notificationId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $lock = Cache::lock('whatsapp-notification:'.$this->notificationId, 120);

        if (! $lock->get()) {
            Log::info("[SendWhatsAppNotificationJob] Pesan {$this->notificationId} sedang diproses oleh worker lain.");

            return;
        }

        try {
            $this->sendNotification();
        } finally {
            $lock->release();
        }
    }

    private function sendNotification(): void
    {
        $notification = NotificationLog::find($this->notificationId);

        if (! $notification) {
            Log::warning("[SendWhatsAppNotificationJob] NotificationLog ID {$this->notificationId} tidak ditemukan.");

            return;
        }

        // Deduplikasi ketat (BR-06 No double send) dan abaikan yang dibatalkan
        if (in_array($notification->status, ['sent', 'delivered', 'read', 'cancelled'])) {
            Log::info("[SendWhatsAppNotificationJob] Pesan {$notification->id} berstatus {$notification->status}. Pengiriman dilewati.");

            return;
        }

        if ($notification->attendance?->is_manual_override) {
            $notification->update([
                'status' => 'cancelled',
                'error_message' => 'Dibatalkan: presensi telah dikoreksi manual oleh operator.',
            ]);
            Log::info("[SendWhatsAppNotificationJob] Pesan {$notification->id} dibatalkan karena presensi dikoreksi manual.");

            return;
        }

        // Pengecekan guard wali (Consent & Validitas Nomor Fonnte)
        $guardian = $notification->guardian;
        if ($guardian) {
            if (! $guardian->consent_at) {
                $notification->update([
                    'status' => 'cancelled',
                    'error_message' => 'Wali belum menyetujui penerimaan notifikasi WhatsApp (Consent belum diberikan).',
                ]);
                Log::info("[SendWhatsAppNotificationJob] Pesan {$notification->id} dibatalkan karena wali belum memberikan consent.");

                return;
            }

            if ($guardian->wa_status === 'invalid') {
                $notification->update([
                    'status' => 'cancelled',
                    'error_message' => 'Nomor WhatsApp wali tidak aktif / tidak terdaftar di aplikasi WhatsApp (invalid). Pengiriman dibatalkan tanpa mengurangi kuota.',
                ]);
                Log::info("[SendWhatsAppNotificationJob] Pesan {$notification->id} dibatalkan karena nomor wali invalid.");

                return;
            }

            if ($guardian->wa_status !== 'valid') {
                $notification->update([
                    'status' => 'held',
                    'error_message' => 'Nomor WhatsApp wali belum berstatus valid (status saat ini: '.$guardian->wa_status.'). Pesan ditahan.',
                ]);
                Log::info("[SendWhatsAppNotificationJob] Pesan {$notification->id} ditahan karena nomor wali belum valid.");

                return;
            }
        }

        $notification->increment('attempts');

        $driver = WhatsAppManager::driver();
        $result = $driver->sendMessage($notification);

        if ($result['success']) {
            $notification->update([
                'status' => 'sent',
                'sent_at' => now(),
                'provider_message_id' => $result['message_id'],
                'error_message' => null,
            ]);

            Log::info("[SendWhatsAppNotificationJob] Notifikasi {$notification->id} berhasil diterima provider (sent). Menunggu konfirmasi delivery.");
        } else {
            $isLastAttempt = $this->attempts() >= $this->tries;

            $notification->update([
                'status' => $isLastAttempt ? 'failed' : 'queued',
                'error_message' => $result['error'],
            ]);

            Log::error("[SendWhatsAppNotificationJob] Gagal mengirim notifikasi {$notification->id} (Percobaan {$this->attempts()}/{$this->tries}): {$result['error']}");

            if (! $isLastAttempt) {
                // Lemparkan exception agar Laravel Queue melakukan retry sesuai jadwal backoff
                throw new \RuntimeException('WhatsApp Send Error: '.$result['error']);
            }
        }
    }

    /**
     * Handle job failure.
     */
    public function failed(\Throwable $exception): void
    {
        $notification = NotificationLog::find($this->notificationId);
        if ($notification) {
            $notification->update([
                'status' => 'failed',
                'error_message' => $exception->getMessage(),
            ]);
        }
    }
}
