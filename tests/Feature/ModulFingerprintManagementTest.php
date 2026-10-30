<?php

namespace Tests\Feature;

use App\Jobs\SyncDeviceAttendance;
use App\Jobs\SyncDeviceUsers;
use App\Models\AttendanceLog;
use App\Models\DeviceSyncLog;
use App\Models\DeviceUser;
use App\Models\FingerprintDevice;
use App\Models\Student;
use App\Models\TaskProgress;
use App\Models\User;
use App\Services\DeviceLockService;
use App\Services\FingerprintConnectorService;
use App\Services\SoapFingerprintService;
use App\Support\TaskProgressReporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ModulFingerprintManagementTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected FingerprintDevice $device;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role' => 'admin',
        ]);

        $this->device = FingerprintDevice::create([
            'name' => 'Solution X601 Utama',
            'ip' => '192.168.1.201',
            'port' => 4370,
            'comm_key' => '0',
            'timezone' => 'Asia/Jakarta',
            'status' => 'active',
            'last_seen_at' => now(),
            'secret_token' => 'test_sec_token_123',
            'active' => true,
        ]);
    }

    public function test_admin_can_view_fingerprint_management_page_with_sync_history(): void
    {
        DeviceSyncLog::create([
            'device_id' => $this->device->id,
            'sync_type' => 'test_connection',
            'status' => 'success',
            'records_count' => 0,
            'message' => 'Tes koneksi berhasil via SDK.',
            'operator_id' => $this->admin->id,
        ]);

        // Halaman daftar mesin kini hanya menampilkan ringkasan per kartu.
        $response = $this->actingAs($this->admin)->get(route('devices.index'));
        $response->assertStatus(200);
        $response->assertSee('Solution X601 Utama', false);
        $response->assertSee('Tes Koneksi', false);
        $response->assertSee('Kelola Mesin', false);

        // Riwayat sinkronisasi tampil pada halaman terpisah.
        $historyResponse = $this->actingAs($this->admin)->get(route('devices.sync-history'));
        $historyResponse->assertStatus(200);
        $historyResponse->assertSee('Tes koneksi berhasil via SDK.', false);

        // Aksi sinkronisasi tersedia pada halaman detail mesin.
        $detailResponse = $this->actingAs($this->admin)->get(route('devices.show', $this->device));
        $detailResponse->assertStatus(200);
        $detailResponse->assertSee('Sinkronisasi Siswa', false);
        $detailResponse->assertSee('Sinkronisasi Absensi', false);
    }

    public function test_sync_students_automatically_upserts_students_without_duplicates(): void
    {
        // Mocking the ConnectorService call to simulate receiving users from machine
        $mockService = $this->createMock(FingerprintConnectorService::class);
        $mockService->expects($this->once())
            ->method('syncMachineUsers')
            ->willReturnCallback(function ($device, $operatorId, $createStudents) {
                // Simulasi 2 siswa dibaca dari mesin. PIN hanya disimpan pada mapping,
                // bukan diisi ke kolom NIS.
                $users = [
                    ['pin' => '2001', 'name' => 'Doni Firmansyah'],
                    ['pin' => '2002', 'name' => 'Citra Lestari'],
                ];

                foreach ($users as $u) {
                    $st = Student::create([
                        'nis' => null,
                        'name' => $u['name'],
                        'active' => true,
                    ]);

                    DeviceUser::create([
                        'device_id' => $device->id,
                        'machine_user_id' => $u['pin'],
                        'student_id' => $st->id,
                    ]);
                }

                DeviceSyncLog::create([
                    'device_id' => $device->id,
                    'sync_type' => 'sync_students',
                    'status' => 'success',
                    'records_count' => 2,
                    'message' => 'Sinkronisasi siswa berhasil. Total 2 siswa terbaca.',
                    'operator_id' => $operatorId,
                ]);

                return [
                    'success' => true,
                    'message' => 'Sinkronisasi siswa berhasil. Total 2 siswa terbaca.',
                    'total' => 2,
                    'new' => 2,
                    'updated' => 0,
                ];
            });

        $this->app->instance(FingerprintConnectorService::class, $mockService);

        $response = $this->actingAs($this->admin)
            ->post(route('devices.sync-students', $this->device), ['from_mappings' => true]);

        $response->assertRedirect(route('devices.mappings', ['device_id' => $this->device->id]));
        $response->assertSessionHas('success');

        // Pastikan siswa tersimpan tanpa duplikasi dan PIN tidak masuk ke NIS.
        $this->assertDatabaseHas('students', ['nis' => null, 'name' => 'Doni Firmansyah']);
        $this->assertDatabaseHas('students', ['nis' => null, 'name' => 'Citra Lestari']);
        $this->assertDatabaseMissing('students', ['nis' => '2001']);
        $this->assertDatabaseMissing('students', ['nis' => '2002']);
        $this->assertDatabaseHas('device_users', ['device_id' => $this->device->id, 'machine_user_id' => '2001']);
        $this->assertDatabaseHas('device_users', ['device_id' => $this->device->id, 'machine_user_id' => '2002']);

        // Pastikan riwayat sinkronisasi tercatat
        $this->assertDatabaseHas('device_sync_logs', [
            'device_id' => $this->device->id,
            'sync_type' => 'sync_students',
            'status' => 'success',
            'records_count' => 2,
        ]);
    }

    public function test_sync_students_option_defaults_to_false_when_checkbox_not_sent(): void
    {
        $capturedCreateStudents = [];

        $mockService = $this->createMock(FingerprintConnectorService::class);
        $mockService->expects($this->exactly(2))
            ->method('syncMachineUsers')
            ->willReturnCallback(function ($device, $operatorId, $createStudents) use (&$capturedCreateStudents) {
                $capturedCreateStudents[] = $createStudents;

                return [
                    'success' => true,
                    'message' => 'Sinkronisasi pengguna berhasil.',
                    'total' => 0,
                    'new' => 0,
                    'updated' => 0,
                    'students_created' => 0,
                    'unmapped' => 0,
                ];
            });

        $this->app->instance(FingerprintConnectorService::class, $mockService);

        $this->actingAs($this->admin)
            ->post(route('devices.sync-students', $this->device))
            ->assertRedirect(route('devices.show', $this->device));

        $this->actingAs($this->admin)
            ->post(route('devices.sync-students', $this->device), ['create_students' => '1'])
            ->assertRedirect(route('devices.show', $this->device));

        $this->assertSame([false, true], $capturedCreateStudents);
    }

    public function test_sync_students_json_dispatches_background_job(): void
    {
        Queue::fake();

        $response = $this->actingAs($this->admin)->postJson(
            route('devices.sync-students', $this->device),
            ['create_students' => '1']
        );

        $response->assertStatus(202);
        $response->assertJsonStructure(['uuid', 'progress_token', 'message']);
        Queue::assertPushed(SyncDeviceUsers::class, fn ($job) => $job->deviceId === $this->device->id);
        $this->assertDatabaseHas('task_progress', [
            'uuid' => $response->json('uuid'),
            'type' => 'devices.sync-students',
        ]);
    }

    public function test_sync_attendances_json_dispatches_background_job(): void
    {
        Queue::fake();

        $response = $this->actingAs($this->admin)->postJson(
            route('devices.sync-attendances', $this->device)
        );

        $response->assertStatus(202);
        $response->assertJsonStructure(['uuid', 'progress_token', 'message']);
        Queue::assertPushed(SyncDeviceAttendance::class, fn ($job) => $job->deviceId === $this->device->id);
        $this->assertDatabaseHas('task_progress', [
            'uuid' => $response->json('uuid'),
            'type' => 'devices.sync-attendance',
        ]);
    }

    public function test_sync_device_users_job_marks_progress_completed(): void
    {
        $reporter = TaskProgressReporter::start('devices.sync-students', 'Sinkronisasi Siswa', 1, $this->admin->id);

        $connector = $this->createMock(FingerprintConnectorService::class);
        $connector->expects($this->once())
            ->method('syncMachineUsers')
            ->willReturn([
                'success' => true,
                'message' => 'Sinkronisasi pengguna berhasil.',
                'new' => 2,
                'updated' => 0,
                'students_created' => 2,
                'unmapped' => 0,
            ]);

        (new SyncDeviceUsers($this->device->id, $this->admin->id, true, $reporter->uuid()))->handle($connector);

        $task = TaskProgress::where('uuid', $reporter->uuid())->firstOrFail();
        $this->assertSame(TaskProgress::STATUS_COMPLETED, $task->status);
        $this->assertSame(100, $task->percent());
    }

    public function test_sync_machine_users_reports_progress_per_user(): void
    {
        $reporter = TaskProgressReporter::start('devices.sync-students', 'Sinkronisasi Siswa', 0, $this->admin->id);

        $service = $this->makeConnectorServiceStub([
            ['pin' => '10101', 'name' => 'ABDUL RIPAL STIAWAN PUTR'],
            ['pin' => '10102', 'name' => 'ABY SIGIT'],
        ]);

        $service->syncMachineUsers($this->device, $this->admin->id, false, $reporter);

        $this->assertSame(2, $reporter->total());
        $this->assertSame(2, $reporter->processed());
    }

    public function test_sync_device_attendance_job_marks_progress_completed(): void
    {
        $this->device->update(['ingest_driver' => 'soap']);

        $reporter = TaskProgressReporter::start('devices.sync-attendance', 'Sinkronisasi Absensi', 1, $this->admin->id);

        $soap = $this->createMock(SoapFingerprintService::class);
        $soap->expects($this->once())
            ->method('pullAttendanceLogs')
            ->willReturn(['success' => true, 'message' => '5 data ditarik', 'new_inserted' => 5]);

        $connector = $this->createMock(FingerprintConnectorService::class);
        $connector->expects($this->never())->method('ensureAttendanceReachable');
        $connector->expects($this->never())->method('syncAttendances');

        $lock = $this->createMock(DeviceLockService::class);
        $lock->method('run')->willReturnCallback(
            fn ($device, $callback) => ['locked' => false, 'result' => $callback()]
        );

        (new SyncDeviceAttendance($this->device->id, $this->admin->id, $reporter->uuid()))
            ->handle($connector, $soap, $lock);

        $task = TaskProgress::where('uuid', $reporter->uuid())->firstOrFail();
        $this->assertSame(TaskProgress::STATUS_COMPLETED, $task->status);
        $this->assertSame(100, $task->percent());
    }

    public function test_sync_machine_users_creates_students_and_mappings_when_option_enabled(): void
    {
        $service = $this->makeConnectorServiceStub([
            ['pin' => '10101', 'name' => 'ABDUL RIPAL STIAWAN PUTR'],
            ['pin' => '10102', 'name' => 'ABY SIGIT'],
        ]);

        $result = $service->syncMachineUsers($this->device, $this->admin->id, true);

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['new']);
        $this->assertSame(2, $result['students_created']);
        $this->assertSame(0, $result['unmapped']);

        $this->assertDatabaseHas('students', ['nis' => null, 'name' => 'Siswa PIN 10101']);
        $this->assertDatabaseHas('students', ['nis' => null, 'name' => 'Siswa PIN 10102']);
        $this->assertDatabaseMissing('students', ['nis' => '10101']);
        $this->assertDatabaseMissing('students', ['nis' => '10102']);
        // Nama mesin tetap tersimpan sebagai petunjuk pemetaan, bukan sebagai nama siswa.
        $this->assertDatabaseHas('device_machine_users', [
            'device_id' => $this->device->id,
            'fingerprint_user_id' => '10101',
            'machine_name' => 'ABDUL RIPAL STIAWAN PUTR',
        ]);
        $this->assertDatabaseHas('device_machine_users', [
            'device_id' => $this->device->id,
            'fingerprint_user_id' => '10102',
        ]);
        $this->assertDatabaseHas('device_users', [
            'device_id' => $this->device->id,
            'machine_user_id' => '10101',
        ]);
    }

    public function test_sync_machine_users_is_idempotent_when_option_enabled(): void
    {
        $service = $this->makeConnectorServiceStub([
            ['pin' => '10101', 'name' => 'ABDUL RIPAL STIAWAN PUTR'],
        ]);

        $service->syncMachineUsers($this->device, $this->admin->id, true);

        $student = Student::firstOrFail();
        $mapping = DeviceUser::where('device_id', $this->device->id)
            ->where('machine_user_id', '10101')
            ->firstOrFail();

        // NIS diubah setelah sync pertama: mapping harus tetap menjadi acuan.
        $student->update(['nis' => '10101-REAL']);

        $second = $service->syncMachineUsers($this->device, $this->admin->id, true);
        $mapping->refresh();

        $this->assertSame(0, $second['new']);
        $this->assertSame(0, $second['students_created']);
        $this->assertSame(1, Student::count());
        $this->assertSame('10101-REAL', $student->fresh()->nis);
        $this->assertSame($student->id, $mapping->student_id);
        $this->assertSame(1, DeviceUser::where('device_id', $this->device->id)
            ->where('machine_user_id', '10101')
            ->count());
    }

    public function test_sync_machine_users_keeps_placeholder_name_and_normalizes_machine_name(): void
    {
        $service = $this->makeConnectorServiceStub([
            ['pin' => '20201', 'name' => 'Nur&apos;aini   &amp;  Budi'],
        ]);

        $result = $service->syncMachineUsers($this->device, $this->admin->id, true);

        $this->assertTrue($result['success']);

        // Nama siswa baru tetap placeholder; tidak diambil dari nama mesin.
        $student = Student::firstOrFail();
        $this->assertSame('Siswa PIN 20201', $student->name);

        // Entitas XML didekode & spasi dirapikan untuk petunjuk pemetaan.
        $this->assertDatabaseHas('device_machine_users', [
            'device_id' => $this->device->id,
            'fingerprint_user_id' => '20201',
            'machine_name' => "Nur'aini & Budi",
        ]);

        // Sinkronisasi ulang tidak boleh menimpa nama placeholder dengan nama mesin.
        $service->syncMachineUsers($this->device, $this->admin->id, true);
        $this->assertSame('Siswa PIN 20201', $student->fresh()->name);
    }

    public function test_sync_machine_users_does_not_create_students_when_option_disabled(): void
    {
        $service = $this->makeConnectorServiceStub([
            ['pin' => '10101', 'name' => 'ABDUL RIPAL STIAWAN PUTR'],
        ]);

        $result = $service->syncMachineUsers($this->device, $this->admin->id, false);

        $this->assertSame(0, $result['students_created']);
        $this->assertSame(1, $result['unmapped']);
        $this->assertDatabaseCount('students', 0);
        $this->assertDatabaseMissing('students', ['nis' => '10101']);
        $this->assertDatabaseHas('device_machine_users', [
            'device_id' => $this->device->id,
            'fingerprint_user_id' => '10101',
        ]);
    }

    public function test_sync_machine_users_remaps_unmapped_logs_when_option_enabled(): void
    {
        AttendanceLog::create([
            'device_id' => $this->device->id,
            'machine_user_id' => '10101',
            'occurred_at' => now()->subDay(),
            'received_at' => now(),
            'source_key' => 'x601_10101_1',
            'status' => 'unmapped',
            'raw_payload' => '{}',
        ]);

        $service = $this->makeConnectorServiceStub([
            ['pin' => '10101', 'name' => 'ABDUL RIPAL STIAWAN PUTR'],
        ]);

        $service->syncMachineUsers($this->device, $this->admin->id, true);

        $this->assertDatabaseHas('attendance_logs', [
            'device_id' => $this->device->id,
            'source_key' => 'x601_10101_1',
            'status' => 'unprocessed',
        ]);
    }

    public function test_sync_machine_users_keeps_unmapped_logs_when_option_disabled(): void
    {
        AttendanceLog::create([
            'device_id' => $this->device->id,
            'machine_user_id' => '10101',
            'occurred_at' => now()->subDay(),
            'received_at' => now(),
            'source_key' => 'x601_10101_1',
            'status' => 'unmapped',
            'raw_payload' => '{}',
        ]);

        $service = $this->makeConnectorServiceStub([
            ['pin' => '10101', 'name' => 'ABDUL RIPAL STIAWAN PUTR'],
        ]);

        $service->syncMachineUsers($this->device, $this->admin->id, false);

        $this->assertDatabaseHas('attendance_logs', [
            'device_id' => $this->device->id,
            'source_key' => 'x601_10101_1',
            'status' => 'unmapped',
        ]);
    }

    /**
     * Buat instance service asli dengan pre-check soket dan panggilan konektor di-stub.
     *
     * @param  array<int, array{pin: string, name?: string}>  $users
     */
    protected function makeConnectorServiceStub(array $users): FingerprintConnectorService
    {
        $service = $this->getMockBuilder(FingerprintConnectorService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['checkSocketReachable', 'callHttpApi'])
            ->getMock();

        $service->method('checkSocketReachable')->willReturn(true);
        $service->method('callHttpApi')->willReturn([
            'success' => true,
            'users' => $users,
        ]);

        return $service;
    }

    public function test_sync_machine_users_failure_records_sync_students_log(): void
    {
        $this->device->update(['status' => 'active']);

        $service = $this->getMockBuilder(FingerprintConnectorService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['checkSocketReachable'])
            ->getMock();

        $service->method('checkSocketReachable')->willReturn('Mesin tidak terhubung.');

        $result = $service->syncMachineUsers($this->device, $this->admin->id, false);

        $this->assertFalse($result['success']);
        $this->assertSame('Mesin tidak terhubung.', $result['message']);
        $this->assertDatabaseHas('device_sync_logs', [
            'device_id' => $this->device->id,
            'sync_type' => 'sync_students',
            'status' => 'failed',
            'records_count' => 0,
            'message' => 'Mesin tidak terhubung.',
            'operator_id' => $this->admin->id,
        ]);
        $this->assertSame('offline', $this->device->fresh()->status);
    }

    public function test_sync_attendances_records_sync_log(): void
    {
        $mockService = $this->createMock(FingerprintConnectorService::class);
        $mockService->expects($this->once())->method('ensureAttendanceReachable')->willReturn(true);
        $mockService->expects($this->once())
            ->method('syncAttendances')
            ->willReturnCallback(function ($device, $operatorId) {
                DeviceSyncLog::create([
                    'device_id' => $device->id,
                    'sync_type' => 'sync_attendances',
                    'status' => 'success',
                    'records_count' => 5,
                    'message' => 'Sinkronisasi absensi selesai. 5 log baru dimasukkan.',
                    'operator_id' => $operatorId,
                ]);

                return [
                    'success' => true,
                    'message' => 'Sinkronisasi absensi selesai. 5 log baru dimasukkan.',
                    'read_total' => 5,
                    'new_inserted' => 5,
                    'attendances_processed' => 5,
                ];
            });

        $this->app->instance(FingerprintConnectorService::class, $mockService);

        $response = $this->actingAs($this->admin)->post(route('devices.sync-attendances', $this->device));

        $response->assertRedirect(route('devices.show', $this->device));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('device_sync_logs', [
            'device_id' => $this->device->id,
            'sync_type' => 'sync_attendances',
            'status' => 'success',
            'records_count' => 5,
        ]);
    }

    public function test_sync_time_uses_connector_for_connector_device(): void
    {
        $mockService = $this->createMock(FingerprintConnectorService::class);
        $mockService->expects($this->once())
            ->method('syncTime')
            ->with($this->callback(fn ($device) => $device->id === $this->device->id), $this->admin->id)
            ->willReturn(['success' => true, 'message' => 'Jam mesin disinkronkan.', 'code' => 0]);

        $this->app->instance(FingerprintConnectorService::class, $mockService);

        $response = $this->actingAs($this->admin)->post(route('devices.sync-time', $this->device));

        $response->assertRedirect(route('devices.show', $this->device));
        $response->assertSessionHas('success', 'Jam mesin disinkronkan.');
    }

    public function test_sync_attendances_stops_with_single_log_when_device_unreachable(): void
    {
        $this->device->update(['ip' => '127.0.0.1', 'port' => 65000]);

        $soap = $this->createMock(SoapFingerprintService::class);
        $soap->expects($this->never())->method('pullAttendanceLogs');
        $this->app->instance(SoapFingerprintService::class, $soap);

        $response = $this->actingAs($this->admin)->post(route('devices.sync-attendances', $this->device));

        $response->assertRedirect(route('devices.show', $this->device));
        $response->assertSessionHas('error', 'Mesin '.$this->device->name.' tidak terhubung.');

        $this->assertDatabaseCount('device_sync_logs', 1);
        $this->assertDatabaseHas('device_sync_logs', [
            'device_id' => $this->device->id,
            'sync_type' => 'sync_attendances',
            'status' => 'failed',
            'message' => "Mesin {$this->device->name} (127.0.0.1:65000) tidak terhubung.",
        ]);
    }

    public function test_sync_attendance_job_stops_with_single_log_when_device_unreachable(): void
    {
        $this->device->update(['ip' => '127.0.0.1', 'port' => 65000]);

        $reporter = TaskProgressReporter::start('devices.sync-attendance', 'Sinkronisasi Absensi', 1, $this->admin->id);

        $soap = $this->createMock(SoapFingerprintService::class);
        $soap->expects($this->never())->method('pullAttendanceLogs');

        $lock = $this->createMock(DeviceLockService::class);
        $lock->expects($this->never())->method('run');

        (new SyncDeviceAttendance($this->device->id, $this->admin->id, $reporter->uuid()))
            ->handle($this->app->make(FingerprintConnectorService::class), $soap, $lock);

        $task = TaskProgress::where('uuid', $reporter->uuid())->firstOrFail();
        $this->assertSame(TaskProgress::STATUS_FAILED, $task->status);

        $this->assertDatabaseCount('device_sync_logs', 1);
        $this->assertDatabaseHas('device_sync_logs', [
            'device_id' => $this->device->id,
            'sync_type' => 'sync_attendances',
            'status' => 'failed',
        ]);
    }

    public function test_test_connection_action_records_failure_gracefully_when_offline(): void
    {
        $mockService = $this->createMock(FingerprintConnectorService::class);
        $mockService->expects($this->once())
            ->method('testConnection')
            ->willReturnCallback(function ($device, $operatorId) {
                DeviceSyncLog::create([
                    'device_id' => $device->id,
                    'sync_type' => 'test_connection',
                    'status' => 'failed',
                    'records_count' => 0,
                    'message' => 'Gagal terhubung ke mesin fisik Solution X601.',
                    'operator_id' => $operatorId,
                ]);

                return [
                    'success' => false,
                    'code' => -201,
                    'message' => 'Gagal terhubung ke mesin fisik Solution X601.',
                ];
            });

        $this->app->instance(FingerprintConnectorService::class, $mockService);

        $response = $this->actingAs($this->admin)->post(route('devices.test-connection', $this->device));

        $response->assertRedirect(route('devices.show', $this->device));
        $response->assertSessionHas('error');

        // Riwayat sinkronisasi mencatat status tes koneksi
        $this->assertDatabaseHas('device_sync_logs', [
            'device_id' => $this->device->id,
            'sync_type' => 'test_connection',
            'status' => 'failed',
        ]);
    }

    public function test_fingerprint_sync_artisan_command(): void
    {
        $mockService = $this->createMock(FingerprintConnectorService::class);
        $mockService->expects($this->once())
            ->method('syncAttendances')
            ->willReturn([
                'success' => true,
                'message' => 'Sinkronisasi berhasil.',
                'new_inserted' => 0,
            ]);

        $this->app->instance(FingerprintConnectorService::class, $mockService);

        $this->artisan('fingerprint:sync', ['--device' => $this->device->id])
            ->assertExitCode(0);
    }

    public function test_admin_can_update_fingerprint_device(): void
    {
        $response = $this->actingAs($this->admin)->put(route('devices.update', $this->device), [
            'name' => 'Mesin Fingerprint Baru',
            'ip' => '192.168.1.205',
            'port' => 4370,
            'comm_key' => '1234',
            'timezone' => 'Asia/Jakarta',
            'status' => 'active',
        ]);

        $response->assertRedirect(route('devices.show', $this->device));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('fingerprint_devices', [
            'id' => $this->device->id,
            'name' => 'Mesin Fingerprint Baru',
            'ip' => '192.168.1.205',
            'comm_key' => '1234',
            'status' => 'offline',
        ]);
    }

    public function test_admin_can_set_device_to_maintenance(): void
    {
        $response = $this->actingAs($this->admin)->put(route('devices.update', $this->device), [
            'name' => $this->device->name,
            'ip' => $this->device->ip,
            'port' => $this->device->port,
            'comm_key' => $this->device->comm_key,
            'timezone' => $this->device->timezone,
            'status' => 'maintenance',
        ]);

        $response->assertRedirect(route('devices.show', $this->device));
        $this->assertDatabaseHas('fingerprint_devices', [
            'id' => $this->device->id,
            'status' => 'maintenance',
        ]);
    }

    public function test_admin_cannot_register_second_device_with_duplicate_ip(): void
    {
        $response = $this->actingAs($this->admin)->post(route('devices.store'), [
            'name' => 'Mesin Fingerprint Kembar',
            'ip' => $this->device->ip,
            'port' => 4370,
            'comm_key' => '0',
            'timezone' => 'Asia/Jakarta',
        ]);

        $response->assertSessionHasErrors('ip');
        $this->assertSame(1, FingerprintDevice::count());
    }

    public function test_admin_cannot_change_device_ip_to_an_existing_device_ip(): void
    {
        $otherDevice = FingerprintDevice::create([
            'name' => 'Solution X601 Cadangan',
            'ip' => '192.168.1.202',
            'port' => 4370,
            'comm_key' => '0',
            'timezone' => 'Asia/Jakarta',
            'status' => 'offline',
            'secret_token' => 'test_sec_token_456',
            'active' => true,
        ]);

        $response = $this->actingAs($this->admin)->put(route('devices.update', $this->device), [
            'name' => $this->device->name,
            'ip' => $otherDevice->ip,
            'port' => 4370,
            'comm_key' => '0',
            'timezone' => 'Asia/Jakarta',
            'status' => 'active',
        ]);

        $response->assertSessionHasErrors('ip');
        $this->assertSame('192.168.1.201', $this->device->refresh()->ip);
    }

    public function test_admin_can_update_device_without_changing_its_own_ip(): void
    {
        $response = $this->actingAs($this->admin)->put(route('devices.update', $this->device), [
            'name' => 'Solution X601 Utama (Revisi)',
            'ip' => $this->device->ip,
            'port' => 4370,
            'comm_key' => '0',
            'timezone' => 'Asia/Jakarta',
            'status' => 'active',
        ]);

        $response->assertRedirect(route('devices.show', $this->device));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('fingerprint_devices', [
            'id' => $this->device->id,
            'name' => 'Solution X601 Utama (Revisi)',
            'ip' => '192.168.1.201',
        ]);
    }

    public function test_admin_can_delete_fingerprint_device(): void
    {
        $response = $this->actingAs($this->admin)->delete(route('devices.destroy', $this->device));

        $response->assertRedirect(route('devices.index'));
        $response->assertSessionHas('success');

        $this->assertDatabaseMissing('fingerprint_devices', [
            'id' => $this->device->id,
        ]);
    }
}
