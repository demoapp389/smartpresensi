<?php

namespace App\Services\WhatsApp;

use App\Models\NotificationLog;
use App\Models\WhatsAppTemplate;
use App\Support\WhatsAppHttp;
use Illuminate\Support\Facades\Log;

class FonnteDriver implements WhatsAppGatewayInterface
{
    protected string $token;

    protected string $url;

    protected string $schoolName;

    public function __construct()
    {
        $this->token = config('whatsapp.fonnte.token', '');
        $this->url = config('whatsapp.fonnte.url', 'https://api.fonnte.com/send');
        $this->schoolName = config('whatsapp.school_name', 'Sekolah');
    }

    public function sendMessage(NotificationLog $notification): array
    {
        if (empty($this->token)) {
            return [
                'success' => false,
                'message_id' => null,
                'error' => 'Token Fonnte belum dikonfigurasi di .env (WA_FONNTE_TOKEN).',
                'raw' => [],
            ];
        }

        $payloadData = $notification->payload ?? [];
        $eventType = $notification->event_type;

        // Jika broadcast sudah membawa pesan utuh terformat
        if ($eventType === 'broadcast' && ! empty($payloadData['full_message'])) {
            $message = $payloadData['full_message'];
        } else {
            $template = WhatsAppTemplate::getTemplate($eventType);

            $message = str_replace(
                [':nama_siswa', ':kelas', ':tanggal', ':waktu', ':status', ':nama_sekolah', ':nama_wali', ':pesan'],
                [
                    $payloadData['nama_siswa'] ?? 'Siswa',
                    $payloadData['kelas'] ?? '-',
                    $payloadData['tanggal'] ?? now()->format('d/m/Y'),
                    $payloadData['waktu'] ?? now()->format('H:i').' WIB',
                    ucfirst($payloadData['status'] ?? '-'),
                    $this->schoolName,
                    $payloadData['nama_wali'] ?? 'Bapak/Ibu',
                    $payloadData['pesan'] ?? '',
                ],
                $template
            );
        }

        return $this->sendDirectMessage($notification->recipient_phone, $message);
    }

    public function sendDirectMessage(string $phone, string $message): array
    {
        if (empty($this->token)) {
            return [
                'success' => false,
                'message_id' => null,
                'error' => 'Token Fonnte belum dikonfigurasi di .env.',
                'raw' => [],
            ];
        }

        try {
            $response = WhatsAppHttp::request()->withHeaders([
                'Authorization' => $this->token,
            ])->timeout(15)->post($this->url, [
                'target' => $phone,
                'message' => $message,
                'countryCode' => '62',
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $messageId = $data['id'][0] ?? ($data['process'] ?? null);

                return [
                    'success' => true,
                    'message_id' => (string) $messageId,
                    'error' => null,
                    'raw' => $data,
                ];
            }

            Log::error('[FonnteDriver Error]', ['status' => $response->status(), 'body' => $response->body()]);

            return [
                'success' => false,
                'message_id' => null,
                'error' => "HTTP {$response->status()}: ".$response->body(),
                'raw' => $response->json() ?? [],
            ];
        } catch (\Throwable $e) {
            Log::error('[FonnteDriver Exception]', ['message' => $e->getMessage()]);

            return [
                'success' => false,
                'message_id' => null,
                'error' => $e->getMessage(),
                'raw' => [],
            ];
        }
    }
}
