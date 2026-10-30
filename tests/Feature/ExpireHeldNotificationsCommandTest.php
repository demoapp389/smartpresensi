<?php

namespace Tests\Feature;

use App\Models\Guardian;
use App\Models\NotificationLog;
use App\Models\SchoolClass;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExpireHeldNotificationsCommandTest extends TestCase
{
    use RefreshDatabase;

    private Guardian $guardian;

    protected function setUp(): void
    {
        parent::setUp();

        $class = SchoolClass::create(['name' => 'X-IPA-1', 'academic_year' => '2026/2027']);

        $student = Student::create([
            'nis' => '1001',
            'name' => 'Budi Santoso',
            'gender' => 'L',
            'class_id' => $class->id,
            'active' => true,
        ]);

        $this->guardian = Guardian::create([
            'student_id' => $student->id,
            'name' => 'Wali Budi',
            'relationship' => 'Ayah',
            'wa_number' => '6281234567890',
            'active' => true,
        ]);
    }

    private function heldLog(string $key, \DateTimeInterface $createdAt): NotificationLog
    {
        $log = NotificationLog::create([
            'guardian_id' => $this->guardian->id,
            'event_type' => 'check_in',
            'idempotency_key' => $key,
            'template_name' => 'siswa_hadir_v1',
            'recipient_phone' => $this->guardian->wa_number,
            'status' => 'held',
            'attempts' => 0,
        ]);

        // created_at tidak fillable massal sesuai urutan; set eksplisit lalu simpan.
        $log->created_at = $createdAt;
        $log->save();

        return $log;
    }

    public function test_it_cancels_only_held_notifications_older_than_expiration(): void
    {
        $expired = $this->heldLog('HELD_EXPIRED', now()->subHours(30));
        $recent = $this->heldLog('HELD_RECENT', now()->subHours(2));

        $this->artisan('whatsapp:expire-held-notifications')->assertExitCode(0);

        $this->assertSame('cancelled', $expired->fresh()->status);
        $this->assertSame('held', $recent->fresh()->status);
    }

    public function test_it_honours_the_hours_option(): void
    {
        $log = $this->heldLog('HELD_CUSTOM', now()->subHours(3));

        $this->artisan('whatsapp:expire-held-notifications', ['--hours' => 1])->assertExitCode(0);

        $this->assertSame('cancelled', $log->fresh()->status);
    }
}
