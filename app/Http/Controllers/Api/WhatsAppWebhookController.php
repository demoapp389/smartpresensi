<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NotificationLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Controller untuk menangani Webhook WhatsApp (Meta Cloud API & Gateway) (PRD FR-14).
 * Mendukung verifikasi token Meta dan penerimaan status tanda terima (sent, delivered, read, failed).
 */
class WhatsAppWebhookController extends Controller
{
    /**
     * Verifikasi Webhook Meta WhatsApp Cloud API (GET).
     */
    public function verify(Request $request): Response|JsonResponse
    {
        $mode = $request->query('hub_mode');
        $token = (string) $request->query('hub_verify_token', '');
        $challenge = $request->query('hub_challenge');

        $expectedToken = (string) config('whatsapp.meta.webhook_verify_token', '');

        // Fail-closed: tanpa token terkonfigurasi, verifikasi selalu ditolak.
        if ($expectedToken !== '' && $mode === 'subscribe' && hash_equals($expectedToken, $token)) {
            return response($challenge, 200)->header('Content-Type', 'text/plain');
        }

        return response()->json(['error' => 'Verification token mismatch.'], 403);
    }

    /**
     * Menerima event webhook status pengiriman pesan (POST).
     */
    public function handle(Request $request): JsonResponse
    {
        if (! $this->passesOriginCheck($request)) {
            return response()->json(['error' => 'Webhook authentication failed.'], 403);
        }

        $payload = $request->all();
        Log::info('[WhatsApp Webhook Event Received]', $this->redactSecrets($payload));

        // 1. Meta Cloud API Payload
        if (isset($payload['entry'])) {
            foreach ($payload['entry'] as $entry) {
                foreach ($entry['changes'] ?? [] as $change) {
                    $value = $change['value'] ?? [];
                    $statuses = $value['statuses'] ?? [];

                    foreach ($statuses as $st) {
                        $providerMessageId = $st['id'] ?? null;
                        $statusName = $st['status'] ?? null; // sent, delivered, read, failed

                        if ($providerMessageId && $statusName) {
                            $notification = NotificationLog::where('provider_message_id', $providerMessageId)->first();
                            if ($notification && $this->applyStatus(
                                $notification,
                                (string) $statusName,
                                $st['errors'][0]['message'] ?? null,
                            )) {
                                Log::info("[WhatsApp Webhook] Status pesan {$notification->id} diperbarui menjadi '{$notification->status}'");
                            }
                        }
                    }
                }
            }
        }

        // 2. Fonnte / Generic Webhook Payload
        if (isset($payload['id']) && isset($payload['status'])) {
            $rawId = $payload['id'];
            $providerMessageId = is_array($rawId) ? (string) ($rawId[0] ?? '') : (string) $rawId;

            $notification = NotificationLog::where('provider_message_id', $providerMessageId)->first();
            if ($notification && $this->applyStatus(
                $notification,
                (string) $payload['status'],
                $payload['reason'] ?? $payload['detail'] ?? null,
            )) {
                Log::info("[WhatsApp Webhook] Status pesan {$notification->id} diperbarui menjadi '{$notification->status}'");
            }
        }

        return response()->json(['status' => 'success'], 200);
    }

    /**
     * Terapkan status webhook tanpa pernah memundurkan status yang sudah lebih tinggi.
     */
    private function applyStatus(NotificationLog $notification, string $statusName, ?string $errorMessage = null): bool
    {
        $mappedStatus = match (strtolower(trim($statusName))) {
            'queued', 'pending', 'processing' => 'queued',
            'sent', 'success' => 'sent',
            'delivered' => 'delivered',
            'read' => 'read',
            'failed', 'reject', 'rejected', 'error' => 'failed',
            default => null,
        };

        if ($mappedStatus === null) {
            Log::warning('[WhatsApp Webhook] Status tidak dikenal diabaikan.', [
                'notification_id' => $notification->id,
                'status' => $statusName,
            ]);

            return false;
        }

        $currentStatus = strtolower(trim((string) $notification->status));

        if ($currentStatus === 'cancelled') {
            return false;
        }

        $currentRank = $this->statusRank($currentStatus);
        $newRank = $this->statusRank($mappedStatus);

        // Kegagalan hanya boleh menimpa status sebelum pesan terkirim/diterima.
        if ($mappedStatus === 'failed') {
            if ($currentRank >= $this->statusRank('delivered')) {
                return false;
            }
        } elseif ($newRank <= $currentRank && $currentRank >= 0) {
            return false;
        }

        $attributes = ['status' => $mappedStatus];

        if ($mappedStatus === 'sent' && $notification->sent_at === null) {
            $attributes['sent_at'] = now();
        }

        if (is_string($errorMessage) && trim($errorMessage) !== '') {
            $attributes['error_message'] = Str::limit(trim($errorMessage), 500);
        } elseif (in_array($mappedStatus, ['sent', 'delivered', 'read'], true)) {
            $attributes['error_message'] = null;
        }

        $notification->update($attributes);

        return true;
    }

    private function statusRank(string $status): int
    {
        return match (strtolower(trim($status))) {
            'queued', 'held' => 0,
            'sent' => 1,
            'delivered' => 2,
            'read' => 3,
            'failed' => -1,
            'cancelled' => -3,
            default => -2,
        };
    }

    /**
     * Verifikasi asal permintaan webhook (SEC-04).
     *
     * Menerima tanda tangan Meta (X-Hub-Signature-256) bila app secret diisi,
     * atau rahasia bersama untuk gateway generik (Fonnte) lewat header
     * X-Webhook-Token/X-Webhook-Secret atau field payload. Query string sengaja
     * tidak diterima karena rahasia di URL mudah bocor ke access log.
     * Fail-closed: bila tidak ada rahasia terkonfigurasi, semua permintaan ditolak.
     */
    private function passesOriginCheck(Request $request): bool
    {
        $appSecret = (string) config('whatsapp.meta.app_secret', '');
        $sharedSecret = (string) config('whatsapp.webhook_secret', '');

        if ($appSecret === '' && $sharedSecret === '') {
            Log::error('[WhatsApp Webhook] Tidak ada app secret maupun webhook secret yang dikonfigurasi — permintaan ditolak.');

            return false;
        }

        if ($appSecret !== '') {
            $signature = (string) $request->header('X-Hub-Signature-256', '');
            $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $appSecret);

            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        if ($sharedSecret !== '') {
            // Baca hanya body request (bukan query string) agar rahasia di URL
            // tidak pernah dianggap kredensial yang sah.
            $body = $request->isJson() ? $request->json()->all() : $request->request->all();

            $candidates = [
                $request->header('X-Webhook-Token'),
                $request->header('X-Webhook-Secret'),
                $body['secret'] ?? null,
                $body['secret_key'] ?? null,
                $body['webhook_secret'] ?? null,
                $body['token'] ?? null,
            ];

            foreach ($candidates as $provided) {
                if (is_string($provided) && $provided !== '' && hash_equals($sharedSecret, $provided)) {
                    return true;
                }
            }
        }

        Log::warning('[WhatsApp Webhook] Kredensial webhook tidak valid — permintaan ditolak.');

        return false;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function redactSecrets(array $payload): array
    {
        foreach (['secret', 'secret_key', 'token', 'webhook_secret'] as $key) {
            if (array_key_exists($key, $payload)) {
                $payload[$key] = '[REDACTED]';
            }
        }

        return $payload;
    }
}
