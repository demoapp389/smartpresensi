<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Guardian;
use App\Models\NotificationLog;
use App\Models\SchoolClass;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class WhatsAppWebhookStatusTest extends TestCase
{
    use RefreshDatabase;

    private Guardian $guardian;

    private Attendance $attendance;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('whatsapp.webhook_secret', 'shared-pass');
        config()->set('whatsapp.meta.app_secret', '');

        $class = SchoolClass::create([
            'name' => 'X-RPL 1',
            'academic_year' => '2026/2027',
        ]);

        $student = Student::create([
            'nis' => '9001',
            'name' => 'Siswa Webhook',
            'class_id' => $class->id,
            'active' => true,
        ]);

        $this->guardian = Guardian::create([
            'student_id' => $student->id,
            'name' => 'Wali Siswa',
            'relationship' => 'Ayah',
            'wa_number' => '6281234567890',
            'wa_status' => 'valid',
            'consent_at' => now(),
            'active' => true,
        ]);

        $this->attendance = Attendance::create([
            'student_id' => $student->id,
            'school_date' => now('Asia/Jakarta')->toDateString(),
            'status' => 'hadir',
            'check_in_at' => now('Asia/Jakarta'),
        ]);
    }

    public function test_status_never_moves_backwards(): void
    {
        $log = $this->notification(['status' => 'delivered', 'provider_message_id' => 'wamid-1']);

        $this->postWebhook(['id' => 'wamid-1', 'status' => 'sent'])->assertOk();

        $this->assertSame('delivered', $log->fresh()->status);
    }

    public function test_delivered_and_read_upgrade_sent_status(): void
    {
        $log = $this->notification(['status' => 'sent', 'provider_message_id' => 'wamid-2']);

        $this->postWebhook(['id' => 'wamid-2', 'status' => 'delivered'])->assertOk();
        $this->assertSame('delivered', $log->fresh()->status);

        $this->postWebhook(['id' => 'wamid-2', 'status' => 'read'])->assertOk();
        $this->assertSame('read', $log->fresh()->status);
    }

    public function test_failed_status_does_not_override_delivered_message(): void
    {
        $log = $this->notification(['status' => 'delivered', 'provider_message_id' => 'wamid-3']);

        $this->postWebhook([
            'id' => 'wamid-3',
            'status' => 'failed',
            'reason' => 'late failure event',
        ])->assertOk();

        $this->assertSame('delivered', $log->fresh()->status);
    }

    public function test_failed_status_overrides_queued_message_and_stores_reason(): void
    {
        $log = $this->notification(['status' => 'queued', 'provider_message_id' => 'wamid-4']);

        $this->postWebhook([
            'id' => 'wamid-4',
            'status' => 'failed',
            'reason' => 'Device disconnected',
        ])->assertOk();

        $log->refresh();
        $this->assertSame('failed', $log->status);
        $this->assertStringContainsString('Device disconnected', $log->error_message);
    }

    public function test_unknown_status_is_ignored(): void
    {
        $log = $this->notification(['status' => 'sent', 'provider_message_id' => 'wamid-5']);

        $this->postWebhook(['id' => 'wamid-5', 'status' => 'mystery'])->assertOk();

        $this->assertSame('sent', $log->fresh()->status);
    }

    public function test_fonnte_webhook_accepts_message_id_array(): void
    {
        $log = $this->notification(['status' => 'sent', 'provider_message_id' => 'wamid-6']);

        $this->postWebhook(['id' => ['wamid-6'], 'status' => 'delivered'])->assertOk();

        $this->assertSame('delivered', $log->fresh()->status);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function notification(array $attributes): NotificationLog
    {
        return NotificationLog::create(array_merge([
            'attendance_id' => $this->attendance->id,
            'guardian_id' => $this->guardian->id,
            'event_type' => 'check_in',
            'idempotency_key' => 'WEBHOOK_'.uniqid(),
            'template_name' => 'siswa_hadir_v1',
            'recipient_phone' => '6281234567890',
            'status' => 'queued',
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postWebhook(array $payload): TestResponse
    {
        return $this->postJson(route('api.whatsapp.webhook.handle'), $payload, [
            'X-Webhook-Token' => 'shared-pass',
        ]);
    }
}
