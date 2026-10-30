<?php

namespace Tests\Feature;

use App\Jobs\BulkDeviceOperation;
use App\Models\DeviceUser;
use App\Models\FingerprintDevice;
use App\Models\Student;
use App\Models\TaskProgress;
use App\Models\User;
use App\Services\SoapFingerprintService;
use App\Support\TaskProgressReporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DeviceOfflineActionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private FingerprintDevice $offlineDevice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->offlineDevice = $this->makeDevice('Mesin Offline', '192.168.1.210');
    }

    public function test_bulk_push_names_is_rejected_without_task_or_job_when_badge_offline(): void
    {
        Queue::fake();
        $this->mapStudent($this->offlineDevice, '8101', 'Siswa Offline');

        $response = $this->actingAs($this->admin)
            ->postJson(route('devices.bulk-push-names', $this->offlineDevice));

        $response->assertStatus(422);
        $this->assertStringContainsString('sedang offline', $response->json('message'));

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('task_progress', 0);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'dispatch_push_names']);
    }

    public function test_bulk_clone_is_rejected_when_source_offline(): void
    {
        Queue::fake();
        $target = $this->makeOnlineDevice('Mesin Target', '192.168.1.211');
        $this->mapStudent($this->offlineDevice, '8102', 'Siswa Sumber');

        $response = $this->actingAs($this->admin)
            ->postJson(route('devices.bulk-clone', $this->offlineDevice), [
                'target_device_id' => $target->id,
            ]);

        $response->assertStatus(422);
        $this->assertStringContainsString($this->offlineDevice->name, $response->json('message'));

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('task_progress', 0);
    }

    public function test_bulk_clone_is_rejected_when_target_offline(): void
    {
        Queue::fake();
        $source = $this->makeOnlineDevice('Mesin Sumber', '192.168.1.212');
        $this->mapStudent($source, '8103', 'Siswa Sumber');

        $response = $this->actingAs($this->admin)
            ->postJson(route('devices.bulk-clone', $source), [
                'target_device_id' => $this->offlineDevice->id,
            ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('Mesin target '.$this->offlineDevice->name, $response->json('message'));

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('task_progress', 0);
    }

    public function test_sync_students_json_is_rejected_without_task_or_job_when_offline(): void
    {
        Queue::fake();

        $response = $this->actingAs($this->admin)
            ->postJson(route('devices.sync-students', $this->offlineDevice));

        $response->assertStatus(422);
        $this->assertStringContainsString('sedang offline', $response->json('message'));

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('task_progress', 0);
    }

    public function test_sync_attendance_json_is_rejected_without_task_or_job_when_offline(): void
    {
        Queue::fake();

        $response = $this->actingAs($this->admin)
            ->postJson(route('devices.sync-attendances', $this->offlineDevice));

        $response->assertStatus(422);
        $this->assertStringContainsString('sedang offline', $response->json('message'));

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('task_progress', 0);
    }

    public function test_direct_soap_actions_are_rejected_when_offline(): void
    {
        $soap = $this->createMock(SoapFingerprintService::class);
        $soap->expects($this->never())->method('syncTime');
        $soap->expects($this->never())->method('pullAttendanceLogs');
        $soap->expects($this->never())->method('restartMachine');
        $soap->expects($this->never())->method('clearAttendanceLogs');
        $soap->expects($this->never())->method('deleteUser');
        $this->app->instance(SoapFingerprintService::class, $soap);

        $this->actingAs($this->admin)
            ->postJson(route('devices.sync-time', $this->offlineDevice))
            ->assertStatus(422);

        $this->actingAs($this->admin)
            ->postJson(route('devices.pull-soap', $this->offlineDevice))
            ->assertStatus(422);

        $this->actingAs($this->admin)
            ->postJson(route('devices.restart', $this->offlineDevice))
            ->assertStatus(422);

        $this->actingAs($this->admin)
            ->postJson(route('devices.clear-logs', $this->offlineDevice))
            ->assertStatus(422);

        $this->actingAs($this->admin)
            ->postJson(route('devices.delete-user', $this->offlineDevice), [
                'pin' => '8104',
                'confirm' => 'HAPUS 8104',
            ])
            ->assertStatus(422);

        $this->assertDatabaseMissing('audit_logs', ['action' => 'delete_machine_user']);
    }

    public function test_push_mapping_is_rejected_when_offline(): void
    {
        $mapping = $this->mapStudent($this->offlineDevice, '8105', 'Siswa Mapping');

        $soap = $this->createMock(SoapFingerprintService::class);
        $soap->expects($this->never())->method('pushUserInfo');
        $this->app->instance(SoapFingerprintService::class, $soap);

        $this->actingAs($this->admin)
            ->postJson(route('devices.mapping.push', $mapping))
            ->assertStatus(422);
    }

    public function test_bulk_job_stops_before_soap_when_device_is_offline(): void
    {
        $device = $this->makeOnlineDevice('Mesin Sumber', '192.168.1.213');
        $this->mapStudent($device, '8106', 'Siswa Job');

        $reporter = TaskProgressReporter::start('devices.push_names', 'Kirim Nama', 1, $this->admin->id);

        $device->update(['status' => 'offline']);

        $soap = $this->createMock(SoapFingerprintService::class);
        $soap->expects($this->never())->method('pushUserInfo');

        $job = new BulkDeviceOperation(
            BulkDeviceOperation::OP_PUSH_NAMES,
            $device->id,
            null,
            $this->admin->id,
            $reporter->uuid()
        );

        $job->handle($soap);

        $task = TaskProgress::where('uuid', $reporter->uuid())->firstOrFail();
        $this->assertSame(TaskProgress::STATUS_FAILED, $task->status);
        $this->assertStringContainsString('sedang offline', $task->message);
    }

    public function test_clone_job_stops_before_soap_when_target_is_offline(): void
    {
        $source = $this->makeOnlineDevice('Mesin Sumber', '192.168.1.214');
        $target = $this->makeDevice('Mesin Target', '192.168.1.215');
        $this->mapStudent($source, '8107', 'Siswa Clone');

        $reporter = TaskProgressReporter::start('devices.clone', 'Kloning', 1, $this->admin->id);

        $soap = $this->createMock(SoapFingerprintService::class);
        $soap->expects($this->never())->method('pushUserInfo');
        $soap->expects($this->never())->method('setUserTemplate');

        $job = new BulkDeviceOperation(
            BulkDeviceOperation::OP_CLONE,
            $source->id,
            $target->id,
            $this->admin->id,
            $reporter->uuid()
        );

        $job->handle($soap);

        $task = TaskProgress::where('uuid', $reporter->uuid())->firstOrFail();
        $this->assertSame(TaskProgress::STATUS_FAILED, $task->status);
        $this->assertStringContainsString('Mesin target '.$target->name, $task->message);
    }

    private function makeDevice(string $name, string $ip): FingerprintDevice
    {
        return FingerprintDevice::create([
            'name' => $name,
            'ip' => $ip,
            'port' => 4370,
            'comm_key' => '0',
            'timezone' => 'Asia/Jakarta',
            'status' => 'offline',
            'last_seen_at' => null,
            'secret_token' => 'token_'.str_replace('.', '_', $ip),
            'active' => true,
        ]);
    }

    private function makeOnlineDevice(string $name, string $ip): FingerprintDevice
    {
        $device = $this->makeDevice($name, $ip);
        $device->markOnline();

        return $device->refresh();
    }

    private function mapStudent(FingerprintDevice $device, string $pin, string $name): DeviceUser
    {
        $student = Student::create([
            'nis' => $pin,
            'name' => $name,
            'gender' => 'L',
            'active' => true,
        ]);

        return DeviceUser::create([
            'device_id' => $device->id,
            'machine_user_id' => $pin,
            'student_id' => $student->id,
        ]);
    }
}
