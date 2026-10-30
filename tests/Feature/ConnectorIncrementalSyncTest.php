<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\FingerprintDevice;
use App\Services\AttendanceProcessingService;
use App\Services\DeviceSyncLogService;
use App\Services\FingerprintConnectorService;
use App\Services\FingerprintIngestService;
use App\Services\WhatsAppNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConnectorIncrementalSyncTest extends TestCase
{
    use RefreshDatabase;

    private FingerprintDevice $device;

    protected function setUp(): void
    {
        parent::setUp();

        $this->device = FingerprintDevice::create([
            'name' => 'X601',
            'ip' => '192.168.1.220',
            'port' => 4370,
            'comm_key' => '0',
            'timezone' => 'Asia/Jakarta',
            'status' => 'active',
            'secret_token' => 'tok_incremental',
            'active' => true,
        ]);
    }

    public function test_sync_attendances_sends_since_from_latest_stored_log(): void
    {
        AttendanceLog::create([
            'device_id' => $this->device->id,
            'machine_user_id' => '7001',
            'occurred_at' => '2026-10-14 07:05:12',
            'received_at' => now(),
            'source_key' => 'x601_7001_1',
            'status' => 'processed',
        ]);

        $service = $this->service(['success' => true, 'logs' => []]);
        $result = $service->syncAttendances($this->device);

        $this->assertTrue($result['success']);
        $this->assertSame('/api/sync-logs', $service->lastEndpoint);
        $this->assertSame('2026-10-14 07:00:12', $service->lastQuery['since'] ?? null);
    }

    public function test_busy_connector_response_does_not_create_failure_log(): void
    {
        $service = $this->service([
            'success' => false,
            'code' => -202,
            'message' => 'Device sedang sibuk.',
        ]);

        $result = $service->syncAttendances($this->device);

        $this->assertFalse($result['success']);
        $this->assertTrue($result['busy']);
        $this->assertSame(-202, $result['code']);
        $this->assertDatabaseCount('device_sync_logs', 0);
    }

    public function test_manual_sync_time_uses_connector_and_records_one_log(): void
    {
        $service = $this->service([
            'success' => true,
            'message' => 'Jam mesin disinkronkan.',
            'device_time' => '2026-10-23 08:00:00',
            'drift_seconds' => 12,
            'synced' => true,
        ]);

        $result = $service->syncTime($this->device);

        $this->assertTrue($result['success']);
        $this->assertSame('/api/sync-time', $service->lastEndpoint);
        $this->assertDatabaseHas('device_sync_logs', [
            'device_id' => $this->device->id,
            'sync_type' => 'sync_time',
            'status' => 'success',
        ]);
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function service(array $response): FingerprintConnectorService
    {
        $service = new class(app(FingerprintIngestService::class), app(AttendanceProcessingService::class), app(WhatsAppNotificationService::class), 'Windows', app(DeviceSyncLogService::class)) extends FingerprintConnectorService
        {
            public ?string $lastEndpoint = null;

            /** @var array<string, mixed> */
            public array $lastQuery = [];

            /** @var array<string, mixed> */
            public array $response = [];

            protected function checkSocketReachable(string $ip, int $port, float $timeout = 1.5): true|string
            {
                return true;
            }

            protected function callHttpApi(string $endpoint, array $queryParams = [], float $timeout = 3): ?array
            {
                $this->lastEndpoint = $endpoint;
                $this->lastQuery = $queryParams;

                return $this->response;
            }

            /**
             * @param  array<string, mixed>  $response
             */
            public function setResponse(array $response): void
            {
                $this->response = $response;
            }
        };

        $service->setResponse($response);

        return $service;
    }
}
