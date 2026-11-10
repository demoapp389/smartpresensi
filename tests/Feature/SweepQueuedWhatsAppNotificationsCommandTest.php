<?php

namespace Tests\Feature;

use App\Jobs\SendWhatsAppNotificationJob;
use App\Models\Attendance;
use App\Models\Guardian;
use App\Models\NotificationLog;
use App\Models\SchoolClass;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SweepQueuedWhatsAppNotificationsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_redispatches_only_stale_queued_notifications(): void
    {
        Queue::fake();

        $guardian = $this->guardian();
        $attendance = $this->attendance($guardian);

        $stale = $this->notification($guardian, $attendance, 'STALE', now()->subMinutes(30));
        $fresh = $this->notification($guardian, $attendance, 'FRESH', now()->subMinutes(1));

        $this->artisan('whatsapp:sweep-queued')->assertExitCode(0);

        Queue::assertPushed(SendWhatsAppNotificationJob::class, 1);
        Queue::assertPushed(
            SendWhatsAppNotificationJob::class,
            fn (SendWhatsAppNotificationJob $job): bool => $job->notificationId === $stale->id,
        );
        Queue::assertNotPushed(
            SendWhatsAppNotificationJob::class,
            fn (SendWhatsAppNotificationJob $job): bool => $job->notificationId === $fresh->id,
        );
    }

    public function test_command_skips_notifications_that_exhausted_retries(): void
    {
        Queue::fake();

        $guardian = $this->guardian();
        $attendance = $this->attendance($guardian);

        $this->notification($guardian, $attendance, 'EXHAUSTED', now()->subHour(), 3);

        $this->artisan('whatsapp:sweep-queued')->assertExitCode(0);

        Queue::assertNotPushed(SendWhatsAppNotificationJob::class);
    }

    private function guardian(): Guardian
    {
        $class = SchoolClass::create([
            'name' => 'X-RPL 1',
            'academic_year' => '2026/2027',
        ]);

        $student = Student::create([
            'nis' => '9101',
            'name' => 'Siswa Sweeper',
            'class_id' => $class->id,
            'active' => true,
        ]);

        return Guardian::create([
            'student_id' => $student->id,
            'name' => 'Wali Sweeper',
            'relationship' => 'Ayah',
            'wa_number' => '6281234567890',
            'wa_status' => 'valid',
            'consent_at' => now(),
            'active' => true,
        ]);
    }

    private function attendance(Guardian $guardian): Attendance
    {
        return Attendance::create([
            'student_id' => $guardian->student_id,
            'school_date' => now('Asia/Jakarta')->toDateString(),
            'status' => 'hadir',
            'check_in_at' => now('Asia/Jakarta'),
        ]);
    }

    private function notification(
        Guardian $guardian,
        Attendance $attendance,
        string $key,
        Carbon $queuedAt,
        int $attempts = 0,
    ): NotificationLog {
        return NotificationLog::create([
            'attendance_id' => $attendance->id,
            'guardian_id' => $guardian->id,
            'event_type' => 'check_in',
            'idempotency_key' => $key,
            'template_name' => 'siswa_hadir_v1',
            'recipient_phone' => $guardian->wa_number,
            'status' => 'queued',
            'attempts' => $attempts,
            'queued_at' => $queuedAt,
        ]);
    }
}
