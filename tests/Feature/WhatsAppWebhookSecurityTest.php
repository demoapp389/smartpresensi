<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class WhatsAppWebhookSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Pastikan tidak ada rahasia yang bocor dari environment.
        config()->set('whatsapp.meta.app_secret', '');
        config()->set('whatsapp.webhook_secret', '');
        config()->set('whatsapp.meta.webhook_verify_token', '');
    }

    public function test_verify_rejects_when_token_not_configured(): void
    {
        $this->getJson(route('api.whatsapp.webhook.verify', [
            'hub_mode' => 'subscribe',
            'hub_verify_token' => 'absensi_secret_verify_token',
            'hub_challenge' => 'x',
        ]))->assertStatus(403);
    }

    public function test_handle_rejects_invalid_meta_signature_when_app_secret_set(): void
    {
        config()->set('whatsapp.meta.app_secret', 'secret123');

        $this->postJson(route('api.whatsapp.webhook.handle'), ['id' => '1', 'status' => 'delivered'], [
            'X-Hub-Signature-256' => 'sha256=deadbeef',
        ])->assertStatus(403);
    }

    public function test_handle_accepts_valid_meta_signature(): void
    {
        config()->set('whatsapp.meta.app_secret', 'secret123');

        $body = json_encode(['id' => 'none', 'status' => 'delivered']);
        $signature = 'sha256='.hash_hmac('sha256', $body, 'secret123');

        $this->call(
            'POST',
            route('api.whatsapp.webhook.handle'),
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => $signature],
            $body
        )->assertStatus(200);
    }

    public function test_handle_rejects_wrong_shared_token_when_webhook_secret_set(): void
    {
        config()->set('whatsapp.webhook_secret', 'shared-pass');

        $this->postJson(route('api.whatsapp.webhook.handle'), ['id' => '1', 'status' => 'delivered'])
            ->assertStatus(403);

        $this->postJson(route('api.whatsapp.webhook.handle'), ['id' => '1', 'status' => 'delivered'], [
            'X-Webhook-Token' => 'shared-pass',
        ])->assertStatus(200);
    }

    public function test_handle_rejects_when_no_secret_is_configured(): void
    {
        $this->postJson(route('api.whatsapp.webhook.handle'), ['id' => 'unknown-id', 'status' => 'delivered'])
            ->assertStatus(403);
    }

    public function test_handle_accepts_shared_token_when_meta_secret_is_also_configured(): void
    {
        config()->set('whatsapp.meta.app_secret', 'secret123');
        config()->set('whatsapp.webhook_secret', 'shared-pass');

        $this->postJson(route('api.whatsapp.webhook.handle'), ['id' => '1', 'status' => 'delivered'], [
            'X-Webhook-Token' => 'shared-pass',
        ])->assertStatus(200);
    }

    public function test_handle_accepts_shared_secret_from_fonnte_payload(): void
    {
        config()->set('whatsapp.webhook_secret', 'shared-pass');

        $this->postJson(route('api.whatsapp.webhook.handle'), [
            'id' => '1',
            'status' => 'delivered',
            'secret' => 'shared-pass',
        ])->assertStatus(200);
    }

    public function test_handle_accepts_shared_secret_from_webhook_secret_payload(): void
    {
        config()->set('whatsapp.webhook_secret', 'shared-pass');

        $this->postJson(route('api.whatsapp.webhook.handle'), [
            'id' => '1',
            'status' => 'delivered',
            'webhook_secret' => 'shared-pass',
        ])->assertStatus(200);
    }

    public function test_webhook_log_redacts_webhook_secret_payload(): void
    {
        config()->set('whatsapp.webhook_secret', 'shared-pass');
        Log::spy();

        $this->postJson(route('api.whatsapp.webhook.handle'), [
            'id' => '1',
            'status' => 'delivered',
            'webhook_secret' => 'shared-pass',
        ])->assertStatus(200);

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $message, array $context): bool => $message === '[WhatsApp Webhook Event Received]'
                && ($context['webhook_secret'] ?? null) === '[REDACTED]')
            ->once();
    }

    public function test_webhook_log_does_not_expose_shared_secret(): void
    {
        config()->set('whatsapp.webhook_secret', 'shared-pass');
        Log::spy();

        $this->postJson(route('api.whatsapp.webhook.handle'), [
            'id' => '1',
            'status' => 'delivered',
            'secret' => 'shared-pass',
        ])->assertStatus(200);

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $message, array $context): bool => $message === '[WhatsApp Webhook Event Received]'
                && ($context['secret'] ?? null) === '[REDACTED]')
            ->once();
    }
}
