<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AttendanceLog;
use App\Models\DeviceUser;
use App\Models\FingerprintDevice;
use App\Models\SchoolClass;
use App\Models\SchoolSchedule;
use App\Models\Student;
use App\Services\WhatsAppNotificationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Kontrak HTTP yang dipakai C# Windows Connector Solution X601 (LAN):
 * resolusi perangkat dari header X-Device-Token, deduplikasi idempotent per
 * (device_id, source_key), heartbeat ping, dan penolakan token tidak valid.
 */
class FingerprintIngestControllerTest extends TestCase
{
    use RefreshDatabase;

    private function device(array $attributes = []): FingerprintDevice
    {
        return FingerprintDevice::create(array_merge([
            'name' => 'X601 Gerbang',
            'ip' => '192.168.1.'.random_int(10, 250),
            'port' => 4370,
            'comm_key' => '0',
            'timezone' => 'Asia/Jakarta',
            'status' => 'offline',
            'secret_token' => 'tok_'.uniqid(),
            'active' => true,
        ], $attributes));
    }

    private function payload(string $machineUserId = '7001', string $occurredAt = '2026-10-14 07:05:12'): array
    {
        return ['logs' => [[
            'machine_user_id' => $machineUserId,
            'occurred_at' => $occurredAt,
        ]]];
    }

    public function test_ingest_returns_401_when_no_token_is_provided(): void
    {
        $this->postJson('/api/v1/fingerprint/ingest', $this->payload())
            ->assertStatus(401)
            ->assertJson(['status' => 'error']);
    }

    public function test_ingest_returns_403_when_the_token_is_unknown(): void
    {
        $this->device();

        $this->postJson('/api/v1/fingerprint/ingest', $this->payload(), ['X-Device-Token' => 'tidak-ada'])
            ->assertStatus(403);
    }

    public function test_ingest_returns_403_when_the_device_is_inactive(): void
    {
        $device = $this->device(['active' => false]);

        $this->postJson('/api/v1/fingerprint/ingest', $this->payload(), ['X-Device-Token' => $device->secret_token])
            ->assertStatus(403);
    }

    public function test_ingest_returns_422_when_the_logs_payload_is_missing(): void
    {
        $device = $this->device();

        $this->postJson('/api/v1/fingerprint/ingest', [], ['X-Device-Token' => $device->secret_token])
            ->assertStatus(422)
            ->assertJsonValidationErrors('logs');
    }

    public function test_ingest_returns_422_when_a_log_has_no_identifier_and_no_time(): void
    {
        $device = $this->device();

        $this->postJson('/api/v1/fingerprint/ingest', ['logs' => [['foo' => 'bar']]], ['X-Device-Token' => $device->secret_token])
            ->assertStatus(422);
    }

    public function test_ingest_resolves_the_device_from_the_x_device_token(): void
    {
        $this->device(['name' => 'Mesin Lain']);
        $target = $this->device(['name' => 'Mesin Tujuan']);

        $this->postJson('/api/v1/fingerprint/ingest', $this->payload(), ['X-Device-Token' => $target->secret_token])
            ->assertOk()
            ->assertJsonPath('device.id', $target->id)
            ->assertJsonPath('summary.accepted', 1)
            ->assertJsonPath('summary.missing', [])
            ->assertJsonPath('summary.new_inserted', 1);

        $this->assertSame(1, AttendanceLog::where('device_id', $target->id)->count());
    }

    public function test_ingest_marks_a_new_log_unmapped_for_an_unknown_machine_user(): void
    {
        $device = $this->device();

        $this->postJson('/api/v1/fingerprint/ingest', $this->payload('9999'), ['X-Device-Token' => $device->secret_token])
            ->assertOk()
            ->assertJsonPath('summary.received', 1)
            ->assertJsonPath('summary.new_inserted', 1)
            ->assertJsonPath('summary.unmapped_logs', 1);

        $log = AttendanceLog::where('device_id', $device->id)->firstOrFail();
        $this->assertSame('unmapped', $log->status);
        $this->assertSame('9999', $log->machine_user_id);
    }

    public function test_ingest_deduplicates_a_repeated_log(): void
    {
        $device = $this->device();
        $headers = ['X-Device-Token' => $device->secret_token];

        $this->postJson('/api/v1/fingerprint/ingest', $this->payload(), $headers)->assertOk();

        $this->postJson('/api/v1/fingerprint/ingest', $this->payload(), $headers)
            ->assertOk()
            ->assertJsonPath('summary.accepted', 1)
            ->assertJsonPath('summary.missing', [])
            ->assertJsonPath('summary.new_inserted', 0)
            ->assertJsonPath('summary.duplicates_skipped', 1);

        $this->assertSame(1, AttendanceLog::where('device_id', $device->id)->count());
    }

    public function test_ingest_accepts_the_token_as_a_bearer_header(): void
    {
        $device = $this->device();

        $this->postJson('/api/v1/fingerprint/ingest', $this->payload(), ['Authorization' => 'Bearer '.$device->secret_token])
            ->assertOk();
    }

    public function test_ingest_marks_the_device_online(): void
    {
        $device = $this->device(['status' => 'offline']);

        $this->postJson('/api/v1/fingerprint/ingest', $this->payload(), ['X-Device-Token' => $device->secret_token])->assertOk();

        $device->refresh();
        $this->assertSame('active', $device->status);
        $this->assertNotNull($device->last_seen_at);
    }

    public function test_checkout_only_scan_does_not_queue_whatsapp_notification(): void
    {
        $this->mock(WhatsAppNotificationService::class, function ($mock): void {
            $mock->shouldNotReceive('queueAttendanceNotifications');
        });

        $device = $this->device();
        $class = SchoolClass::create(['name' => 'X-RPL 5', 'academic_year' => '2026/2027']);
        $student = Student::create([
            'nis' => '7001',
            'name' => 'Siswa Checkout Saja',
            'gender' => 'L',
            'class_id' => $class->id,
            'active' => true,
        ]);
        DeviceUser::create([
            'device_id' => $device->id,
            'machine_user_id' => '7001',
            'student_id' => $student->id,
        ]);

        $this->travelTo(Carbon::parse('2026-10-28 14:06:00', 'Asia/Jakarta'));
        $today = Carbon::today('Asia/Jakarta');

        SchoolSchedule::create([
            'day_pattern' => 'regular',
            'day_name' => $today->translatedFormat('l'),
            'day_of_week' => $today->dayOfWeekIso,
            'check_in_start' => '06:00:00',
            'start_at' => '07:00:00',
            'late_after' => '07:15:00',
            'check_out_start' => '14:00:00',
            'end_at' => '16:00:00',
            'is_active' => true,
        ]);

        $this->postJson(
            '/api/v1/fingerprint/ingest',
            $this->payload('7001', $today->toDateString().' 14:05:00'),
            ['X-Device-Token' => $device->secret_token]
        )->assertOk()->assertJsonPath('summary.attendances_processed', 1);

        $attendance = Attendance::where('student_id', $student->id)->firstOrFail();

        $this->assertSame('perlu_tinjauan', $attendance->status);
        $this->assertNull($attendance->check_in_at);
        $this->assertSame('perlu_verifikasi', $attendance->check_out_status);
        $this->assertDatabaseCount('notification_logs', 0);
    }

    public function test_ping_returns_ok_and_marks_the_device_online(): void
    {
        $device = $this->device(['status' => 'offline']);

        $this->getJson('/api/v1/fingerprint/ping', ['X-Device-Token' => $device->secret_token])
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('device', $device->name);

        $this->assertSame('active', $device->refresh()->status);
    }

    public function test_ping_returns_401_when_the_token_is_unknown(): void
    {
        $this->getJson('/api/v1/fingerprint/ping', ['X-Device-Token' => 'tidak-ada'])
            ->assertStatus(401);
    }
}
