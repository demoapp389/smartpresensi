<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Guardian;
use App\Models\NotificationLog;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DataRetentionPruneTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_prune_uses_shorter_retention_for_system_actions(): void
    {
        $admin = User::factory()->admin()->create();

        $systemExpired = $this->createAuditLog('auto_alpa_batch', null, now()->subDays(45));
        $systemFresh = $this->createAuditLog('auto_alpa_batch', null, now()->subDays(5));
        $manualFresh = $this->createAuditLog('update_student', $admin->id, now()->subDays(45));
        $manualExpired = $this->createAuditLog('update_student', $admin->id, now()->subDays(400));

        // Aksi dari antrean/command tetap inisiatif manusia, jadi ikut retensi panjang.
        $queuedImportFresh = $this->createAuditLog('import_students_xlsx', null, now()->subDays(45));
        $queuedImportExpired = $this->createAuditLog('import_students_xlsx', null, now()->subDays(400));

        $this->artisan('model:prune', ['--model' => [AuditLog::class]])->assertSuccessful();

        $this->assertDatabaseMissing('audit_logs', ['id' => $systemExpired->id]);
        $this->assertDatabaseHas('audit_logs', ['id' => $systemFresh->id]);
        $this->assertDatabaseHas('audit_logs', ['id' => $manualFresh->id]);
        $this->assertDatabaseMissing('audit_logs', ['id' => $manualExpired->id]);
        $this->assertDatabaseHas('audit_logs', ['id' => $queuedImportFresh->id]);
        $this->assertDatabaseMissing('audit_logs', ['id' => $queuedImportExpired->id]);
    }

    public function test_notification_prune_keeps_failed_rows_longer(): void
    {
        $guardian = $this->createGuardian();

        $sentExpired = $this->createNotificationLog($guardian, 'sent', now()->subDays(45));
        $sentFresh = $this->createNotificationLog($guardian, 'sent', now()->subDays(5));
        $failedKept = $this->createNotificationLog($guardian, 'failed', now()->subDays(45));
        $failedExpired = $this->createNotificationLog($guardian, 'failed', now()->subDays(120));

        $this->artisan('model:prune', ['--model' => [NotificationLog::class]])->assertSuccessful();

        $this->assertDatabaseMissing('notification_logs', ['id' => $sentExpired->id]);
        $this->assertDatabaseHas('notification_logs', ['id' => $sentFresh->id]);
        $this->assertDatabaseHas('notification_logs', ['id' => $failedKept->id]);
        $this->assertDatabaseMissing('notification_logs', ['id' => $failedExpired->id]);
    }

    public function test_prune_thresholds_follow_configuration(): void
    {
        config([
            'audit.system_retention_days' => 3,
            'audit.retention_days' => 10,
        ]);

        $systemExpired = $this->createAuditLog('auto_alpa_batch', null, now()->subDays(5));
        $manualKept = $this->createAuditLog('update_student', User::factory()->admin()->create()->id, now()->subDays(5));

        $this->artisan('model:prune', ['--model' => [AuditLog::class]])->assertSuccessful();

        $this->assertDatabaseMissing('audit_logs', ['id' => $systemExpired->id]);
        $this->assertDatabaseHas('audit_logs', ['id' => $manualKept->id]);
    }

    private function createAuditLog(string $action, ?int $actorId, CarbonInterface $createdAt): AuditLog
    {
        return AuditLog::create([
            'actor_id' => $actorId,
            'action' => $action,
            'entity_type' => 'Attendance',
            'entity_id' => 1,
            'before' => null,
            'after' => null,
            'ip_address' => null,
            'created_at' => $createdAt,
        ]);
    }

    private function createGuardian(): Guardian
    {
        $class = SchoolClass::create([
            'name' => 'XII-RPL 1',
            'academic_year' => '2026/2027',
        ]);

        $student = Student::create([
            'nis' => '99001',
            'name' => 'Siswa Prune',
            'gender' => 'L',
            'class_id' => $class->id,
            'active' => true,
        ]);

        return Guardian::create([
            'student_id' => $student->id,
            'name' => 'Wali Prune',
            'relationship' => 'Ayah',
            'wa_number' => '081234567890',
            'wa_status' => 'valid',
            'consent_at' => now(),
            'active' => true,
        ]);
    }

    private function createNotificationLog(Guardian $guardian, string $status, CarbonInterface $createdAt): NotificationLog
    {
        $log = NotificationLog::create([
            'guardian_id' => $guardian->id,
            'event_type' => 'check_in',
            'idempotency_key' => 'prune-'.$status.'-'.$createdAt->getTimestamp(),
            'template_name' => 'siswa_hadir_v1',
            'recipient_phone' => '081234567890',
            'status' => $status,
        ]);

        $log->forceFill(['created_at' => $createdAt])->save();

        return $log;
    }
}
