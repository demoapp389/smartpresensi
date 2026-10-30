<?php

namespace Tests\Feature;

use App\Services\AttendanceProcessingService;
use App\Services\FingerprintConnectorService;
use App\Services\FingerprintIngestService;
use App\Services\WhatsAppNotificationService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ConnectorLocalApiAuthTest extends TestCase
{
    public function test_local_api_request_sends_connector_token(): void
    {
        config()->set('services.connector.local_api_url', 'http://127.0.0.1:4371');
        config()->set('services.connector.local_api_token', 'ct_test_token');

        Http::fake([
            '127.0.0.1:4371/*' => Http::response(['status' => 'ok'], 200),
        ]);

        $service = $this->service();
        $result = $service->callStatus();

        $this->assertSame(['status' => 'ok'], $result);
        Http::assertSent(fn ($request): bool => $request->url() === 'http://127.0.0.1:4371/api/status'
            && $request->hasHeader('X-Connector-Token', 'ct_test_token'));
    }

    public function test_local_api_request_omits_header_when_token_is_empty(): void
    {
        config()->set('services.connector.local_api_url', 'http://127.0.0.1:4371');
        config()->set('services.connector.local_api_token', '');

        Http::fake([
            '127.0.0.1:4371/*' => Http::response(['status' => 'ok'], 200),
        ]);

        $service = $this->service();
        $service->callStatus();

        Http::assertSent(fn ($request): bool => ! $request->hasHeader('X-Connector-Token'));
    }

    private function service(): FingerprintConnectorService
    {
        return new class($this->app->make(FingerprintIngestService::class), $this->app->make(AttendanceProcessingService::class), $this->app->make(WhatsAppNotificationService::class)) extends FingerprintConnectorService
        {
            public function callStatus(): ?array
            {
                return $this->callHttpApi('/api/status');
            }
        };
    }
}
