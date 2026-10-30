<?php

namespace App\Support;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * HTTP client untuk API WhatsApp dengan CA bundle yang pasti tersedia.
 *
 * Runtime PHP Windows hasil bundle tidak selalu mengisi curl.cainfo. Bila itu
 * terjadi, gunakan CA bawaan aplikasi agar verifikasi TLS tetap aktif.
 */
final class WhatsAppHttp
{
    public static function request(): PendingRequest
    {
        $caBundle = self::caBundle();

        return Http::withOptions($caBundle === null ? [] : ['verify' => $caBundle]);
    }

    private static function caBundle(): ?string
    {
        $configured = ini_get('curl.cainfo');

        if (is_string($configured) && $configured !== '' && is_file($configured)) {
            return $configured;
        }

        $bundled = resource_path('certificates/cacert.pem');

        return is_file($bundled) ? $bundled : null;
    }
}
