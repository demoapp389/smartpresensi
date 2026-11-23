<?php

namespace Tests\Feature;

use App\Models\FingerprintDevice;
use App\Services\AttendanceProcessingService;
use App\Services\FingerprintConnectorService;
use App\Services\FingerprintIngestService;
use App\Services\WhatsAppNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FingerprintConnectorExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_maps_connector_payload_to_templates(): void
    {
        $service = $this->service();
        $service->httpResponse = [
            'success' => true,
            'has_template' => true,
            'fingers' => 2,
            'size' => 24,
            'templates' => [
                ['finger' => 0, 'size' => 16, 'template_base64' => 'QUJD'],
                ['finger' => 4, 'size' => 8, 'template_base64' => 'REVG'],
            ],
            'message' => 'ok',
        ];

        $result = $service->exportUserTemplate($this->device(), '1001');

        $this->assertTrue($result['success']);
        $this->assertSame([
            ['finger_id' => 0, 'size' => 16, 'template' => 'QUJD'],
            ['finger_id' => 4, 'size' => 8, 'template' => 'REVG'],
        ], $result['templates']);
        $this->assertSame('1001', $service->lastQuery['pin']);
        $this->assertSame('1', $service->lastQuery['include_data']);
        $this->assertArrayNotHasKey('finger', $service->lastQuery);
    }

    public function test_export_limits_to_requested_finger(): void
    {
        $service = $this->service();
        $service->httpResponse = [
            'success' => true,
            'has_template' => true,
            'templates' => [['finger' => 2, 'size' => 4, 'template_base64' => 'WFla']],
            'message' => 'ok',
        ];

        $result = $service->exportUserTemplate($this->device(), '1001', 2);

        $this->assertTrue($result['success']);
        $this->assertSame('2', (string) $service->lastQuery['finger']);
    }

    public function test_export_uses_cli_when_daemon_lacks_template_payload(): void
    {
        $service = $this->service();
        // Daemon lama: hanya status verifikasi, tanpa isi template.
        $service->httpResponse = [
            'success' => true,
            'has_template' => true,
            'fingers' => 1,
            'size' => 16,
            'message' => 'PIN 1001 memiliki template sidik jari (1 jari).',
        ];
        $service->cliResponse = [
            'success' => true,
            'has_template' => true,
            'templates' => [['finger' => 0, 'size' => 16, 'template_base64' => 'Q0xJ']],
            'message' => 'ok',
        ];

        $result = $service->exportUserTemplate($this->device(), '1001');

        $this->assertTrue($result['success']);
        $this->assertSame('Q0xJ', $result['templates'][0]['template']);
        $this->assertContains('--export-template', $service->lastCli);
    }

    public function test_export_reports_finger_without_template(): void
    {
        $service = $this->service();
        $service->httpResponse = [
            'success' => true,
            'has_template' => false,
            'templates' => [],
            'message' => 'PIN 1001 terdaftar tetapi belum ada template sidik jari.',
        ];
        $service->cliResponse = [
            'success' => true,
            'has_template' => false,
            'templates' => [],
            'message' => 'PIN 1001 terdaftar tetapi belum ada template sidik jari.',
        ];

        $result = $service->exportUserTemplate($this->device(), '1001');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('belum ada template', $result['message']);
    }

    public function test_export_fails_clearly_when_connector_returns_status_without_data(): void
    {
        $service = $this->service();
        $service->httpResponse = [
            'success' => true,
            'has_template' => true,
            'message' => 'PIN 1001 memiliki template sidik jari (1 jari).',
        ];
        $service->cliResponse = [
            'success' => true,
            'has_template' => true,
            'message' => 'PIN 1001 memiliki template sidik jari (1 jari).',
        ];

        $result = $service->exportUserTemplate($this->device(), '1001');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Perbarui connector', $result['message']);
    }

    private function device(): FingerprintDevice
    {
        return FingerprintDevice::create([
            'name' => 'Solution X601 - Gerbang',
            'ip' => '10.10.10.201',
            'port' => 4370,
            'comm_key' => '0',
            'timezone' => 'Asia/Jakarta',
            'status' => 'active',
            'last_seen_at' => now(),
            'secret_token' => 'tok-export',
            'active' => true,
        ]);
    }

    private function service(): FingerprintConnectorService
    {
        return new class($this->app->make(FingerprintIngestService::class), $this->app->make(AttendanceProcessingService::class), $this->app->make(WhatsAppNotificationService::class)) extends FingerprintConnectorService
        {
            public ?array $httpResponse = null;

            public ?array $cliResponse = null;

            /** @var array<int, string> */
            public array $lastCli = [];

            /** @var array<string, mixed> */
            public array $lastQuery = [];

            public function __construct(
                FingerprintIngestService $ingestService,
                AttendanceProcessingService $processingService,
                WhatsAppNotificationService $waService,
            ) {
                parent::__construct($ingestService, $processingService, $waService, 'Linux');
            }

            protected function callHttpApi(string $endpoint, array $queryParams = [], float $timeout = 3): ?array
            {
                $this->lastQuery = $queryParams;

                return $this->httpResponse;
            }

            protected function callCli(array $arguments): array
            {
                $this->lastCli = $arguments;

                return $this->cliResponse ?? [
                    'success' => false,
                    'code' => -998,
                    'message' => 'CLI tidak tersedia.',
                ];
            }

            protected function checkSocketReachable(string $ip, int $port, float $timeout = 1.5, bool $allowWebFallback = true): true|string
            {
                return true;
            }

            public function supportsTemplateVerification(): bool
            {
                return true;
            }
        };
    }
}
