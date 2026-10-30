<?php

namespace Tests\Feature;

use App\Jobs\BulkDeviceOperation;
use App\Jobs\BulkValidateGuardianPhones;
use App\Jobs\ImportStudentsFromExcel;
use App\Jobs\SyncAllDevicesAttendance;
use App\Models\DeviceUser;
use App\Models\FingerprintDevice;
use App\Models\Guardian;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\TaskProgress;
use App\Models\User;
use App\Services\SimpleXlsxExporter;
use App\Services\SoapFingerprintService;
use App\Support\TaskProgressReporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TaskProgressTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
    }

    public function test_progress_endpoint_returns_live_progress_state(): void
    {
        $reporter = TaskProgressReporter::start('test.op', 'Uji Coba', 10, $this->admin->id);

        $reporter->setTotal(10);
        $reporter->advance(4);
        $reporter->advance();
        $reporter->flushProgress();

        $response = $this->getJson(route('api.progress.show', ['uuid' => $reporter->uuid(), 'token' => $reporter->signature()]));

        $response->assertOk();
        $response->assertJson([
            'status' => 'running',
            'processed' => 5,
            'total' => 10,
            'percent' => 50,
            'finished' => false,
        ]);
    }

    public function test_progress_endpoint_returns_404_for_unknown_uuid(): void
    {
        $this->getJson(route('api.progress.show', '00000000-0000-0000-0000-000000000000'))
            ->assertNotFound();
    }

    public function test_progress_endpoint_rejects_missing_or_invalid_token(): void
    {
        $reporter = TaskProgressReporter::start('test.op', 'Uji Token', 3, $this->admin->id);

        $this->getJson(route('api.progress.show', $reporter->uuid()))
            ->assertForbidden();

        $this->getJson(route('api.progress.show', ['uuid' => $reporter->uuid(), 'token' => 'token-palsu']))
            ->assertForbidden();
    }

    public function test_finished_task_always_reports_one_hundred_percent(): void
    {
        $reporter = TaskProgressReporter::start('test.op', 'Uji Selesai', 0, $this->admin->id);
        $reporter->finish(['ok' => 1], 'Selesai');

        $this->getJson(route('api.progress.show', ['uuid' => $reporter->uuid(), 'token' => $reporter->signature()]))
            ->assertOk()
            ->assertJson([
                'status' => 'completed',
                'percent' => 100,
                'finished' => true,
                'results' => ['ok' => 1],
            ]);
    }

    public function test_reporter_marks_failure_and_keeps_error_message(): void
    {
        $reporter = TaskProgressReporter::start('test.op', 'Uji Gagal', 5, $this->admin->id);
        $reporter->fail('Mesin sumber tidak ditemukan.');

        $this->getJson(route('api.progress.show', ['uuid' => $reporter->uuid(), 'token' => $reporter->signature()]))
            ->assertOk()
            ->assertJson([
                'status' => 'failed',
                'finished' => true,
                'message' => 'Mesin sumber tidak ditemukan.',
            ]);
    }

    public function test_import_endpoint_queues_job_and_returns_uuid(): void
    {
        Queue::fake();

        $tempPath = tempnam(sys_get_temp_dir(), 'progress_import_').'.xlsx';
        $download = SimpleXlsxExporter::download('dummy.xlsx', ['NIS', 'Nama Siswa'], [['1', 'Siswa Satu']]);
        copy($download->getFile()->getPathname(), $tempPath);

        $response = $this->actingAs($this->admin)->post(route('students.import'), [
            'file' => new UploadedFile(
                $tempPath,
                'data.xlsx',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                null,
                true
            ),
        ], ['Accept' => 'application/json']);

        $response->assertAccepted();
        $response->assertJsonStructure(['uuid', 'total']);

        $uuid = $response->json('uuid');
        $this->assertDatabaseHas('task_progress', [
            'uuid' => $uuid,
            'type' => 'students.import',
            'total' => 1,
        ]);

        Queue::assertPushed(ImportStudentsFromExcel::class);
    }

    public function test_import_job_records_progress_and_finishes_task(): void
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'progress_job_').'.xlsx';
        $download = SimpleXlsxExporter::download(
            'dummy.xlsx',
            ['NIS', 'Nama Siswa', 'Jenis Kelamin', 'Kelas', 'Nama Wali', 'Hubungan', 'No WA', 'PIN Mesin'],
            [
                ['7001', 'Siswa Satu', 'L', 'X-A1', 'Wali Satu', 'Ayah', '628111111111', ''],
                ['7002', 'Siswa Dua', 'P', 'X-A1', 'Wali Dua', 'Ibu', '628222222222', ''],
            ]
        );
        copy($download->getFile()->getPathname(), $tempPath);

        $reporter = TaskProgressReporter::start('students.import', 'Import Data Siswa', 2, $this->admin->id);

        (new ImportStudentsFromExcel($tempPath, 'data.xlsx', null, $this->admin->id, '127.0.0.1', $reporter->uuid()))
            ->handle();

        $task = TaskProgress::where('uuid', $reporter->uuid())->firstOrFail();

        $this->assertSame(TaskProgress::STATUS_COMPLETED, $task->status);
        $this->assertSame(2, $task->processed);
        $this->assertSame(100, $task->percent());
        $this->assertSame(['created' => 2, 'updated' => 0, 'mapped' => 0, 'skipped' => 0], $task->results);
        $this->assertDatabaseCount('students', 2);

        // Berkas unggahan dibersihkan setelah job selesai.
        $this->assertFileDoesNotExist($tempPath);
    }

    public function test_bulk_push_names_queues_job_instead_of_running_inline(): void
    {
        Queue::fake();

        $device = FingerprintDevice::create([
            'name' => 'Mesin A', 'ip' => '192.168.1.201', 'port' => 4370, 'comm_key' => '0', 'status' => 'active', 'last_seen_at' => now(), 'active' => true,
        ]);

        $this->createMappedStudent('7101', 'Siswa Satu');

        $response = $this->actingAs($this->admin)
            ->post(route('devices.bulk-push-names', $device), [], ['Accept' => 'application/json']);

        $response->assertAccepted();
        $this->assertDatabaseHas('task_progress', ['uuid' => $response->json('uuid')]);
        Queue::assertPushed(BulkDeviceOperation::class);
    }

    public function test_bulk_push_names_rejects_when_no_students_mapped(): void
    {
        Queue::fake();

        $device = FingerprintDevice::create([
            'name' => 'Mesin Kosong', 'ip' => '192.168.1.202', 'port' => 4370, 'comm_key' => '0', 'active' => true,
        ]);

        $this->actingAs($this->admin)
            ->post(route('devices.bulk-push-names', $device))
            ->assertRedirect(route('devices.show', $device))
            ->assertSessionHas('error');

        Queue::assertNothingPushed();
    }

    public function test_bulk_device_operation_completes_with_success_count(): void
    {
        $device = FingerprintDevice::create([
            'name' => 'Mesin Sumber', 'ip' => '192.168.1.203', 'port' => 4370, 'comm_key' => '0', 'status' => 'active', 'last_seen_at' => now(), 'active' => true,
        ]);

        $this->createMappedStudent('7201', 'Siswa Satu');
        $this->createMappedStudent('7202', 'Siswa Dua');

        $reporter = TaskProgressReporter::start('devices.push_names', 'Kirim Nama', 2, $this->admin->id);

        $job = new BulkDeviceOperation(
            BulkDeviceOperation::OP_PUSH_NAMES,
            $device->id,
            null,
            $this->admin->id,
            $reporter->uuid()
        );

        // Stub SOAP supaya job tidak menyentuh jaringan.
        $soap = $this->createMock(SoapFingerprintService::class);
        $soap->method('pushUserInfo')->willReturn(['success' => true]);
        $job->handle($soap);

        $task = TaskProgress::where('uuid', $reporter->uuid())->firstOrFail();

        $this->assertSame(TaskProgress::STATUS_COMPLETED, $task->status);
        $this->assertSame(2, $task->processed);
        $this->assertSame(100, $task->percent());
        $this->assertSame(['success' => 2, 'failed' => 0], $task->results);
    }

    public function test_bulk_device_operation_fails_when_source_device_missing(): void
    {
        $reporter = TaskProgressReporter::start('devices.push_names', 'Kirim Nama', 5, $this->admin->id);

        $job = new BulkDeviceOperation(BulkDeviceOperation::OP_PUSH_NAMES, 99999, null, $this->admin->id, $reporter->uuid());
        $job->handle($this->createMock(SoapFingerprintService::class));

        $task = TaskProgress::where('uuid', $reporter->uuid())->firstOrFail();

        $this->assertSame(TaskProgress::STATUS_FAILED, $task->status);
        $this->assertStringContainsString('tidak ditemukan', $task->message);
    }

    public function test_sync_all_devices_queues_background_job(): void
    {
        Queue::fake();

        FingerprintDevice::create([
            'name' => 'Mesin Aktif', 'ip' => '192.168.1.204', 'port' => 4370, 'comm_key' => '0', 'active' => true,
        ]);

        $response = $this->actingAs($this->admin)
            ->post(route('system.sync-all-devices'), [], ['Accept' => 'application/json']);

        $response->assertAccepted();
        $this->assertDatabaseHas('task_progress', ['uuid' => $response->json('uuid')]);
        Queue::assertPushed(SyncAllDevicesAttendance::class);
    }

    public function test_sync_all_devices_rejects_when_no_active_device(): void
    {
        Queue::fake();

        $this->actingAs($this->admin)
            ->post(route('system.sync-all-devices'))
            ->assertSessionHas('error');

        Queue::assertNothingPushed();
    }

    public function test_bulk_guardian_validation_queues_single_progress_task(): void
    {
        Queue::fake();

        $class = SchoolClass::create(['name' => 'X-B1', 'academic_year' => '2026/2027']);
        $student = Student::create([
            'nis' => '7301', 'name' => 'Siswa WA', 'gender' => 'L', 'class_id' => $class->id, 'active' => true,
        ]);

        Guardian::create([
            'student_id' => $student->id,
            'name' => 'Wali WA',
            'relationship' => 'Ayah',
            'wa_number' => '628333333333',
            'wa_status' => 'pending',
            'active' => true,
        ]);

        $response = $this->actingAs($this->admin)->post(route('guardians.bulk-validate'), [
            'scope' => 'pending',
            'limit' => 50,
            'async' => 1,
        ], ['Accept' => 'application/json']);

        $response->assertAccepted();

        $this->assertDatabaseHas('task_progress', [
            'uuid' => $response->json('uuid'),
            'type' => 'guardians.bulk-validate',
            'total' => 1,
        ]);

        Queue::assertPushed(BulkValidateGuardianPhones::class);
    }

    private function createMappedStudent(string $nis, string $name): Student
    {
        $class = SchoolClass::firstOrCreate(
            ['name' => 'X-P1'],
            ['academic_year' => '2026/2027']
        );

        $student = Student::create([
            'nis' => $nis,
            'name' => $name,
            'gender' => 'L',
            'class_id' => $class->id,
            'active' => true,
        ]);

        DeviceUser::create([
            'device_id' => FingerprintDevice::first()->id,
            'machine_user_id' => $nis,
            'student_id' => $student->id,
        ]);

        return $student;
    }
}
