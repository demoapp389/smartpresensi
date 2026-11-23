<?php

namespace Tests\Feature;

use App\Services\AttendanceProcessingService;
use App\Services\FingerprintConnectorService;
use App\Services\FingerprintIngestService;
use App\Services\WhatsAppNotificationService;
use Tests\TestCase;

class FingerprintConnectorTemplateCapabilityTest extends TestCase
{
    public function test_connector_reporting_user_template_capability_supports_verification(): void
    {
        $service = $this->service([
            'status' => 'ok',
            'capabilities' => ['user_template' => true],
        ]);

        $this->assertTrue($service->supportsTemplateVerification());
        $this->assertSame('/api/status', $service->lastEndpoint);
    }

    public function test_connector_without_capability_disables_verification(): void
    {
        $service = $this->service(['status' => 'ok']);

        $this->assertFalse($service->supportsTemplateVerification());
    }

    public function test_unreachable_connector_disables_verification(): void
    {
        $service = $this->service(null);

        $this->assertFalse($service->supportsTemplateVerification());
    }

    public function test_capability_probe_is_cached_between_calls(): void
    {
        $service = $this->service([
            'status' => 'ok',
            'capabilities' => ['user_template' => true],
        ]);

        $service->supportsTemplateVerification();
        $service->supportsTemplateVerification();

        $this->assertSame(1, $service->statusChecks);
    }

    /**
     * Service tiruan: platform dipaksa Linux agar binary C# (yang ada di repo)
     * tidak dianggap bisa dijalankan, dan `callHttpApi` tidak menyentuh jaringan.
     *
     * @param  array<string, mixed>|null  $status
     */
    private function service(?array $status): FingerprintConnectorService
    {
        return new class($this->app->make(FingerprintIngestService::class), $this->app->make(AttendanceProcessingService::class), $this->app->make(WhatsAppNotificationService::class), $status) extends FingerprintConnectorService
        {
            public int $statusChecks = 0;

            public ?string $lastEndpoint = null;

            public function __construct(
                FingerprintIngestService $ingestService,
                AttendanceProcessingService $processingService,
                WhatsAppNotificationService $waService,
                private ?array $status,
            ) {
                parent::__construct($ingestService, $processingService, $waService, 'Linux');
            }

            protected function callHttpApi(string $endpoint, array $queryParams = [], float $timeout = 3): ?array
            {
                $this->lastEndpoint = $endpoint;
                $this->statusChecks++;

                return $this->status;
            }
        };
    }
}
