<?php

namespace App\Http\Controllers;

use App\Concerns\LogsAuditTrail;
use App\Http\Controllers\Concerns\HasHybridFilters;
use App\Jobs\SendWhatsAppNotificationJob;
use App\Models\NotificationLog;
use App\Services\WhatsApp\FonnteNumberValidationService;
use App\Services\WhatsApp\WhatsAppManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class NotificationLogController extends Controller
{
    use HasHybridFilters;
    use LogsAuditTrail;

    protected function getFilterConfig(): array
    {
        return [
            'status' => [
                'type' => 'select',
                'column' => 'status',
                'operator' => '=',
            ],
            'event_type' => [
                'type' => 'select',
                'column' => 'event_type',
                'operator' => '=',
            ],
        ];
    }

    public function index(Request $request): View
    {
        $status = $request->input('status');
        $eventType = $request->input('event_type');
        $search = $request->input('search');

        $query = NotificationLog::with(['attendance.student.schoolClass', 'guardian'])
            ->latest('id');

        $query = $this->applyFilters($query, $request);

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('recipient_phone', 'like', "%{$search}%")
                    ->orWhere('idempotency_key', 'like', "%{$search}%")
                    ->orWhereHas('guardian', function ($g) use ($search) {
                        $g->where('name', 'like', "%{$search}%");
                    })
                    ->orWhereHas('attendance.student', function ($s) use ($search) {
                        $s->where('name', 'like', "%{$search}%");
                    });
            });
        }

        // Data dikirim penuh (tanpa paginate): tabel memakai DataTables di
        // browser. Filter status/event/pencarian tetap dijalankan di server,
        // sedangkan paginasi & pengurutan ditangani DataTables di sisi klien.
        $notifications = $query->get();

        // Statistik ringkas dihitung dalam satu query agregat per status (PERF-06/DUP-03).
        $statusCounts = NotificationLog::statusCounts();

        $stats = [
            'total' => (int) $statusCounts->sum(),
            'queued' => (int) $statusCounts->get('queued', 0),
            'sent' => (int) $statusCounts->get('sent', 0),
            'delivered' => (int) $statusCounts->get('delivered', 0),
            'read' => (int) $statusCounts->get('read', 0),
            'failed' => (int) $statusCounts->get('failed', 0),
        ];

        $activeProvider = config('whatsapp.default', 'log');

        return view('notifications.index', compact('notifications', 'stats', 'status', 'eventType', 'search', 'activeProvider'));
    }

    /**
     * Halaman Pengaturan Gateway WhatsApp (read-only + uji kirim pesan).
     *
     * Konfigurasi gateway dibaca dari file konfigurasi (berasal dari .env) dan
     * tidak dapat diubah dari UI. Token rahasia ditampilkan dalam bentuk
     * tersamar dan hanya dibuka lewat aksi eksplisit pengguna.
     */
    public function gateway(): View
    {
        $webhookBaseUrl = rtrim((string) config('whatsapp.webhook_base_url'), '/');
        $webhookUrl = $webhookBaseUrl !== ''
            ? $webhookBaseUrl.'/api/v1/whatsapp/webhook'
            : route('api.whatsapp.webhook.handle');

        $gateway = [
            'provider' => config('whatsapp.default'),
            'school_name' => config('whatsapp.school_name'),
            'retry_limit' => config('whatsapp.retry_limit'),
            'meta_version' => config('whatsapp.meta.version'),
            'fonnte_url' => config('whatsapp.fonnte.url'),
            'fonnte_validate_url' => config('whatsapp.fonnte.validate_url'),
            'validation' => config('whatsapp.validation'),
            'revalidation' => config('whatsapp.revalidation'),
            'webhook_url' => $webhookUrl,
            'webhook_url_is_public' => $webhookBaseUrl !== '',
            'webhook_url_is_https' => str_starts_with($webhookUrl, 'https://'),
            'webhook_public_only' => (bool) config('whatsapp.webhook_public_only'),
            'webhook_secret_configured' => (string) config('whatsapp.webhook_secret', '') !== '',
        ];

        return view('settings.whatsapp-gateway', compact('gateway'));
    }

    /**
     * Memproses ulang seluruh pesan WhatsApp yang berstatus 'failed'.
     */
    public function retryAllFailed(Request $request): RedirectResponse
    {
        $failedLogs = NotificationLog::where('status', 'failed')->get();

        if ($failedLogs->isEmpty()) {
            return back()->with('info', 'Tidak ada pesan gagal yang perlu dikirim ulang.');
        }

        $count = $failedLogs->count();
        $ids = $failedLogs->pluck('id')->all();

        DB::transaction(function () use ($ids, $count) {
            NotificationLog::whereIn('id', $ids)->update([
                'status' => 'queued',
                'error_message' => null,
                'queued_at' => now(),
            ]);

            $this->logAudit('retry_all_failed_whatsapp_notifications', 'NotificationLog', null, ['retried_count' => $count], ['status' => 'queued', 'retried_ids' => $ids]);
        });

        foreach ($ids as $id) {
            SendWhatsAppNotificationJob::dispatch($id);
        }

        return back()->with('success', "Sebanyak {$count} pesan gagal telah dimasukkan kembali ke antrean pengiriman WhatsApp.");
    }

    /**
     * Memproses ulang sejumlah pesan WhatsApp terpilih.
     */
    public function bulkRetry(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:notification_logs,id'],
        ]);

        $logs = NotificationLog::whereIn('id', $validated['ids'])
            ->whereIn('status', ['failed', 'queued'])
            ->get();

        if ($logs->isEmpty()) {
            return back()->with('error', 'Pesan terpilih sudah terkirim, diterima, atau dibaca sehingga tidak dapat dikirim ulang.');
        }

        $ids = $logs->pluck('id')->all();
        $count = count($ids);

        DB::transaction(function () use ($ids, $count) {
            NotificationLog::whereIn('id', $ids)->update([
                'status' => 'queued',
                'error_message' => null,
                'queued_at' => now(),
            ]);

            $this->logAudit('bulk_retry_whatsapp_notifications', 'NotificationLog', null, ['retried_count' => $count], ['status' => 'queued', 'retried_ids' => $ids]);
        });

        foreach ($ids as $id) {
            SendWhatsAppNotificationJob::dispatch($id);
        }

        return back()->with('success', "Sebanyak {$count} pesan telah dimasukkan kembali ke antrean pengiriman WhatsApp.");
    }

    /**
     * Menghapus log notifikasi terpilih (khusus log gagal atau queued yang dibatalkan).
     */
    public function bulkDestroy(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:notification_logs,id'],
        ]);

        $logs = NotificationLog::whereIn('id', $validated['ids'])->get();

        $ids = $logs->pluck('id')->all();
        $count = count($ids);

        DB::transaction(function () use ($ids, $count) {
            NotificationLog::whereIn('id', $ids)->delete();

            $this->logAudit('bulk_delete_whatsapp_notifications', 'NotificationLog', null, ['deleted_ids' => $ids], ['deleted_count' => $count]);
        });

        return back()->with('success', "Sebanyak {$count} catatan riwayat notifikasi berhasil dihapus.");
    }

    /**
     * Memproses ulang pengiriman pesan WhatsApp yang gagal atau pending (PRD FR-15).
     */
    public function retry(Request $request, NotificationLog $notification): RedirectResponse
    {
        if (in_array($notification->status, ['sent', 'delivered', 'read'])) {
            return back()->with('error', 'Pesan ini sudah terkirim, diterima, atau dibaca sehingga tidak dapat dikirim ulang.');
        }

        $before = [
            'status' => $notification->status,
            'attempts' => $notification->attempts,
            'error_message' => $notification->error_message,
        ];

        $notification->update([
            'status' => 'queued',
            'error_message' => null,
            'queued_at' => now(),
        ]);

        // Dispatch ulang ke antrean
        SendWhatsAppNotificationJob::dispatch($notification->id);

        $this->logAudit('retry_whatsapp_notification', 'NotificationLog', $notification->id, $before, [
            'status' => 'queued',
            'action' => 'dispatched_to_queue',
        ]);

        return back()->with('success', "Pesan ke +{$notification->recipient_phone} telah dimasukkan kembali ke antrean pengiriman WhatsApp.");
    }

    /**
     * Kirim pesan uji coba WhatsApp untuk verifikasi konektivitas gateway.
     */
    public function sendTest(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'test_phone' => ['required', 'string', 'min:8', 'max:20'],
            'test_message' => ['required', 'string', 'max:500'],
        ]);

        // Validasi nomor WhatsApp terlebih dahulu sebelum mengirim
        $validator = app(FonnteNumberValidationService::class);
        $validation = $validator->validateSingle($validated['test_phone']);

        if ($validation['status'] === 'invalid') {
            return back()->with('error', "Nomor {$validated['test_phone']} tidak valid: {$validation['error']} Pesan tidak dikirim untuk menghemat kuota.");
        }

        $driver = WhatsAppManager::driver();
        $result = $driver->sendDirectMessage($validation['normalized'], $validated['test_message']);

        if ($result['success']) {
            $statusLabel = $validation['status'] === 'valid' ? '✅ Tervalidasi' : '⚠️ Belum tervalidasi';

            return back()->with('success', "Pesan uji coba berhasil dikirim ke {$validation['normalized']} ({$statusLabel}) via driver '".config('whatsapp.default')."' (ID: ".($result['message_id'] ?? '-').').');
        }

        return back()->with('error', "Gagal mengirim pesan uji coba ke {$validation['normalized']}: ".($result['error'] ?? 'Unknown error'));
    }
}
