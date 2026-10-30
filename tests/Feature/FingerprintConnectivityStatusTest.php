<?php

namespace Tests\Feature;

use App\Models\FingerprintDevice;
use App\Services\AttendanceProcessingService;
use App\Services\FingerprintConnectorService;
use App\Services\FingerprintIngestService;
use App\Services\WhatsAppNotificationService;
use Tests\TestCase;

class FingerprintConnectivityStatusTest extends TestCase
{
    public function test_heartbeat_trusts_connector_when_device_is_connected(): void
    {
        $service = $this->service([
            'status' => 'ok',
            'devices' => [
                ['ip' => '10.0.0.1', 'port' => 4370, 'connected' => true],
            ],
        ]);

        $this->assertTrue($service->checkConnectivity($this->device('10.0.0.1', 4370)));
        $this->assertSame(0, $service->socketChecks);
        $this->assertSame('/api/status', $service->lastEndpoint);
    }

    public function test_heartbeat_trusts_connector_when_device_is_disconnected(): void
    {
        $service = $this->service([
            'status' => 'ok',
            'devices' => [
                ['ip' => '10.0.0.1', 'port' => 4370, 'connected' => false],
            ],
        ]);

        $this->assertFalse($service->checkConnectivity($this->device('10.0.0.1', 4370)));
        $this->assertSame(0, $service->socketChecks);
    }

    public function test_heartbeat_falls_back_to_socket_when_connector_is_offline(): void
    {
        $service = $this->service(null, true);

        $this->assertTrue($service->checkConnectivity($this->device('10.0.0.1', 4370)));
        $this->assertSame(1, $service->socketChecks);
    }

    public function test_heartbeat_falls_back_to_socket_when_device_is_not_in_connector_config(): void
    {
        $service = $this->service([
            'status' => 'ok',
            'devices' => [
                ['ip' => '10.0.0.2', 'port' => 4370, 'connected' => true],
            ],
        ], false);

        $this->assertFalse($service->checkConnectivity($this->device('10.0.0.1', 4370)));
        $this->assertSame(1, $service->socketChecks);
    }

    private function device(string $ip, int $port): FingerprintDevice
    {
        $device = new FingerprintDevice;
        $device->forceFill(['ip' => $ip, 'port' => $port]);

        return $device;
    }

    private function service(?array $status, bool $socketReachable = true): FingerprintConnectorService
    {
        return new class($this->app->make(FingerprintIngestService::class), $this->app->make(AttendanceProcessingService::class), $this->app->make(WhatsAppNotificationService::class), $status, $socketReachable) extends FingerprintConnectorService
        {
            public int $socketChecks = 0;

            public ?string $lastEndpoint = null;

            public function __construct(
                FingerprintIngestService $ingestService,
                AttendanceProcessingService $processingService,
                WhatsAppNotificationService $waService,
                private ?array $status,
                private bool $socketReachable,
            ) {
                parent::__construct($ingestService, $processingService, $waService);
            }

            protected function callHttpApi(string $endpoint, array $queryParams = [], float $timeout = 3): ?array
            {
                $this->lastEndpoint = $endpoint;

                return $this->status;
            }

            protected function checkSocketReachable(string $ip, int $port, float $timeout = 1.5): true|string
            {
                $this->socketChecks++;

                return $this->socketReachable ? true : 'Mesin tidak terhubung.';
            }
        };
    }
}
