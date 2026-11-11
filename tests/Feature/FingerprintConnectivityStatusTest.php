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
                [
                    'ip' => '10.0.0.1',
                    'port' => 4370,
                    'connected' => true,
                    'last_success_age_seconds' => 5,
                ],
            ],
        ]);

        $this->assertTrue($service->checkConnectivity($this->device('10.0.0.1', 4370)));
        $this->assertSame(0, $service->socketChecks);
        $this->assertSame('/api/status', $service->lastEndpoint);
    }

    public function test_heartbeat_treats_stale_connector_success_as_offline(): void
    {
        $service = $this->service([
            'status' => 'ok',
            'devices' => [
                [
                    'ip' => '10.0.0.1',
                    'port' => 4370,
                    'connected' => true,
                    'last_success_age_seconds' => 300,
                ],
            ],
        ]);

        $this->assertFalse($service->checkConnectivity($this->device('10.0.0.1', 4370)));
        $this->assertSame(0, $service->socketChecks);
    }

    public function test_heartbeat_falls_back_to_last_success_at_for_old_connector(): void
    {
        $service = $this->service([
            'status' => 'ok',
            'devices' => [
                [
                    'ip' => '10.0.0.1',
                    'port' => 4370,
                    'connected' => true,
                    'last_success_at' => now()->subSeconds(5)->toDateTimeString(),
                ],
            ],
        ]);

        $this->assertTrue($service->checkConnectivity($this->device('10.0.0.1', 4370)));
    }

    public function test_heartbeat_trusts_fresh_updated_at_from_legacy_windows_connector(): void
    {
        $service = $this->service([
            'status' => 'ok',
            'devices' => [
                [
                    'ip' => '10.0.0.1',
                    'port' => 4370,
                    'connected' => true,
                    'updated_at' => now()->subSeconds(5)->toDateTimeString(),
                ],
            ],
        ]);

        $this->assertTrue($service->checkConnectivity($this->device('10.0.0.1', 4370)));
    }

    public function test_heartbeat_treats_stale_updated_at_from_legacy_windows_connector_as_offline(): void
    {
        $service = $this->service([
            'status' => 'ok',
            'devices' => [
                [
                    'ip' => '10.0.0.1',
                    'port' => 4370,
                    'connected' => true,
                    'updated_at' => now()->subMinutes(10)->toDateTimeString(),
                ],
            ],
        ]);

        $this->assertFalse($service->checkConnectivity($this->device('10.0.0.1', 4370)));
        $this->assertSame(0, $service->socketChecks);
    }

    public function test_heartbeat_trusts_connector_when_device_is_disconnected(): void
    {
        $service = $this->service([
            'status' => 'ok',
            'devices' => [
                [
                    'ip' => '10.0.0.1',
                    'port' => 4370,
                    'connected' => false,
                    'last_success_age_seconds' => 5,
                ],
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
        $this->assertSame([4370, false], $service->socketArgs);
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

    public function test_heartbeat_probes_web_port_for_soap_device(): void
    {
        $service = $this->service([
            'status' => 'ok',
            'devices' => [
                ['ip' => '10.0.0.1', 'port' => 4370, 'connected' => false],
            ],
        ], true);

        $device = $this->device('10.0.0.1', 4370, 'soap');

        $this->assertTrue($service->checkConnectivity($device));
        $this->assertSame([80, false], $service->socketArgs);
        $this->assertSame(0, $service->statusChecks);
    }

    private function device(string $ip, int $port, string $driver = 'connector'): FingerprintDevice
    {
        $device = new FingerprintDevice;
        $device->forceFill(['ip' => $ip, 'port' => $port, 'ingest_driver' => $driver]);

        return $device;
    }

    private function service(?array $status, bool $socketReachable = true): FingerprintConnectorService
    {
        return new class($this->app->make(FingerprintIngestService::class), $this->app->make(AttendanceProcessingService::class), $this->app->make(WhatsAppNotificationService::class), $status, $socketReachable) extends FingerprintConnectorService
        {
            public int $socketChecks = 0;

            public int $statusChecks = 0;

            public ?string $lastEndpoint = null;

            /** @var array{0: int, 1: bool}|null */
            public ?array $socketArgs = null;

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
                $this->statusChecks++;

                return $this->status;
            }

            protected function checkSocketReachable(string $ip, int $port, float $timeout = 1.5, bool $allowWebFallback = true): true|string
            {
                $this->socketChecks++;
                $this->socketArgs = [$port, $allowWebFallback];

                return $this->socketReachable ? true : 'Mesin tidak terhubung.';
            }
        };
    }
}
