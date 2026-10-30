<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NotificationLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

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
                            if ($notification) {
                                $notification->update([
                                    'status' => $statusName,
                                    'error_message' => $st['errors'][0]['message'] ?? $notification->error_message,
                                ]);
                                Log::info("[WhatsApp Webhook] Status pesan {$notification->id} diperbarui menjadi '{$statusName}'");
                            }
                        }
                    }
                }
            }
        }

        // 2. Fonnte / Generic Webhook Payload
        if (isset($payload['id']) && isset($payload['status'])) {
            $providerMessageId = (string) $payload['id'];
            $statusName = strtolower($payload['status']);

            $notification = NotificationLog::where('provider_message_id', $providerMessageId)->first();
            if ($notification) {
                $mappedStatus = match ($statusName) {
                    'sent' => 'sent',
                    'delivered' => 'delivered',
                    'read' => 'read',
                    'failed', 'reject' => 'failed',
                    default => $statusName,
                };
                $notification->update(['status' => $mappedStatus]);
            }
        }

        return response()->json(['status' => 'success'], 200);
    }

    /**
     * Verifikasi asal permintaan webhook (SEC-04).
     *
     * Menerima tanda tangan Meta (X-Hub-Signature-256) bila app secret diisi,
     * atau rahasia bersama untuk gateway generik (Fonnte):
     * header X-Webhook-Token/X-Webhook-Secret, query ?token=, atau field payload.
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
            $candidates = [
                $request->header('X-Webhook-Token'),
                $request->header('X-Webhook-Secret'),
                $request->query('token'),
                $request->input('secret'),
                $request->input('secret_key'),
                $request->input('webhook_secret'),
                $request->input('token'),
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
