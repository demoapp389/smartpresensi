<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WhatsAppGatewaySettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_gateway_renders_public_webhook_url_and_access_status(): void
    {
        $admin = User::factory()->admin()->create();

        config()->set('whatsapp.webhook_base_url', 'https://wa.example.test/');
        config()->set('whatsapp.webhook_public_only', true);
        config()->set('whatsapp.webhook_secret', 'shared-pass');

        $response = $this->actingAs($admin)->get(route('notifications.gateway'));

        $response->assertOk();
        $response->assertSee('Endpoint Webhook Publik');
        $response->assertSee('https://wa.example.test/api/v1/whatsapp/webhook');
        $response->assertSee('Salin URL');
        $response->assertSee('Hanya webhook');
        $response->assertSee('Terkonfigurasi');
        $response->assertDontSee('shared-pass');
    }

    public function test_gateway_falls_back_to_local_webhook_url_when_base_url_is_empty(): void
    {
        $admin = User::factory()->admin()->create();

        config()->set('whatsapp.webhook_base_url', '');
        config()->set('whatsapp.webhook_public_only', false);

        $response = $this->actingAs($admin)->get(route('notifications.gateway'));

        $response->assertOk();
        $response->assertSee(route('api.whatsapp.webhook.handle'));
        $response->assertSee('Lokal');
        $response->assertSee('WA_WEBHOOK_BASE_URL');
    }
}
