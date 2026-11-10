<?php

namespace App\Services\WhatsApp;

use App\Models\NotificationLog;
use App\Models\WhatsAppTemplate;
use App\Support\WhatsAppHttp;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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

            $data = $response->json();
            $data = is_array($data) ? $data : [];

            // Fonnte membalas HTTP 200 meskipun pengiriman ditolak. Status sebenarnya
            // ada pada field status/Status; tanpa nilai true, pesan tidak pernah masuk
            // antrean provider dan tidak boleh dicatat sebagai "sent".
            if ($response->successful() && $this->isAcceptedByProvider($data)) {
                return [
                    'success' => true,
                    'message_id' => $this->extractMessageId($data),
                    'error' => null,
                    'raw' => $data,
                ];
            }

            $reason = $response->successful()
                ? $this->extractFailureReason($data)
                : "HTTP {$response->status()}: ".$response->body();

            Log::error('[FonnteDriver Error]', [
                'status' => $response->status(),
                'reason' => $reason,
                'body' => $data !== [] ? $data : $response->body(),
            ]);

            return [
                'success' => false,
                'message_id' => null,
                'error' => $reason,
                'raw' => $data,
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

    /**
     * @param  array<string, mixed>  $data
     */
    private function isAcceptedByProvider(array $data): bool
    {
        $status = $data['status'] ?? $data['Status'] ?? null;

        if ($status === true || $status === 1) {
            return true;
        }

        return is_string($status)
            && in_array(strtolower(trim($status)), ['true', '1', 'success', 'ok'], true);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function extractMessageId(array $data): ?string
    {
        $id = $data['id'] ?? null;

        if (is_array($id)) {
            $id = $id[0] ?? null;
        }

        if (! is_scalar($id) || trim((string) $id) === '') {
            return null;
        }

        return (string) $id;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function extractFailureReason(array $data): string
    {
        $reason = $data['reason'] ?? $data['detail'] ?? $data['message'] ?? null;

        if (! is_scalar($reason) || trim((string) $reason) === '') {
            return 'Fonnte menolak pengiriman tanpa alasan yang jelas.';
        }

        return Str::limit(trim((string) $reason), 300);
    }
}
