<?php

namespace Tests\Feature;

use App\Jobs\SendWhatsAppNotificationJob;
use App\Models\Attendance;
use App\Models\Guardian;
use App\Models\NotificationLog;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class NotificationBulkOperationsTest extends TestCase
{
    use RefreshDatabase;

    protected User $operator;

    protected NotificationLog $notifFailed1;

    protected NotificationLog $notifFailed2;

    protected NotificationLog $notifDelivered;

    protected function setUp(): void
    {
        parent::setUp();

        $this->operator = User::factory()->operator()->create();

        $class = SchoolClass::create([
            'name' => 'IX-A',
            'academic_year' => '2026/2027',
        ]);

        $student = Student::create([
            'nis' => '3001',
            'name' => 'Doni Kusuma',
            'class_id' => $class->id,
            'gender' => 'L',
            'active' => true,
        ]);

        $guardian = Guardian::create([
            'student_id' => $student->id,
            'name' => 'Bapak Doni',
            'relationship' => 'ayah',
            'wa_number' => '628123456789',
            'consent_at' => now(),
            'active' => true,
        ]);

        $attendance = Attendance::create([
            'student_id' => $student->id,
            'school_date' => Carbon::today('Asia/Jakarta')->toDateString(),
            'status' => 'hadir',
            'policy_version' => 'v1.0',
        ]);

        $this->notifFailed1 = NotificationLog::create([
            'attendance_id' => $attendance->id,
            'guardian_id' => $guardian->id,
            'event_type' => 'check_in',
            'idempotency_key' => 'FAIL1_'.uniqid(),
            'template_name' => 'check_in',
            'recipient_phone' => $guardian->wa_number,
            'payload' => ['student' => $student->name],
            'status' => 'failed',
            'attempts' => 3,
            'error_message' => 'Connection timeout',
        ]);

        $this->notifFailed2 = NotificationLog::create([
            'attendance_id' => $attendance->id,
            'guardian_id' => $guardian->id,
            'event_type' => 'check_in',
            'idempotency_key' => 'FAIL2_'.uniqid(),
            'template_name' => 'check_in',
            'recipient_phone' => $guardian->wa_number,
            'payload' => ['student' => $student->name],
            'status' => 'failed',
            'attempts' => 3,
            'error_message' => 'Quota exhausted',
        ]);

        $this->notifDelivered = NotificationLog::create([
            'attendance_id' => $attendance->id,
            'guardian_id' => $guardian->id,
            'event_type' => 'check_in',
            'idempotency_key' => 'OK_'.uniqid(),
            'template_name' => 'check_in',
            'recipient_phone' => $guardian->wa_number,
            'payload' => ['student' => $student->name],
            'status' => 'delivered',
            'attempts' => 1,
        ]);
    }

    public function test_can_retry_all_failed_notifications(): void
    {
        Queue::fake();

        $response = $this->actingAs($this->operator)->post(route('notifications.retry-all-failed'));

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->notifFailed1->refresh();
        $this->notifFailed2->refresh();
        $this->notifDelivered->refresh();

        $this->assertSame('queued', $this->notifFailed1->status);
        $this->assertNull($this->notifFailed1->error_message);

        $this->assertSame('queued', $this->notifFailed2->status);
        $this->assertNull($this->notifFailed2->error_message);

        // Pesan yang sudah delivered tidak boleh berubah
        $this->assertSame('delivered', $this->notifDelivered->status);

        Queue::assertPushed(SendWhatsAppNotificationJob::class, 2);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'retry_all_failed_whatsapp_notifications',
            'entity_type' => 'NotificationLog',
        ]);
    }

    public function test_can_bulk_retry_selected_failed_notifications(): void
    {
        Queue::fake();

        $response = $this->actingAs($this->operator)->post(route('notifications.bulk-retry'), [
            'ids' => [$this->notifFailed1->id],
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->notifFailed1->refresh();
        $this->notifFailed2->refresh();

        $this->assertSame('queued', $this->notifFailed1->status);
        $this->assertSame('failed', $this->notifFailed2->status);

        Queue::assertPushed(SendWhatsAppNotificationJob::class, 1);
    }

    public function test_bulk_retry_ignores_delivered_notifications(): void
    {
        Queue::fake();

        $response = $this->actingAs($this->operator)->post(route('notifications.bulk-retry'), [
            'ids' => [$this->notifDelivered->id],
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error');

        Queue::assertNothingPushed();
    }

    public function test_can_bulk_destroy_notifications(): void
    {
        $response = $this->actingAs($this->operator)->delete(route('notifications.bulk-destroy'), [
            'ids' => [$this->notifFailed1->id, $this->notifFailed2->id],
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseMissing('notification_logs', ['id' => $this->notifFailed1->id]);
        $this->assertDatabaseMissing('notification_logs', ['id' => $this->notifFailed2->id]);
        $this->assertDatabaseHas('notification_logs', ['id' => $this->notifDelivered->id]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'bulk_delete_whatsapp_notifications',
            'entity_type' => 'NotificationLog',
        ]);
    }

    public function test_index_stats_match_status_aggregation(): void
    {
        $response = $this->actingAs($this->operator)->get(route('notifications.index'));

        $response->assertOk();

        $response->assertViewHas('stats', function (array $stats) {
            $count = fn (?string $status = null) => NotificationLog::query()
                ->when($status !== null, fn ($q) => $q->where('status', $status))
                ->count();

            return $stats === [
                'total' => $count(),
                'queued' => $count('queued'),
                'sent' => $count('sent'),
                'delivered' => $count('delivered'),
                'read' => $count('read'),
                'failed' => $count('failed'),
            ];
        });
    }

    public function test_outbox_displays_indonesian_delivery_status_labels(): void
    {
        NotificationLog::create([
            'attendance_id' => $this->notifDelivered->attendance_id,
            'guardian_id' => $this->notifDelivered->guardian_id,
            'event_type' => 'check_in',
            'idempotency_key' => 'READ_'.uniqid(),
            'template_name' => 'check_in',
            'recipient_phone' => $this->notifDelivered->recipient_phone,
            'payload' => ['student' => 'Doni Kusuma'],
            'status' => 'read',
            'attempts' => 1,
        ]);

        $response = $this->actingAs($this->operator)->get(route('notifications.index'));

        $response->assertOk();
        $response->assertSee('Diterima');
        $response->assertSee('Dibaca');
        $response->assertDontSee('Delivered');
        $response->assertDontSee('Failed');
    }

    public function test_status_counts_helper_groups_by_status(): void
    {
        $counts = NotificationLog::statusCounts();

        $this->assertSame(2, (int) $counts->get('failed'));
        $this->assertSame(1, (int) $counts->get('delivered'));
        $this->assertNull($counts->get('queued'));
        $this->assertSame(3, (int) $counts->sum());
    }
}
