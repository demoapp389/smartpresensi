<?php

namespace Tests\Feature;

use App\Models\DeviceSyncLog;
use App\Models\DeviceUser;
use App\Models\FingerprintDevice;
use App\Models\Student;
use App\Models\StudentBiometricTemplate;
use App\Models\User;
use App\Services\AttendanceProcessingService;
use App\Services\FingerprintConnectorService;
use App\Services\FingerprintIngestService;
use App\Services\SoapFingerprintService;
use App\Services\WhatsAppNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SoapFingerprintServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_xml_tag_and_row_parsing_works_correctly(): void
    {
        /** @var SoapFingerprintService $service */
        $service = app(SoapFingerprintService::class);

        $sampleXml = '<Information>Success!</Information>';
        $this->assertSame('Success!', $service->parseTag($sampleXml, '<Information>', '</Information>'));

        $logResponseXml = '<GetAttLogResponse>'
            .'<Row><PIN>101</PIN><DateTime>2026-10-13 07:15:00</DateTime><Verified>1</Verified><Status>0</Status></Row>'
            .'<Row><PIN>102</PIN><DateTime>2026-10-13 07:18:22</DateTime><Verified>1</Verified><Status>0</Status></Row>'
            .'</GetAttLogResponse>';

        $rows = $service->parseRows($logResponseXml, 'Row');
        $this->assertCount(2, $rows);

        $pin1 = $service->parseTag($rows[0], '<PIN>', '</PIN>');
        $time1 = $service->parseTag($rows[0], '<DateTime>', '</DateTime>');
        $this->assertSame('101', $pin1);
        $this->assertSame('2026-10-13 07:15:00', $time1);

        $pin2 = $service->parseTag($rows[1], '<PIN>', '</PIN>');
        $this->assertSame('102', $pin2);
    }

    public function test_connection_failure_updates_device_status_to_offline(): void
    {
        $device = FingerprintDevice::create([
            'name' => 'Mesin Uji Coba',
            'ip' => '127.0.0.1',
            'port' => 64999, // port tidak aktif
            'comm_key' => '0',
            'timezone' => 'Asia/Jakarta',
            'status' => 'active',
            'secret_token' => 'test_token_123',
            'active' => true,
        ]);

        /** @var SoapFingerprintService $service */
        $service = app(SoapFingerprintService::class);

        $result = $service->testConnection($device);

        $this->assertFalse($result['success']);
        $this->assertSame('offline', $device->fresh()->status);
        $this->assertDatabaseHas('device_sync_logs', [
            'device_id' => $device->id,
            'sync_type' => 'test_connection',
            'status' => 'failed',
        ]);
    }

    public function test_pull_attendance_logs_parses_and_ingests_records(): void
    {
        $device = FingerprintDevice::create([
            'name' => 'Mesin Gerbang',
            'ip' => '192.168.1.201',
            'port' => 80,
            'comm_key' => '0',
            'timezone' => 'Asia/Jakarta',
            'status' => 'offline',
            'secret_token' => 'test_token_gate',
            'active' => true,
        ]);

        $mockXml = "HTTP/1.0 200 OK\r\n\r\n<GetAttLogResponse>"
            .'<Row><PIN>101</PIN><DateTime>2026-10-13 07:05:12</DateTime><Verified>1</Verified><Status>0</Status></Row>'
            .'</GetAttLogResponse>';

        $service = new class(app(FingerprintIngestService::class), app(AttendanceProcessingService::class), app(WhatsAppNotificationService::class)) extends SoapFingerprintService
        {
            public string $simulatedResponse = '';

            public function sendSoapRequest(string $ip, int $port, string $soapXml, float $timeout = 3.0): ?string
            {
                return $this->simulatedResponse;
            }
        };

        $service->simulatedResponse = $mockXml;

        $result = $service->pullAttendanceLogs($device);

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['records_count']);
        $this->assertSame('active', $device->fresh()->status);
        $this->assertDatabaseHas('attendance_logs', [
            'device_id' => $device->id,
            'machine_user_id' => '101',
        ]);
    }

    public function test_authenticated_user_can_trigger_soap_device_routes(): void
    {
        $admin = User::factory()->create();

        $device = FingerprintDevice::create([
            'name' => 'Mesin Utama',
            'ip' => '127.0.0.1',
            'port' => 64999, // port tidak aktif agar cepat offline
            'comm_key' => '0',
            'timezone' => 'Asia/Jakarta',
            'status' => 'offline',
            'secret_token' => 'sec_tok_123',
            'active' => true,
        ]);

        $student = Student::create([
            'nis' => '1001',
            'name' => 'Ahmad Dahlan',
            'gender' => 'L',
            'active' => true,
        ]);
        $mapping = DeviceUser::create([
            'device_id' => $device->id,
            'student_id' => $student->id,
            'machine_user_id' => '999',
        ]);

        // 1. Route sync-time
        $response = $this->actingAs($admin)->post(route('devices.sync-time', $device));
        $response->assertRedirect(route('devices.show', $device));

        // 2. Route pull-soap
        $response = $this->actingAs($admin)->post(route('devices.pull-soap', $device));
        $response->assertRedirect(route('devices.show', $device));

        // 3. Route restart
        $response = $this->actingAs($admin)->post(route('devices.restart', $device));
        $response->assertRedirect(route('devices.show', $device));

        // 4. Route clear-logs
        $response = $this->actingAs($admin)->post(route('devices.clear-logs', $device));
        $response->assertRedirect(route('devices.show', $device));

        // 5. Route push mapping to machine
        $response = $this->actingAs($admin)->post(route('devices.mapping.push', $mapping));
        $response->assertRedirect(route('devices.mappings'));
    }

    public function test_artisan_pull_soap_command_runs_successfully(): void
    {
        FingerprintDevice::create([
            'name' => 'Mesin Lab',
            'ip' => '127.0.0.1',
            'port' => 64998,
            'comm_key' => '0',
            'timezone' => 'Asia/Jakarta',
            'status' => 'offline',
            'secret_token' => 'token_lab_123',
            'ingest_driver' => 'soap',
            'active' => true,
        ]);

        $this->artisan('fingerprint:pull-soap', ['--sync-time' => true])
            ->expectsOutputToContain('Memulai penarikan absensi Web SOAP')
            ->assertExitCode(0);
    }

    public function test_backup_and_distribute_biometric_fingerprint_workflow(): void
    {
        $admin = User::factory()->create();

        $device1 = FingerprintDevice::create([
            'name' => 'Mesin Utama',
            'ip' => '127.0.0.1',
            'port' => 64997,
            'comm_key' => '0',
            'timezone' => 'Asia/Jakarta',
            'status' => 'offline',
            'secret_token' => 'token_d1',
            'active' => true,
        ]);

        $device2 = FingerprintDevice::create([
            'name' => 'Mesin Kedua',
            'ip' => '127.0.0.2',
            'port' => 64996,
            'comm_key' => '0',
            'timezone' => 'Asia/Jakarta',
            'status' => 'offline',
            'secret_token' => 'token_d2',
            'active' => true,
        ]);

        $student = Student::create([
            'nis' => '1002',
            'name' => 'Fatmawati',
            'gender' => 'P',
            'active' => true,
        ]);

        $mapping = DeviceUser::create([
            'device_id' => $device1->id,
            'student_id' => $student->id,
            'machine_user_id' => '505',
        ]);

        // Simpan template sidik jari manual ke database
        StudentBiometricTemplate::create([
            'student_id' => $student->id,
            'finger_index' => 0,
            'template_data' => 'SAMPLE_BASE64_TEMPLATE_BYTES_1234567890',
            'template_size' => 40,
            'source_device_id' => $device1->id,
        ]);

        $mockService = $this->createMock(SoapFingerprintService::class);
        $mockService->method('setUserTemplate')->willReturn([
            'success' => true,
            'message' => 'Template berhasil didistribusikan',
        ]);
        $mockService->method('pushUserInfo')->willReturn([
            'success' => true,
            'message' => 'User berhasil dikirim',
        ]);
        $this->app->instance(SoapFingerprintService::class, $mockService);

        // Uji rute distribute-fingerprint ke mesin 2
        $response = $this->actingAs($admin)->post(route('devices.mapping.distribute-fingerprint', $mapping), [
            'target_device_id' => $device2->id,
        ]);

        $response->assertRedirect(route('devices.mappings'));
        $this->assertDatabaseHas('device_users', [
            'device_id' => $device2->id,
            'student_id' => $student->id,
            'machine_user_id' => '505',
        ]);
    }

    public function test_mapping_destroy_cleans_up_and_removes_record(): void
    {
        $admin = User::factory()->create();

        $device = FingerprintDevice::create([
            'name' => 'Mesin A',
            'ip' => '127.0.0.1',
            'port' => 64995,
            'comm_key' => '0',
            'timezone' => 'Asia/Jakarta',
            'status' => 'offline',
            'secret_token' => 'token_da',
            'active' => true,
        ]);

        $student = Student::create([
            'nis' => '1003',
            'name' => 'Sutan Sjahrir',
            'gender' => 'L',
            'active' => true,
        ]);

        $mapping = DeviceUser::create([
            'device_id' => $device->id,
            'student_id' => $student->id,
            'machine_user_id' => '707',
        ]);

        $response = $this->actingAs($admin)->delete(route('devices.mapping.destroy', $mapping));
        $response->assertRedirect(route('devices.mappings', ['device_id' => $device->id]));
        $this->assertDatabaseMissing('device_users', ['id' => $mapping->id]);
    }

    public function test_bulk_push_names_sends_all_mapped_students_to_lcd(): void
    {
        $admin = User::factory()->create();

        $device = FingerprintDevice::create([
            'name' => 'Mesin Massal A',
            'ip' => '127.0.0.1',
            'port' => 64994,
            'comm_key' => '0',
            'timezone' => 'Asia/Jakarta',
            'status' => 'active',
            'last_seen_at' => now(),
            'secret_token' => 'token_massal_a',
            'active' => true,
        ]);

        $student1 = Student::create(['nis' => '8001', 'name' => 'Ahmad Dahlan', 'gender' => 'L', 'active' => true]);
        $student2 = Student::create(['nis' => '8002', 'name' => 'Hasyim Asyari', 'gender' => 'L', 'active' => true]);

        DeviceUser::create(['device_id' => $device->id, 'student_id' => $student1->id, 'machine_user_id' => '101']);
        DeviceUser::create(['device_id' => $device->id, 'student_id' => $student2->id, 'machine_user_id' => '102']);

        $mockService = $this->createMock(SoapFingerprintService::class);
        $mockService->expects($this->exactly(2))
            ->method('pushUserInfo')
            ->willReturn(['success' => true, 'message' => 'OK']);
        $this->app->instance(SoapFingerprintService::class, $mockService);

        $response = $this->actingAs($admin)->post(route('devices.bulk-push-names', $device));
        $response->assertRedirect(route('devices.show', $device));
        $response->assertSessionHas('success');
    }

    public function test_bulk_backup_fingerprints_stores_all_templates_in_database(): void
    {
        $admin = User::factory()->create();

        $device = FingerprintDevice::create([
            'name' => 'Mesin Backup A',
            'ip' => '127.0.0.1',
            'port' => 64993,
            'comm_key' => '0',
            'timezone' => 'Asia/Jakarta',
            'status' => 'active',
            'last_seen_at' => now(),
            'secret_token' => 'token_backup_a',
            'active' => true,
        ]);

        $student = Student::create(['nis' => '8003', 'name' => 'Ki Hajar Dewantara', 'gender' => 'L', 'active' => true]);
        DeviceUser::create(['device_id' => $device->id, 'student_id' => $student->id, 'machine_user_id' => '103']);

        $mockService = $this->createMock(SoapFingerprintService::class);
        $mockService->expects($this->once())
            ->method('getUserTemplate')
            ->willReturn([
                'success' => true,
                'message' => 'OK',
                'template' => 'BASE64_TEMPLATE_KI_HAJAR',
                'size' => 24,
            ]);
        $this->app->instance(SoapFingerprintService::class, $mockService);

        $response = $this->actingAs($admin)->post(route('devices.bulk-backup-fingerprints', $device));
        $response->assertRedirect(route('devices.show', $device));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('student_biometric_templates', [
            'student_id' => $student->id,
            'finger_index' => 0,
            'template_data' => 'BASE64_TEMPLATE_KI_HAJAR',
        ]);
    }

    public function test_bulk_clone_device_copies_all_students_and_templates_to_target_device(): void
    {
        $admin = User::factory()->create();

        $deviceSource = FingerprintDevice::create([
            'name' => 'Mesin Sumber',
            'ip' => '127.0.0.1',
            'port' => 64992,
            'comm_key' => '0',
            'timezone' => 'Asia/Jakarta',
            'status' => 'active',
            'last_seen_at' => now(),
            'secret_token' => 'token_src',
            'active' => true,
        ]);

        $deviceTarget = FingerprintDevice::create([
            'name' => 'Mesin Target',
            'ip' => '127.0.0.2',
            'port' => 64991,
            'comm_key' => '0',
            'timezone' => 'Asia/Jakarta',
            'status' => 'active',
            'last_seen_at' => now(),
            'secret_token' => 'token_tgt',
            'active' => true,
        ]);

        $student = Student::create(['nis' => '8004', 'name' => 'Rasuna Said', 'gender' => 'P', 'active' => true]);
        DeviceUser::create(['device_id' => $deviceSource->id, 'student_id' => $student->id, 'machine_user_id' => '104']);

        // Template sudah ada di DB
        StudentBiometricTemplate::create([
            'student_id' => $student->id,
            'finger_index' => 0,
            'template_data' => 'BASE64_RASUNA_SAID',
            'template_size' => 18,
            'source_device_id' => $deviceSource->id,
        ]);

        $mockService = $this->createMock(SoapFingerprintService::class);
        $mockService->expects($this->once())
            ->method('pushUserInfo')
            ->willReturn(['success' => true, 'message' => 'OK']);
        $mockService->expects($this->once())
            ->method('setUserTemplate')
            ->willReturn(['success' => true, 'message' => 'OK']);
        $this->app->instance(SoapFingerprintService::class, $mockService);

        $response = $this->actingAs($admin)->post(route('devices.bulk-clone', $deviceSource), [
            'target_device_id' => $deviceTarget->id,
        ]);

        $response->assertRedirect(route('devices.show', $deviceSource));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('device_users', [
            'device_id' => $deviceTarget->id,
            'student_id' => $student->id,
            'machine_user_id' => '104',
        ]);
    }

    public function test_sync_attendances_uses_soap_service_when_successful(): void
    {
        $admin = User::factory()->create();

        $device = FingerprintDevice::create([
            'name' => 'Mesin Smart A',
            'ip' => '127.0.0.1',
            'port' => 64990,
            'comm_key' => '0',
            'timezone' => 'Asia/Jakarta',
            'status' => 'active',
            'last_seen_at' => now(),
            'secret_token' => 'token_smart_a',
            'ingest_driver' => 'soap',
            'active' => true,
        ]);

        $mockSoap = $this->createMock(SoapFingerprintService::class);
        $mockSoap->expects($this->once())
            ->method('pullAttendanceLogs')
            ->willReturn(['success' => true, 'message' => '5 data ditarik', 'records_count' => 5]);
        $this->app->instance(SoapFingerprintService::class, $mockSoap);

        $mockConnector = $this->createMock(FingerprintConnectorService::class);
        $mockConnector->expects($this->never())->method('ensureAttendanceReachable');
        $mockConnector->expects($this->never())->method('syncAttendances');
        $this->app->instance(FingerprintConnectorService::class, $mockConnector);

        $response = $this->actingAs($admin)->post(route('devices.sync-attendances', $device));
        $response->assertRedirect(route('devices.show', $device));
        $response->assertSessionHas('success', '5 data ditarik (via Web SOAP)');
    }

    public function test_sync_attendances_does_not_fall_back_to_connector_when_soap_fails(): void
    {
        $admin = User::factory()->create();

        $device = FingerprintDevice::create([
            'name' => 'Mesin Smart B',
            'ip' => '127.0.0.1',
            'port' => 64989,
            'comm_key' => '0',
            'timezone' => 'Asia/Jakarta',
            'status' => 'active',
            'last_seen_at' => now(),
            'secret_token' => 'token_smart_b',
            'ingest_driver' => 'soap',
            'active' => true,
        ]);

        $mockSoap = $this->createMock(SoapFingerprintService::class);
        $mockSoap->expects($this->once())
            ->method('pullAttendanceLogs')
            ->willReturn(['success' => false, 'message' => 'Connection timed out']);
        $this->app->instance(SoapFingerprintService::class, $mockSoap);

        $mockConnector = $this->createMock(FingerprintConnectorService::class);
        $mockConnector->expects($this->never())->method('ensureAttendanceReachable');
        $mockConnector->expects($this->never())->method('syncAttendances');
        $this->app->instance(FingerprintConnectorService::class, $mockConnector);

        $response = $this->actingAs($admin)->post(route('devices.sync-attendances', $device));
        $response->assertRedirect(route('devices.show', $device));
        $response->assertSessionHas('error', 'Connection timed out');
    }

    public function test_delete_user_command_is_sent_and_success_is_logged(): void
    {
        $device = FingerprintDevice::create([
            'name' => 'Mesin Hapus',
            'ip' => '127.0.0.1',
            'port' => 80,
            'comm_key' => '0',
            'timezone' => 'Asia/Jakarta',
            'status' => 'active',
            'secret_token' => 'token_delete',
            'active' => true,
        ]);

        $service = $this->fakeSoapService();
        $service->simulatedResponse = "HTTP/1.0 200 OK\r\n\r\n<DeleteUserResponse><ArgComKey>0</ArgComKey><Information>OK</Information></DeleteUserResponse>";

        $result = $service->deleteUser($device, '101');

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('<DeleteUser>', $service->requests[0]);
        $this->assertStringContainsString('<PIN xsi:type="xsd:integer">101</PIN>', $service->requests[0]);
        // RefreshDB hanya dikirim setelah penghapusan terkonfirmasi sukses.
        $this->assertTrue(collect($service->requests)->contains(fn (string $xml) => str_contains($xml, '<RefreshDB>')));
        $this->assertDatabaseHas('device_sync_logs', [
            'device_id' => $device->id,
            'sync_type' => 'delete_user',
            'status' => 'success',
        ]);
    }

    public function test_delete_user_reports_failure_when_device_unreachable(): void
    {
        $device = FingerprintDevice::create([
            'name' => 'Mesin Hapus Offline',
            'ip' => '127.0.0.1',
            'port' => 80,
            'comm_key' => '0',
            'timezone' => 'Asia/Jakarta',
            'status' => 'offline',
            'secret_token' => 'token_delete_offline',
            'active' => true,
        ]);

        $service = $this->fakeSoapService();
        $service->simulatedResponse = null;

        $result = $service->deleteUser($device, '202');

        $this->assertFalse($result['success']);
        $this->assertDatabaseHas('device_sync_logs', [
            'device_id' => $device->id,
            'sync_type' => 'delete_user',
            'status' => 'failed',
        ]);
    }

    public function test_delete_user_reports_failure_when_machine_returns_error(): void
    {
        $device = FingerprintDevice::create([
            'name' => 'Mesin Hapus Error',
            'ip' => '127.0.0.1',
            'port' => 80,
            'comm_key' => '0',
            'timezone' => 'Asia/Jakarta',
            'status' => 'active',
            'secret_token' => 'token_delete_error',
            'active' => true,
        ]);

        $service = $this->fakeSoapService();
        $service->simulatedResponse = "HTTP/1.0 200 OK\r\n\r\n<DeleteUserResponse><Information>Error</Information></DeleteUserResponse>";

        $result = $service->deleteUser($device, '303');

        $this->assertFalse($result['success']);
        $this->assertDatabaseHas('device_sync_logs', [
            'device_id' => $device->id,
            'sync_type' => 'delete_user',
            'status' => 'failed',
        ]);
    }

    public function test_delete_machine_user_requires_exact_confirmation_phrase(): void
    {
        $admin = User::factory()->create();

        $device = FingerprintDevice::create([
            'name' => 'Mesin Konfirmasi',
            'ip' => '127.0.0.1',
            'port' => 80,
            'comm_key' => '0',
            'timezone' => 'Asia/Jakarta',
            'status' => 'active',
            'secret_token' => 'token_confirm',
            'active' => true,
        ]);

        $mock = $this->createMock(SoapFingerprintService::class);
        $mock->expects($this->never())->method('deleteUser');
        $this->app->instance(SoapFingerprintService::class, $mock);

        $response = $this->actingAs($admin)->post(route('devices.delete-user', $device), [
            'pin' => '101',
            'confirm' => 'HAPUS 102',
        ]);

        $response->assertRedirect(route('devices.show', $device));
        $response->assertSessionHas('error');
        $this->assertDatabaseMissing('audit_logs', ['action' => 'delete_machine_user']);
    }

    public function test_delete_machine_user_rejects_unsafe_pin_characters(): void
    {
        $admin = User::factory()->create();

        $device = FingerprintDevice::create([
            'name' => 'Mesin Karakter',
            'ip' => '127.0.0.1',
            'port' => 80,
            'comm_key' => '0',
            'timezone' => 'Asia/Jakarta',
            'status' => 'active',
            'secret_token' => 'token_chars',
            'active' => true,
        ]);

        $mock = $this->createMock(SoapFingerprintService::class);
        $mock->expects($this->never())->method('deleteUser');
        $this->app->instance(SoapFingerprintService::class, $mock);

        $response = $this->actingAs($admin)->post(route('devices.delete-user', $device), [
            'pin' => '1<script>',
            'confirm' => 'HAPUS 1<script>',
        ]);

        $response->assertSessionHasErrors('pin');
    }

    public function test_delete_machine_user_executes_and_audits_when_confirmed(): void
    {
        $admin = User::factory()->create();

        $device = FingerprintDevice::create([
            'name' => 'Mesin Audit Hapus',
            'ip' => '127.0.0.1',
            'port' => 80,
            'comm_key' => '0',
            'timezone' => 'Asia/Jakarta',
            'status' => 'active',
            'last_seen_at' => now(),
            'secret_token' => 'token_audit_delete',
            'active' => true,
        ]);

        $mock = $this->createMock(SoapFingerprintService::class);
        $mock->expects($this->once())
            ->method('deleteUser')
            ->with($this->callback(fn (FingerprintDevice $bound) => $bound->is($device)), '101', $admin->id)
            ->willReturn(['success' => true, 'message' => 'User PIN 101 berhasil dihapus.']);
        $this->app->instance(SoapFingerprintService::class, $mock);

        $response = $this->actingAs($admin)->post(route('devices.delete-user', $device), [
            'pin' => '101',
            'confirm' => 'HAPUS 101',
        ]);

        $response->assertRedirect(route('devices.show', $device));
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'delete_machine_user',
            'entity_id' => $device->id,
        ]);
    }

    public function test_delete_machine_user_is_admin_only(): void
    {
        $operator = User::factory()->operator()->create();

        $device = FingerprintDevice::create([
            'name' => 'Mesin RBAC',
            'ip' => '127.0.0.1',
            'port' => 80,
            'comm_key' => '0',
            'timezone' => 'Asia/Jakarta',
            'status' => 'active',
            'secret_token' => 'token_rbac',
            'active' => true,
        ]);

        $this->actingAs($operator)
            ->post(route('devices.delete-user', $device), ['pin' => '1', 'confirm' => 'HAPUS 1'])
            ->assertStatus(403);
    }

    public function test_push_user_info_sanitizes_special_characters_for_device(): void
    {
        $device = FingerprintDevice::create([
            'name' => 'Mesin Sanitasi',
            'ip' => '127.0.0.1',
            'port' => 64990,
            'comm_key' => '0',
            'timezone' => 'Asia/Jakarta',
            'status' => 'offline',
            'secret_token' => 'token_sanitasi',
            'active' => true,
        ]);

        $service = $this->fakeSoapService();
        $service->simulatedResponse = '<SetUserInfoResponse><Information>Success!</Information></SetUserInfoResponse>';

        // Apostrof tipografis + karakter XML (ampersand) harus lolos sebagai XML valid.
        $original = "Nur\u{2019}aini & Budi (A)";
        $result = $service->pushUserInfo($device, 505, $original);

        $this->assertTrue($result['success']);

        $xml = $service->requests[0];
        $this->assertStringContainsString('<Name>Nur&apos;aini &amp; Budi (A)</Name>', $xml);

        // Nama asli tetap utuh untuk laporan/jejak audit.
        $log = DeviceSyncLog::where('device_id', $device->id)
            ->where('sync_type', 'push_user')
            ->first();
        $this->assertSame($original, $log->details['name']);
        $this->assertSame("Nur'aini & Budi (A)", $log->details['sent_name']);
    }

    public function test_push_user_info_transliterates_and_truncates_name_for_device(): void
    {
        $device = FingerprintDevice::create([
            'name' => 'Mesin Translit',
            'ip' => '127.0.0.1',
            'port' => 64989,
            'comm_key' => '0',
            'timezone' => 'Asia/Jakarta',
            'status' => 'offline',
            'secret_token' => 'token_translit',
            'active' => true,
        ]);

        $service = $this->fakeSoapService();
        $service->simulatedResponse = '<SetUserInfoResponse><Information>Success!</Information></SetUserInfoResponse>';

        $service->pushUserInfo($device, 506, "Jos\u{00E9} Caf\u{00E9}");
        $this->assertStringContainsString('<Name>Jose Cafe</Name>', $service->requests[0]);

        // Nama panjang dipotong maksimum 24 byte agar diterima memori mesin.
        $service->pushUserInfo($device, 507, str_repeat('A', 40));
        $sentName = $service->parseTag($service->requests[2], '<Name>', '</Name>');
        $this->assertSame(24, strlen(html_entity_decode($sentName, ENT_XML1 | ENT_QUOTES, 'UTF-8')));
    }

    /**
     * @return SoapFingerprintService&object{requests: array<int, string>, simulatedResponse: ?string}
     */
    private function fakeSoapService(): SoapFingerprintService
    {
        return new class(app(FingerprintIngestService::class), app(AttendanceProcessingService::class), app(WhatsAppNotificationService::class)) extends SoapFingerprintService
        {
            /** @var array<int, string> */
            public array $requests = [];

            public ?string $simulatedResponse = null;

            public function sendSoapRequest(string $ip, int $port, string $soapXml, float $timeout = 3.0): ?string
            {
                $this->requests[] = $soapXml;

                return $this->simulatedResponse;
            }
        };
    }
}
