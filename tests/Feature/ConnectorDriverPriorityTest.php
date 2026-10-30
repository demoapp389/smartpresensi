<?php

namespace Tests\Feature;

use App\Services\AttendanceProcessingService;
use App\Services\FingerprintConnectorService;
use App\Services\FingerprintIngestService;
use App\Services\WhatsAppNotificationService;
use Tests\TestCase;

class ConnectorDriverPriorityTest extends TestCase
{
    /**
     * Bangun instance service dengan platform yang disuntik agar urutan driver
     * dapat diuji tanpa bergantung pada OS host.
     */
    private function serviceFor(string $platform): object
    {
        return new class(app(FingerprintIngestService::class), app(AttendanceProcessingService::class), app(WhatsAppNotificationService::class), $platform) extends FingerprintConnectorService
        {
            /**
             * @return array<int, array<int, string>>
             */
            public function orderedCommands(): array
            {
                return $this->connectorCommands(['--test']);
            }
        };
    }

    public function test_windows_prefers_csharp_binary_first(): void
    {
        $commands = $this->serviceFor('Windows')->orderedCommands();

        $this->assertCount(2, $commands);
        $this->assertStringContainsString('SolutionX601Connector', $commands[0][0]);
    }

    public function test_linux_prefers_python_connector_first(): void
    {
        $commands = $this->serviceFor('Linux')->orderedCommands();

        $this->assertCount(2, $commands);
        $this->assertStringNotContainsString('.exe', $commands[0][0]);
        $this->assertStringContainsString('zkconnector.py', $commands[0][1] ?? '');
    }
}
