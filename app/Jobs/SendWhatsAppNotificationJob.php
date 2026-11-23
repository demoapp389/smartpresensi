<?php

namespace App\Jobs;

use App\Models\NotificationLog;
use App\Models\TaskProgress;
use App\Services\WhatsApp\WhatsAppManager;
use App\Services\WhatsApp\WhatsAppSendPacer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Job antrean untuk mengirim notifikasi WhatsApp secara asinkron (PRD FR-12, FR-13, FR-14).
 * Mendukung retry otomatis dengan exponential backoff dan penandaan status permanen jika gagal.
 */
class SendWhatsAppNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 90;

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
    public function handle(?WhatsAppSendPacer $pacer = null): void
    {
        $pacer ??= app(WhatsAppSendPacer::class);

        $lock = Cache::lock('whatsapp-notification:'.$this->notificationId, 120);

        if (! $lock->get()) {
            Log::info("[SendWhatsAppNotificationJob] Pesan {$this->notificationId} sedang diproses oleh worker lain.");

            // Jangan biarkan job dianggap sukses saat lock gagal; kembalikan ke antrean
            // agar tidak berhenti permanen di status "queued".
            if ($this->job !== null) {
                $this->release(10);
            }

            return;
        }

        try {
            $this->sendNotification($pacer);
        } finally {
            $lock->release();
        }
    }

    private function sendNotification(WhatsAppSendPacer $pacer): void
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
            $this->finalize($notification, [
                'status' => 'cancelled',
                'error_message' => 'Dibatalkan: presensi telah dikoreksi manual oleh operator.',
            ]);
            Log::info("[SendWhatsAppNotificationJob] Pesan {$notification->id} dibatalkan karena presensi dikoreksi manual.");

            return;
        }

        // Pengecekan guard wali (Consent & Validitas Nomor Fonnte)
        $guardian = $notification->guardian;
        if ($guardian) {
            // Siaran pengumuman dikirim atas konfirmasi eksplisit operator, sehingga
            // tidak mewajibkan consent otomatis seperti notifikasi absensi.
            if (! $guardian->consent_at && $notification->event_type !== 'broadcast') {
                $this->finalize($notification, [
                    'status' => 'cancelled',
                    'error_message' => 'Wali belum menyetujui penerimaan notifikasi WhatsApp (Consent belum diberikan).',
                ]);
                Log::info("[SendWhatsAppNotificationJob] Pesan {$notification->id} dibatalkan karena wali belum memberikan consent.");

                return;
            }

            if ($guardian->wa_status === 'invalid') {
                $this->finalize($notification, [
                    'status' => 'cancelled',
                    'error_message' => 'Nomor WhatsApp wali tidak aktif / tidak terdaftar di aplikasi WhatsApp (invalid). Pengiriman dibatalkan tanpa mengurangi kuota.',
                ]);
                Log::info("[SendWhatsAppNotificationJob] Pesan {$notification->id} dibatalkan karena nomor wali invalid.");

                return;
            }

            if ($guardian->wa_status !== 'valid') {
                $this->finalize($notification, [
                    'status' => 'held',
                    'error_message' => 'Nomor WhatsApp wali belum berstatus valid (status saat ini: '.$guardian->wa_status.'). Pesan ditahan.',
                ]);
                Log::info("[SendWhatsAppNotificationJob] Pesan {$notification->id} ditahan karena nomor wali belum valid.");

                return;
            }
        }

        $notification->increment('attempts');

        $driver = WhatsAppManager::driver();

        // Jeda aman lintas worker: nomor pengirim tidak diblokir karena burst.
        $result = $pacer->send(fn (): array => $driver->sendMessage($notification));

        if ($result['success']) {
            // Webhook bisa lebih dulu menulis delivered/read; jangan turunkan.
            $this->finalize($notification, [
                'status' => 'sent',
                'sent_at' => now(),
                'provider_message_id' => $result['message_id'],
                'error_message' => null,
            ]);

            Log::info("[SendWhatsAppNotificationJob] Notifikasi {$notification->id} berhasil diterima provider (sent). Menunggu konfirmasi delivery.");
        } else {
            $isLastAttempt = $this->attempts() >= $this->tries;

            if ($isLastAttempt) {
                $this->finalize($notification, [
                    'status' => 'failed',
                    'error_message' => $result['error'],
                ]);
            } else {
                $this->updateUnlessFinal($notification, [
                    'status' => 'queued',
                    'error_message' => $result['error'],
                ]);
            }

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
            // Jangan menimpa status akhir (mis. webhook sudah menulis delivered
            // sebelum job melaporkan timeout).
            $this->finalize($notification, [
                'status' => 'failed',
                'error_message' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Tulis status terminal lalu perbarui progres siaran bila pesan bagian dari siaran.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function finalize(NotificationLog $notification, array $attributes): bool
    {
        return DB::transaction(function () use ($notification, $attributes): bool {
            if (! $this->updateUnlessFinal($notification, $attributes)) {
                return false;
            }

            $this->advanceBroadcastProgress($notification);

            return true;
        });
    }

    /**
     * Perbarui progres siaran dan tutup task saat seluruh pesan sudah selesai.
     *
     * Progres dihitung ulang dari status baris outbox (bukan counter naik) agar
     * pengiriman ulang pesan yang sama tidak membuat progres melebihi total.
     */
    private function advanceBroadcastProgress(NotificationLog $notification): void
    {
        $taskUuid = $notification->payload['task_uuid'] ?? null;

        if (! is_string($taskUuid) || $taskUuid === '') {
            return;
        }

        $task = TaskProgress::where('uuid', $taskUuid)->first();

        if (! $task || $task->isFinished()) {
            return;
        }

        $baseQuery = NotificationLog::query()
            ->where('event_type', 'broadcast')
            ->where('idempotency_key', 'like', 'BROADCAST_'.$taskUuid.'_%');

        $settled = (clone $baseQuery)
            ->whereIn('status', ['sent', 'delivered', 'read', 'failed', 'cancelled', 'held'])
            ->count();

        TaskProgress::whereKey($task->id)
            ->where('status', TaskProgress::STATUS_RUNNING)
            ->update(['processed' => $settled]);

        if ($settled < $task->total) {
            return;
        }

        $sent = (clone $baseQuery)->whereIn('status', ['sent', 'delivered', 'read'])->count();
        $failed = (clone $baseQuery)->where('status', 'failed')->count();

        $task->update([
            'status' => TaskProgress::STATUS_COMPLETED,
            'finished_at' => now(),
            'results' => [
                'total' => $task->total,
                'sent' => $sent,
                'failed' => $failed,
            ],
            'message' => "Selesai. {$sent} pesan terkirim".($failed > 0 ? ", {$failed} gagal." : '.'),
        ]);
    }

    /**
     * Update notifikasi hanya bila statusnya belum final.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function updateUnlessFinal(NotificationLog $notification, array $attributes): bool
    {
        return NotificationLog::query()
            ->whereKey($notification->id)
            ->whereNotIn('status', NotificationLog::FINAL_STATUSES)
            ->update($attributes) > 0;
    }
}
