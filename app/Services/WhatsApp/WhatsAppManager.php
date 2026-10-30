<?php

namespace App\Services\WhatsApp;

class WhatsAppManager
{
    protected static ?WhatsAppGatewayInterface $driverInstance = null;

    /**
     * Dapatkan instance driver gateway WhatsApp aktif.
     */
    public static function driver(?string $driver = null): WhatsAppGatewayInterface
    {
        $driverName = $driver ?? config('whatsapp.default', 'log');

        return match ($driverName) {
            'meta' => new MetaCloudApiDriver,
            'fonnte' => new FonnteDriver,
            'log', 'mock' => new LogDriver,
            default => new LogDriver,
        };
    }
}
