<?php

namespace Tests\Feature;

use App\Jobs\VerifyFingerprintTemplates;
use App\Models\DeviceMachineUser;
use App\Models\DeviceUser;
use App\Models\FingerprintDevice;
use App\Models\Student;
use App\Models\TaskProgress;
use App\Models\User;
use App\Services\DeviceLockService;
use App\Services\FingerprintConnectorService;
use App\Services\FingerprintTemplateVerificationService;
use App\Support\TaskProgressReporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Tests\TestCase;

class FingerprintTemplateVerificationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private FingerprintDevice $device;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();

        $this->device = FingerprintDevice::create([
            'name' => 'Solution X601 - Gerbang Depan',
            'ip' => '192.168.1.201',
            'port' => 4370,
            'comm_key' => '0',
            'timezone' => 'Asia/Jakarta',
            'status' => 'active',
            'last_seen_at' => now(),
            'secret_token' => 'x601_sec_templateverification',
            'active' => true,
            'ingest_driver' => 'connector',
        ]);
    }

    public function test_verification_job_stores_template_status_for_each_pin(): void
    {
        $hasTemplate = DeviceMachineUser::create([
            'device_id' => $this->device->id,
            'fingerprint_user_id' => '1001',
            'machine_name' => 'Siswa Punya Sidik Jari',
        ]);

        $missingTemplate = DeviceMachineUser::create([
            'device_id' => $this->device->id,
            'fingerprint_user_id' => '1002',
            'machine_name' => 'Siswa Tanpa Sidik Jari',
        ]);

        $this->mock(FingerprintConnectorService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('supportsTemplateVerification')->andReturnTrue();
            $mock->shouldReceive('checkUserTemplate')->andReturnUsing(
                fn (FingerprintDevice $device, string $pin): array => $pin === '1001'
                    ? ['success' => true, 'has_template' => true, 'fingers' => 1, 'size' => 512, 'message' => 'Ada.', 'code' => 0]
                    : ['success' => true, 'has_template' => false, 'fingers' => 0, 'size' => 0, 'message' => 'Tidak ada.', 'code' => 0]
            );
        });

        $reporter = TaskProgressReporter::start('devices.verify-templates', 'Verifikasi Sidik Jari', 0, $this->admin->id);

        $job = new VerifyFingerprintTemplates($this->device->id, $this->admin->id, $reporter->uuid());
        $job->handle(
            $this->app->make(FingerprintTemplateVerificationService::class),
            $this->app->make(DeviceLockService::class),
        );

        $hasTemplate->refresh();
        $missingTemplate->refresh();

        $this->assertTrue($hasTemplate->has_template);
        $this->assertFalse($missingTemplate->has_template);
        $this->assertSame('connector', $hasTemplate->template_check_source);
        $this->assertNotNull($hasTemplate->template_checked_at);

        $task = TaskProgress::where('uuid', $reporter->uuid())->firstOrFail();
        $this->assertSame(TaskProgress::STATUS_COMPLETED, $task->status);
        $this->assertStringContainsString('1 PIN punya sidik jari', (string) $task->message);
    }

    public function test_verification_stops_after_repeated_connection_failures(): void
    {
        foreach (['2001', '2002', '2003', '2004'] as $pin) {
            DeviceMachineUser::create([
                'device_id' => $this->device->id,
                'fingerprint_user_id' => $pin,
                'machine_name' => 'PIN '.$pin,
            ]);
        }

        $this->mock(FingerprintConnectorService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('supportsTemplateVerification')->andReturnTrue();
            $mock->shouldReceive('checkUserTemplate')->andReturn([
                'success' => false,
                'has_template' => null,
                'message' => 'Mesin tidak terhubung.',
                'code' => -201,
            ]);
        });

        $result = $this->app->make(FingerprintTemplateVerificationService::class)->verifyDevice($this->device);

        $this->assertFalse($result['success']);
        $this->assertSame(0, $result['checked']);
        $this->assertDatabaseHas('fingerprint_devices', [
            'id' => $this->device->id,
            'status' => 'offline',
        ]);
        $this->assertSame(0, DeviceMachineUser::whereNotNull('has_template')->count());
    }

    public function test_verification_reports_when_connector_does_not_support_templates(): void
    {
        DeviceMachineUser::create([
            'device_id' => $this->device->id,
            'fingerprint_user_id' => '3001',
            'machine_name' => 'PIN 3001',
        ]);

        $this->mock(FingerprintConnectorService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('supportsTemplateVerification')->andReturnFalse();
        });

        $result = $this->app->make(FingerprintTemplateVerificationService::class)->verifyDevice($this->device);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('belum mendukung', $result['message']);
    }

    public function test_admin_can_queue_template_verification_with_progress(): void
    {
        Queue::fake();

        $response = $this->actingAs($this->admin)
            ->postJson(route('devices.verify-templates', $this->device));

        $response->assertStatus(202);
        $response->assertJson(['type' => 'devices.verify-templates']);
        Queue::assertPushed(VerifyFingerprintTemplates::class, fn (VerifyFingerprintTemplates $job) => $job->deviceId === $this->device->id);
    }

    public function test_operator_cannot_queue_template_verification(): void
    {
        $operator = User::factory()->operator()->create();

        $this->actingAs($operator)
            ->postJson(route('devices.verify-templates', $this->device))
            ->assertStatus(403);
    }

    public function test_students_index_shows_fingerprint_badge_and_supports_status_filter(): void
    {
        $studentWithTemplate = $this->makeStudent('Siswa Punya', '5001');
        $studentWithoutTemplate = $this->makeStudent('Siswa Tanpa', '5002');
        $studentUnknown = $this->makeStudent('Siswa Cek', '5003');

        $this->makeMachineUser($studentWithTemplate, '5001', true);
        $this->makeMachineUser($studentWithoutTemplate, '5002', false);
        $this->makeMachineUser($studentUnknown, '5003', null);

        $index = $this->actingAs($this->admin)->get(route('students.index'));
        $index->assertStatus(200);
        $index->assertSee('Ada sidik jari', false);
        $index->assertSee('Belum ada sidik jari', false);
        $index->assertSee('Belum diverifikasi', false);

        $this->actingAs($this->admin)
            ->get(route('students.index', ['fingerprint_status' => 'has']))
            ->assertStatus(200)
            ->assertSee('Siswa Punya', false)
            ->assertDontSee('Siswa Tanpa', false)
            ->assertDontSee('Siswa Cek', false);

        $this->actingAs($this->admin)
            ->get(route('students.index', ['fingerprint_status' => 'missing']))
            ->assertStatus(200)
            ->assertSee('Siswa Tanpa', false)
            ->assertDontSee('Siswa Punya', false)
            ->assertDontSee('Siswa Cek', false);

        $this->actingAs($this->admin)
            ->get(route('students.index', ['fingerprint_status' => 'unknown']))
            ->assertStatus(200)
            ->assertSee('Siswa Cek', false)
            ->assertDontSee('Siswa Punya', false)
            ->assertDontSee('Siswa Tanpa', false);
    }

    public function test_mappings_page_shows_fingerprint_status_for_machine_users(): void
    {
        $student = $this->makeStudent('Siswa Terpetakan', '6001');

        DeviceUser::create([
            'device_id' => $this->device->id,
            'student_id' => $student->id,
            'machine_user_id' => '6001',
        ]);

        DeviceMachineUser::create([
            'device_id' => $this->device->id,
            'fingerprint_user_id' => '6001',
            'machine_name' => 'Siswa Terpetakan',
            'has_template' => true,
            'template_checked_at' => now(),
            'template_check_source' => 'connector',
        ]);

        $this->actingAs($this->admin)
            ->get(route('devices.mappings', ['tab' => 'mapped', 'device_id' => $this->device->id]))
            ->assertStatus(200)
            ->assertSee('Sidik Jari', false)
            ->assertSee('Ada sidik jari', false);
    }

    public function test_device_detail_shows_template_summary_and_verification_button(): void
    {
        DeviceMachineUser::create([
            'device_id' => $this->device->id,
            'fingerprint_user_id' => '7001',
            'machine_name' => 'PIN 7001',
            'has_template' => false,
            'template_checked_at' => now(),
            'template_check_source' => 'connector',
        ]);

        $this->actingAs($this->admin)
            ->get(route('devices.show', $this->device))
            ->assertStatus(200)
            ->assertSee('Verifikasi Sidik Jari', false)
            ->assertSee('1 belum ada', false)
            ->assertSee(route('devices.verify-templates', $this->device), false);
    }

    private function makeStudent(string $name, string $nis): Student
    {
        return Student::create([
            'nis' => $nis,
            'name' => $name,
            'active' => true,
        ]);
    }

    private function makeMachineUser(Student $student, string $pin, ?bool $hasTemplate): void
    {
        DeviceUser::create([
            'device_id' => $this->device->id,
            'student_id' => $student->id,
            'machine_user_id' => $pin,
        ]);

        DeviceMachineUser::create([
            'device_id' => $this->device->id,
            'fingerprint_user_id' => $pin,
            'machine_name' => $student->name,
            'has_template' => $hasTemplate,
            'template_checked_at' => $hasTemplate === null ? null : now(),
            'template_check_source' => $hasTemplate === null ? null : 'connector',
        ]);
    }
}
