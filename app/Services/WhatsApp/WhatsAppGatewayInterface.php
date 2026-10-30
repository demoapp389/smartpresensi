<?php

namespace App\Services\WhatsApp;

use App\Models\NotificationLog;

interface WhatsAppGatewayInterface
{
    /**
     * Kirim notifikasi berdasarkan record NotificationLog.
     *
     * @return array ['success' => bool, 'message_id' => ?string, 'error' => ?string, 'raw' => array]
     */
    public function sendMessage(NotificationLog $notification): array;

    /**
     * Kirim pesan teks langsung (misal untuk pengujian konektivitas).
     *
     * @return array ['success' => bool, 'message_id' => ?string, 'error' => ?string, 'raw' => array]
     */
    public function sendDirectMessage(string $phone, string $message): array;
}
