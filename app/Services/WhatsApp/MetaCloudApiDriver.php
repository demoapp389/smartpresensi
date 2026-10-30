<?php

namespace App\Services\WhatsApp;

use App\Models\NotificationLog;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MetaCloudApiDriver implements WhatsAppGatewayInterface
{
    protected string $version;

    protected string $phoneNumberId;

    protected string $accessToken;

    protected string $schoolName;

    public function __construct()
    {
        $this->version = config('whatsapp.meta.version', 'v20.0');
        $this->phoneNumberId = config('whatsapp.meta.phone_number_id', '');
        $this->accessToken = config('whatsapp.meta.access_token', '');
        $this->schoolName = config('whatsapp.school_name', 'Sekolah');
    }

    public function sendMessage(NotificationLog $notification): array
    {
        if (empty($this->phoneNumberId) || empty($this->accessToken)) {
            return [
                'success' => false,
                'message_id' => null,
                'error' => 'Kredensial Meta WhatsApp Cloud API belum dikonfigurasi di .env (WA_META_PHONE_NUMBER_ID / WA_META_ACCESS_TOKEN).',
                'raw' => [],
            ];
        }

        $url = "https://graph.facebook.com/{$this->version}/{$this->phoneNumberId}/messages";
        $recipient = $this->formatPhoneNumber($notification->recipient_phone);

        $payloadData = $notification->payload ?? [];
        $eventType = $notification->event_type;
        $template = config("whatsapp.templates.{$eventType}", config('whatsapp.templates.default'));

        $bodyText = str_replace(
            [':nama_siswa', ':kelas', ':tanggal', ':waktu', ':status', ':nama_sekolah'],
            [
                $payloadData['nama_siswa'] ?? 'Siswa',
                $payloadData['kelas'] ?? '-',
                $payloadData['tanggal'] ?? now()->format('d/m/Y'),
                $payloadData['waktu'] ?? now()->format('H:i').' WIB',
                ucfirst($payloadData['status'] ?? '-'),
                $this->schoolName,
            ],
            $template
        );

        $body = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $recipient,
            'type' => 'text',
            'text' => [
                'preview_url' => false,
                'body' => $bodyText,
            ],
        ];

        try {
            $response = Http::withToken($this->accessToken)
                ->timeout(15)
                ->post($url, $body);

            if ($response->successful()) {
                $data = $response->json();
                $messageId = $data['messages'][0]['id'] ?? null;

                return [
                    'success' => true,
                    'message_id' => $messageId,
                    'error' => null,
                    'raw' => $data,
                ];
            }

            $errorMsg = $response->json('error.message') ?? $response->body();
            Log::error('[MetaCloudApiDriver Error]', ['status' => $response->status(), 'error' => $errorMsg]);

            return [
                'success' => false,
                'message_id' => null,
                'error' => "HTTP {$response->status()}: {$errorMsg}",
                'raw' => $response->json() ?? [],
            ];
        } catch (\Throwable $e) {
            Log::error('[MetaCloudApiDriver Exception]', ['message' => $e->getMessage()]);

            return [
                'success' => false,
                'message_id' => null,
                'error' => $e->getMessage(),
                'raw' => [],
            ];
        }
    }

    public function sendDirectMessage(string $phone, string $message): array
    {
        if (empty($this->phoneNumberId) || empty($this->accessToken)) {
            return [
                'success' => false,
                'message_id' => null,
                'error' => 'Kredensial Meta WhatsApp Cloud API belum dikonfigurasi.',
                'raw' => [],
            ];
        }

        $url = "https://graph.facebook.com/{$this->version}/{$this->phoneNumberId}/messages";
        $recipient = $this->formatPhoneNumber($phone);

        $body = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $recipient,
            'type' => 'text',
            'text' => [
                'preview_url' => false,
                'body' => $message,
            ],
        ];

        try {
            $response = Http::withToken($this->accessToken)->timeout(15)->post($url, $body);

            if ($response->successful()) {
                $data = $response->json();

                return [
                    'success' => true,
                    'message_id' => $data['messages'][0]['id'] ?? null,
                    'error' => null,
                    'raw' => $data,
                ];
            }

            return [
                'success' => false,
                'message_id' => null,
                'error' => $response->json('error.message') ?? $response->body(),
                'raw' => $response->json() ?? [],
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message_id' => null,
                'error' => $e->getMessage(),
                'raw' => [],
            ];
        }
    }

    protected function formatPhoneNumber(string $phone): string
    {
        $clean = preg_replace('/[^0-9]/', '', $phone);
        if (str_starts_with($clean, '0')) {
            $clean = '62'.substr($clean, 1);
        }

        return $clean;
    }
}
