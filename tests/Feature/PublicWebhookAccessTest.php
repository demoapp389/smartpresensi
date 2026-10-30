<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicWebhookAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('whatsapp.meta.app_secret', '');
        config()->set('whatsapp.meta.webhook_verify_token', '');
        config()->set('whatsapp.webhook_public_only', true);
        config()->set('whatsapp.webhook_secret', 'shared-pass');
    }

    public function test_cloudflare_request_to_non_webhook_route_returns_404(): void
    {
        $this->withHeader('CF-Ray', 'test-ray')->get('/login')->assertNotFound();
    }

    public function test_cloudflare_request_to_health_check_is_allowed(): void
    {
        $this->withHeader('CF-Ray', 'test-ray')->get('/up')->assertOk();
    }

    public function test_cloudflare_webhook_request_with_valid_secret_returns_success(): void
    {
        $response = $this->withHeader('CF-Ray', 'test-ray')->postJson(
            route('api.whatsapp.webhook.handle'),
            [
                'id' => 'unknown-message-id',
                'status' => 'delivered',
                'webhook_secret' => 'shared-pass',
            ],
        );

        $response->assertOk()->assertJson(['status' => 'success']);
    }

    public function test_cloudflare_webhook_request_without_valid_secret_returns_403(): void
    {
        $this->withHeader('CF-Ray', 'test-ray')
            ->postJson(route('api.whatsapp.webhook.handle'), [
                'id' => 'unknown-message-id',
                'status' => 'delivered',
            ])
            ->assertForbidden();
    }

    public function test_local_request_to_non_webhook_route_remains_available(): void
    {
        $this->get('/login')->assertOk();
    }

    public function test_public_only_guard_can_be_disabled(): void
    {
        config()->set('whatsapp.webhook_public_only', false);

        $this->withHeader('CF-Ray', 'test-ray')->get('/login')->assertOk();
    }
}
