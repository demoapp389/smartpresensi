<?php

namespace App\Services\WhatsApp;

use App\Models\NotificationLog;
use App\Models\WhatsAppTemplate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class LogDriver implements WhatsAppGatewayInterface
{
    public function sendMessage(NotificationLog $notification): array
    {
        $payload = $notification->payload ?? [];
        $eventType = $notification->event_type;
        $schoolName = config('whatsapp.school_name', 'Sekolah');

        if ($eventType === 'broadcast' && ! empty($payload['full_message'])) {
            $body = $payload['full_message'];
        } else {
            $template = WhatsAppTemplate::getTemplate($eventType);

            $body = str_replace(
                [':nama_siswa', ':kelas', ':tanggal', ':waktu', ':status', ':nama_sekolah', ':nama_wali', ':pesan'],
                [
                    $payload['nama_siswa'] ?? 'Siswa',
                    $payload['kelas'] ?? '-',
                    $payload['tanggal'] ?? now()->format('d/m/Y'),
                    $payload['waktu'] ?? now()->format('H:i').' WIB',
                    ucfirst($payload['status'] ?? '-'),
                    $schoolName,
                    $payload['nama_wali'] ?? 'Bapak/Ibu',
                    $payload['pesan'] ?? '',
                ],
                $template
            );
        }

        $simulatedId = 'log_wamid_'.Str::random(24);

        Log::info('[WhatsApp LogDriver] Pesan Terkirim ke WhatsApp Wali:', [
            'idempotency_key' => $notification->idempotency_key,
            'recipient_phone' => $notification->recipient_phone,
            'message_id' => $simulatedId,
            'body' => $body,
        ]);

        return [
            'success' => true,
            'message_id' => $simulatedId,
            'error' => null,
            'raw' => [
                'driver' => 'log',
                'recipient' => $notification->recipient_phone,
                'body' => $body,
            ],
        ];
    }

    public function sendDirectMessage(string $phone, string $message): array
    {
        $simulatedId = 'log_wamid_'.Str::random(24);

        Log::info('[WhatsApp LogDriver] Pesan Langsung:', [
            'recipient' => $phone,
            'message_id' => $simulatedId,
            'body' => $message,
        ]);

        return [
            'success' => true,
            'message_id' => $simulatedId,
            'error' => null,
            'raw' => ['driver' => 'log', 'recipient' => $phone, 'message' => $message],
        ];
    }
}
