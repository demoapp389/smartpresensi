<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Guardian;
use App\Models\NotificationLog;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use App\Models\WhatsAppSetting;
use App\Services\WhatsAppNotificationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WhatsAppAutoNotificationScopeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Student $student;

    private Guardian $guardian;

    private Attendance $attendance;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

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
            'check_in_status' => 'tepat_waktu',
            'status' => 'hadir',
            'policy_version' => 'v1.0',
        ]);
    }

    public function test_default_scope_notifies_guardians_of_all_students(): void
    {
        $logs = $this->queue('check_in');

        $this->assertCount(1, $logs);
        $this->assertDatabaseHas('notification_logs', [
            'guardian_id' => $this->guardian->id,
            'event_type' => 'check_in',
        ]);
    }

    public function test_late_scope_skips_on_time_student_notification(): void
    {
        WhatsAppSetting::current()->update(['auto_notification_scope' => WhatsAppSetting::SCOPE_LATE]);

        $logs = $this->queue('check_in');

        $this->assertCount(0, $logs);
        $this->assertDatabaseCount('notification_logs', 0);
    }

    public function test_late_scope_still_notifies_late_student_and_their_check_out(): void
    {
        WhatsAppSetting::current()->update(['auto_notification_scope' => WhatsAppSetting::SCOPE_LATE]);

        $this->attendance->update([
            'status' => 'terlambat',
            'check_in_status' => 'terlambat',
        ]);

        $this->assertCount(1, $this->queue('late'));

        $this->attendance->update([
            'check_out_at' => Carbon::now('Asia/Jakarta'),
            'check_out_status' => 'tepat_waktu',
        ]);

        $this->assertCount(1, $this->queue('check_out'));
    }

    public function test_late_scope_skips_check_out_of_on_time_student(): void
    {
        WhatsAppSetting::current()->update(['auto_notification_scope' => WhatsAppSetting::SCOPE_LATE]);

        $this->attendance->update([
            'check_out_at' => Carbon::now('Asia/Jakarta'),
            'check_out_status' => 'tepat_waktu',
        ]);

        $this->assertCount(0, $this->queue('check_out'));
    }

    public function test_admin_can_save_late_scope_and_it_is_audited(): void
    {
        $response = $this->actingAs($this->admin)->put(route('notifications.gateway.settings.update'), [
            'auto_notification_scope' => WhatsAppSetting::SCOPE_LATE,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('whatsapp_settings', [
            'auto_notification_scope' => WhatsAppSetting::SCOPE_LATE,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $this->admin->id,
            'action' => 'update_whatsapp_notification_scope',
        ]);

        $this->assertTrue(WhatsAppSetting::notifiesLateStudentsOnly());
    }

    public function test_gateway_page_shows_saved_scope(): void
    {
        WhatsAppSetting::current()->update(['auto_notification_scope' => WhatsAppSetting::SCOPE_LATE]);

        $this->actingAs($this->admin)
            ->get(route('notifications.gateway'))
            ->assertOk()
            ->assertViewHas('autoNotificationScope', WhatsAppSetting::SCOPE_LATE);
    }

    public function test_invalid_scope_is_rejected_and_setting_unchanged(): void
    {
        $response = $this->actingAs($this->admin)->put(route('notifications.gateway.settings.update'), [
            'auto_notification_scope' => 'semua',
        ]);

        $response->assertSessionHasErrors('auto_notification_scope');

        $this->assertSame(WhatsAppSetting::SCOPE_ALL, WhatsAppSetting::autoNotificationScope());
    }

    /**
     * @return array<int, NotificationLog>
     */
    private function queue(string $eventType): array
    {
        return app(WhatsAppNotificationService::class)
            ->queueAttendanceNotifications($this->attendance->fresh(), $eventType);
    }
}
