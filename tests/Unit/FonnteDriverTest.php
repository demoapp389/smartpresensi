<?php

namespace Tests\Unit;

use App\Services\WhatsApp\FonnteDriver;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FonnteDriverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('whatsapp.fonnte.token', 'test-fonnte-token');
        Config::set('whatsapp.fonnte.url', 'https://api.fonnte.com/send');
    }

    public function test_rejects_http_200_response_when_fonnte_status_is_false(): void
    {
        Http::fake([
            'https://api.fonnte.com/send' => Http::response([
                'status' => false,
                'reason' => 'Device disconnected',
            ], 200),
        ]);

        $result = (new FonnteDriver)->sendDirectMessage('6281234567890', 'Halo');

        $this->assertFalse($result['success']);
        $this->assertNull($result['message_id']);
        $this->assertStringContainsString('Device disconnected', $result['error']);
    }

    public function test_rejects_response_without_status_flag(): void
    {
        Http::fake([
            'https://api.fonnte.com/send' => Http::response(['detail' => 'queued'], 200),
        ]);

        $result = (new FonnteDriver)->sendDirectMessage('6281234567890', 'Halo');

        $this->assertFalse($result['success']);
        $this->assertNotNull($result['error']);
    }

    public function test_accepts_status_true_and_uses_only_the_first_message_id(): void
    {
        Http::fake([
            'https://api.fonnte.com/send' => Http::response([
                'status' => true,
                'detail' => 'success! message in queue',
                'id' => ['msg-1', 'msg-2'],
                'process' => 'pending',
            ], 200),
        ]);

        $result = (new FonnteDriver)->sendDirectMessage('6281234567890', 'Halo');

        $this->assertTrue($result['success']);
        $this->assertSame('msg-1', $result['message_id']);
        $this->assertNull($result['error']);
    }

    public function test_accepts_uppercase_status_flag(): void
    {
        Http::fake([
            'https://api.fonnte.com/send' => Http::response([
                'Status' => 'true',
                'id' => ['msg-3'],
            ], 200),
        ]);

        $result = (new FonnteDriver)->sendDirectMessage('6281234567890', 'Halo');

        $this->assertTrue($result['success']);
        $this->assertSame('msg-3', $result['message_id']);
    }
}
