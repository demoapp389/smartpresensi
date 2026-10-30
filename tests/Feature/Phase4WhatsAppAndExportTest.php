<?php

namespace Tests\Feature;

use App\Jobs\SendWhatsAppNotificationJob;
use App\Models\Attendance;
use App\Models\Guardian;
use App\Models\NotificationLog;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use App\Services\WhatsAppNotificationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class Phase4WhatsAppAndExportTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Student $student;

    protected Guardian $guardian;

    protected Attendance $attendance;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role' => 'admin',
        ]);

        $class = SchoolClass::create([
            'name' => 'XII IPA 1',
            'academic_year' => '2026/2027',
        ]);

        $this->student = Student::create([
            'nis' => '10001',
            'name' => 'Budi Pratama',
            'class_id' => $class->id,
            'active' => true,
        ]);

        $this->guardian = Guardian::create([
            'student_id' => $this->student->id,
            'name' => 'Ayah Budi',
            'wa_number' => '6281234567890',
            'relationship' => 'ayah',
            'consent_at' => now(),
            'active' => true,
        ]);

        $this->attendance = Attendance::create([
            'student_id' => $this->student->id,
            'school_date' => Carbon::today('Asia/Jakarta'),
            'check_in_at' => Carbon::now('Asia/Jakarta'),
            'status' => 'hadir',
            'policy_version' => 'v1.0',
        ]);
    }

    public function test_wa_service_queues_notification_and_dispatches_job(): void
    {
        Queue::fake();

        $service = new WhatsAppNotificationService;
        $logs = $service->queueAttendanceNotifications($this->attendance, 'check_in');

        $this->assertCount(1, $logs);
        $this->assertEquals('queued', $logs[0]->status);
        $this->assertEquals('6281234567890', $logs[0]->recipient_phone);

        Queue::assertPushed(SendWhatsAppNotificationJob::class, function ($job) use ($logs) {
            return $job->notificationId === $logs[0]->id;
        });
    }

    public function test_wa_payload_uses_student_scan_time_not_outbox_time(): void
    {
        Queue::fake();

        $scanTime = Carbon::parse('2026-10-18 07:15:00', 'Asia/Jakarta');
        $this->attendance->update(['check_in_at' => $scanTime]);

        $service = new WhatsAppNotificationService;
        $logs = $service->queueAttendanceNotifications($this->attendance->fresh(), 'check_in');

        $this->assertCount(1, $logs);
        $this->assertSame('07:15 WIB', $logs[0]->payload['waktu']);
    }

    public function test_late_notification_uses_check_in_status_when_attendance_is_pending(): void
    {
        Queue::fake();

        $this->attendance->update([
            'status' => 'perlu_tinjauan',
            'check_in_status' => 'terlambat',
            'check_in_at' => Carbon::parse('2026-10-18 07:16:00', 'Asia/Jakarta'),
        ]);

        $logs = (new WhatsAppNotificationService)->queueAttendanceNotifications(
            $this->attendance->fresh(),
            'late'
        );

        $this->assertCount(1, $logs);
        $this->assertSame('siswa_terlambat_v1', $logs[0]->template_name);
        $this->assertSame('Terlambat', $logs[0]->payload['status']);
        $this->assertSame('07:16 WIB', $logs[0]->payload['waktu']);
    }

    public function test_wa_service_enforces_idempotency_no_duplicate_notifications(): void
    {
        Queue::fake();

        $service = new WhatsAppNotificationService;
        $logsFirst = $service->queueAttendanceNotifications($this->attendance, 'check_in');
        $this->assertCount(1, $logsFirst);

        // Panggilan kedua untuk event dan hari yang sama tidak boleh membuat notifikasi ganda (BR-06)
        $logsSecond = $service->queueAttendanceNotifications($this->attendance, 'check_in');
        $this->assertCount(0, $logsSecond);

        $this->assertEquals(1, NotificationLog::count());
    }

    public function test_send_wa_notification_job_executes_successfully_with_log_driver(): void
    {
        $log = NotificationLog::create([
            'attendance_id' => $this->attendance->id,
            'guardian_id' => $this->guardian->id,
            'event_type' => 'check_in',
            'idempotency_key' => 'TEST_CHECK_IN_'.uniqid(),
            'template_name' => 'siswa_hadir_v1',
            'recipient_phone' => '6281234567890',
            'payload' => [
                'nama_siswa' => $this->student->name,
                'kelas' => 'XII IPA 1',
                'tanggal' => now()->format('d/m/Y'),
                'waktu' => '07:00 WIB',
                'status' => 'Hadir',
            ],
            'status' => 'queued',
            'attempts' => 0,
            'queued_at' => now(),
        ]);

        $job = new SendWhatsAppNotificationJob($log->id);
        $job->handle();

        $log->refresh();
        $this->assertEquals('sent', $log->status);
        $this->assertEquals(1, $log->attempts);
        $this->assertNotNull($log->provider_message_id);
        $this->assertNotNull($log->sent_at);
    }

    public function test_operator_can_retry_failed_notification(): void
    {
        Queue::fake();

        $log = NotificationLog::create([
            'attendance_id' => $this->attendance->id,
            'guardian_id' => $this->guardian->id,
            'event_type' => 'check_in',
            'idempotency_key' => 'FAIL_KEY_'.uniqid(),
            'template_name' => 'siswa_hadir_v1',
            'recipient_phone' => '6281234567890',
            'status' => 'failed',
            'attempts' => 3,
            'error_message' => 'Network timeout',
        ]);

        $response = $this->actingAs($this->admin)->post(route('notifications.retry', $log->id));

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $log->refresh();
        $this->assertEquals('queued', $log->status);
        $this->assertNull($log->error_message);

        Queue::assertPushed(SendWhatsAppNotificationJob::class, function ($job) use ($log) {
            return $job->notificationId === $log->id;
        });
    }

    public function test_operator_can_send_test_message(): void
    {
        $response = $this->actingAs($this->admin)->post(route('notifications.test'), [
            'test_phone' => '081234567890',
            'test_message' => 'Tes pesan gateway absensi.',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
    }

    public function test_whatsapp_webhook_verification(): void
    {
        $challenge = 'test_hub_challenge_string_123';
        config()->set('whatsapp.meta.webhook_verify_token', 'test-verify-token');
        $verifyToken = 'test-verify-token';

        $response = $this->getJson(route('api.whatsapp.webhook.verify', [
            'hub_mode' => 'subscribe',
            'hub_verify_token' => $verifyToken,
            'hub_challenge' => $challenge,
        ]));

        $response->assertStatus(200);
        $this->assertEquals($challenge, $response->getContent());
    }

    public function test_whatsapp_webhook_delivery_receipt_updates_status(): void
    {
        $wamid = 'wamid.HBgTESTMSG123456';

        $log = NotificationLog::create([
            'attendance_id' => $this->attendance->id,
            'guardian_id' => $this->guardian->id,
            'event_type' => 'check_in',
            'idempotency_key' => 'KEY_DELIVERY_'.uniqid(),
            'template_name' => 'siswa_hadir_v1',
            'recipient_phone' => '6281234567890',
            'status' => 'sent',
            'provider_message_id' => $wamid,
        ]);

        $payload = [
            'entry' => [
                [
                    'changes' => [
                        [
                            'value' => [
                                'statuses' => [
                                    [
                                        'id' => $wamid,
                                        'status' => 'delivered',
                                        'timestamp' => time(),
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        config()->set('whatsapp.webhook_secret', 'test-webhook-secret');

        $response = $this->postJson(route('api.whatsapp.webhook.handle'), $payload, [
            'X-Webhook-Token' => 'test-webhook-secret',
        ]);
        $response->assertStatus(200);

        $log->refresh();
        $this->assertEquals('delivered', $log->status);
    }

    public function test_attendances_csv_export_returns_streamed_file_without_secrets(): void
    {
        $response = $this->actingAs($this->admin)->get(route('attendances.export', [
            'date' => Carbon::today('Asia/Jakarta')->toDateString(),
            'format' => 'csv',
        ]));

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $content = $response->streamedContent();
        $this->assertStringContainsString('Budi Pratama', $content);
        $this->assertStringContainsString('10001', $content);
        $this->assertStringContainsString('XII IPA 1', $content);
        $this->assertStringContainsString('HADIR', $content);
        $this->assertStringNotContainsString('secret_token', $content);
    }
}
