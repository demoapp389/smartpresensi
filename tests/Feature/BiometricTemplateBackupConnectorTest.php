<?php

namespace Tests\Feature;

use App\Jobs\BulkDeviceOperation;
use App\Models\DeviceUser;
use App\Models\FingerprintDevice;
use App\Models\Student;
use App\Models\TaskProgress;
use App\Models\User;
use App\Services\BiometricTemplateService;
use App\Services\FingerprintConnectorService;
use App\Services\SoapFingerprintService;
use App\Support\TaskProgressReporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BiometricTemplateBackupConnectorTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
    }

    public function test_backup_via_connector_stores_every_finger_without_touching_soap(): void
    {
        $device = $this->device('connector');
        $student = $this->student('1001');

        $soap = $this->createMock(SoapFingerprintService::class);
        $soap->expects($this->never())->method('getUserTemplate');

        $connector = $this->createMock(FingerprintConnectorService::class);
        $connector->expects($this->once())
            ->method('exportUserTemplate')
            ->with($device, '1001')
            ->willReturn([
                'success' => true,
                'templates' => [
                    ['finger_id' => 0, 'size' => 16, 'template' => 'QUJD'],
                    ['finger_id' => 3, 'size' => 8, 'template' => 'REVG'],
                ],
                'message' => 'PIN 1001 memiliki template sidik jari (2 jari).',
                'code' => 0,
            ]);

        $service = new BiometricTemplateService($soap, $connector);
        $result = $service->backupTemplate($device, $student, '1001', $this->admin->id);

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('2 jari', $result['message']);

        $this->assertDatabaseHas('student_biometric_templates', [
            'student_id' => $student->id,
            'finger_index' => 0,
            'template_data' => 'QUJD',
            'template_size' => 16,
            'source_device_id' => $device->id,
        ]);

        $this->assertDatabaseHas('student_biometric_templates', [
            'student_id' => $student->id,
            'finger_index' => 3,
            'template_data' => 'REVG',
            'template_size' => 8,
        ]);
    }

    public function test_backup_uses_soap_for_soap_driver(): void
    {
        $device = $this->device('soap');
        $student = $this->student('1002');

        $connector = $this->createMock(FingerprintConnectorService::class);
        $connector->expects($this->never())->method('exportUserTemplate');

        $soap = $this->createMock(SoapFingerprintService::class);
        $soap->expects($this->once())
            ->method('getUserTemplate')
            ->with($device, '1002', 0, $this->admin->id)
            ->willReturn([
                'success' => true,
                'template' => 'SOAP_TEMPLATE',
                'size' => 13,
                'message' => 'OK',
            ]);

        $service = new BiometricTemplateService($soap, $connector);
        $result = $service->backupTemplate($device, $student, '1002', $this->admin->id);

        $this->assertTrue($result['success']);
        $this->assertDatabaseHas('student_biometric_templates', [
            'student_id' => $student->id,
            'finger_index' => 0,
            'template_data' => 'SOAP_TEMPLATE',
        ]);
    }

    public function test_connector_driver_falls_back_to_soap_when_reachable(): void
    {
        $device = $this->device('connector');
        $student = $this->student('1003');

        $connector = $this->createMock(FingerprintConnectorService::class);
        $connector->expects($this->once())
            ->method('exportUserTemplate')
            ->willReturn(['success' => false, 'message' => 'Connector tidak merespons.', 'code' => -1]);

        $soap = $this->createMock(SoapFingerprintService::class);
        $soap->method('isSoapReachable')->willReturn(true);
        $soap->expects($this->once())
            ->method('getUserTemplate')
            ->willReturn([
                'success' => true,
                'template' => 'FALLBACK_SOAP',
                'size' => 13,
                'message' => 'OK',
            ]);

        $service = new BiometricTemplateService($soap, $connector);
        $result = $service->backupTemplate($device, $student, '1003', $this->admin->id);

        $this->assertTrue($result['success']);
        $this->assertDatabaseHas('student_biometric_templates', [
            'student_id' => $student->id,
            'template_data' => 'FALLBACK_SOAP',
        ]);
    }

    public function test_backup_blocked_when_neither_path_available(): void
    {
        $device = $this->device('connector');

        $connector = $this->createMock(FingerprintConnectorService::class);
        $connector->method('supportsTemplateVerification')->willReturn(false);

        $soap = $this->createMock(SoapFingerprintService::class);
        $soap->method('isSoapReachable')->willReturn(false);

        $service = new BiometricTemplateService($soap, $connector);
        $reason = $service->backupBlockedReason($device);

        $this->assertNotNull($reason);
        $this->assertStringContainsString('SOAP', $reason);
    }

    public function test_bulk_backup_job_persists_connector_templates(): void
    {
        $device = $this->device('connector');
        $student = $this->student('2001');

        DeviceUser::create([
            'device_id' => $device->id,
            'student_id' => $student->id,
            'machine_user_id' => '2001',
        ]);

        $connector = $this->createMock(FingerprintConnectorService::class);
        $connector->method('supportsTemplateVerification')->willReturn(true);
        $connector->expects($this->once())
            ->method('exportUserTemplate')
            ->willReturn([
                'success' => true,
                'templates' => [['finger_id' => 0, 'size' => 10, 'template' => 'JOB_TEMPLATE']],
                'message' => 'ok',
                'code' => 0,
            ]);
        $this->app->instance(FingerprintConnectorService::class, $connector);

        $soap = $this->createMock(SoapFingerprintService::class);

        $reporter = TaskProgressReporter::start('devices.backup-fingerprints', 'Backup Sidik Jari', 1, $this->admin->id);

        $job = new BulkDeviceOperation(
            BulkDeviceOperation::OP_BACKUP_FINGERPRINTS,
            $device->id,
            null,
            $this->admin->id,
            $reporter->uuid()
        );

        $job->handle($soap);

        $task = TaskProgress::where('uuid', $reporter->uuid())->firstOrFail();
        $this->assertSame(TaskProgress::STATUS_COMPLETED, $task->status);
        $this->assertDatabaseHas('student_biometric_templates', [
            'student_id' => $student->id,
            'finger_index' => 0,
            'template_data' => 'JOB_TEMPLATE',
        ]);
    }

    public function test_bulk_backup_job_fails_with_clear_message_when_pull_fails(): void
    {
        $device = $this->device('connector');
        $student = $this->student('2002');

        DeviceUser::create([
            'device_id' => $device->id,
            'student_id' => $student->id,
            'machine_user_id' => '2002',
        ]);

        $connector = $this->createMock(FingerprintConnectorService::class);
        $connector->method('supportsTemplateVerification')->willReturn(true);
        $connector->method('exportUserTemplate')->willReturn([
            'success' => false,
            'message' => 'Mesin Solution X601 tidak merespons di jaringan LAN.',
            'code' => -1,
        ]);
        $this->app->instance(FingerprintConnectorService::class, $connector);

        $soap = $this->createMock(SoapFingerprintService::class);
        $soap->method('isSoapReachable')->willReturn(false);

        $reporter = TaskProgressReporter::start('devices.backup-fingerprints', 'Backup Sidik Jari', 1, $this->admin->id);

        $job = new BulkDeviceOperation(
            BulkDeviceOperation::OP_BACKUP_FINGERPRINTS,
            $device->id,
            null,
            $this->admin->id,
            $reporter->uuid()
        );

        $job->handle($soap);

        $task = TaskProgress::where('uuid', $reporter->uuid())->firstOrFail();
        $this->assertSame(TaskProgress::STATUS_FAILED, $task->status);
        $this->assertStringContainsString('Backup sidik jari gagal', $task->message);
        $this->assertStringContainsString('tidak merespons', $task->message);
        $this->assertDatabaseCount('student_biometric_templates', 0);
    }

    public function test_bulk_backup_job_stops_before_loop_when_soap_unavailable(): void
    {
        $device = $this->device('soap');
        $student = $this->student('2003');

        DeviceUser::create([
            'device_id' => $device->id,
            'student_id' => $student->id,
            'machine_user_id' => '2003',
        ]);

        $connector = $this->createMock(FingerprintConnectorService::class);
        $connector->method('supportsTemplateVerification')->willReturn(false);
        $this->app->instance(FingerprintConnectorService::class, $connector);

        $soap = $this->createMock(SoapFingerprintService::class);
        $soap->method('isSoapReachable')->willReturn(false);
        $soap->expects($this->never())->method('getUserTemplate');

        $reporter = TaskProgressReporter::start('devices.backup-fingerprints', 'Backup Sidik Jari', 1, $this->admin->id);

        $job = new BulkDeviceOperation(
            BulkDeviceOperation::OP_BACKUP_FINGERPRINTS,
            $device->id,
            null,
            $this->admin->id,
            $reporter->uuid()
        );

        $job->handle($soap);

        $task = TaskProgress::where('uuid', $reporter->uuid())->firstOrFail();
        $this->assertSame(TaskProgress::STATUS_FAILED, $task->status);
        $this->assertStringContainsString('SOAP', $task->message);
    }

    private function device(string $driver): FingerprintDevice
    {
        return FingerprintDevice::create([
            'name' => 'Solution X601 - '.$driver,
            'ip' => '10.10.10.201',
            'port' => 4370,
            'comm_key' => '0',
            'timezone' => 'Asia/Jakarta',
            'status' => 'active',
            'last_seen_at' => now(),
            'secret_token' => 'token-'.uniqid(),
            'ingest_driver' => $driver,
            'active' => true,
        ]);
    }

    private function student(string $pin): Student
    {
        return Student::create([
            'nis' => 'NIS-'.$pin,
            'name' => 'Siswa '.$pin,
            'gender' => 'L',
            'active' => true,
        ]);
    }
}
