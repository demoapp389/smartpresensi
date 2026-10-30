<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Guardian;
use App\Models\NotificationLog;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceBulkCorrectionTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $waliKelasA;

    protected SchoolClass $classA;

    protected SchoolClass $classB;

    protected Student $studentA1;

    protected Student $studentA2;

    protected Student $studentB1;

    protected Attendance $attA1;

    protected Attendance $attA2;

    protected Attendance $attB1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();

        $this->classA = SchoolClass::create([
            'name' => 'VIII-A',
            'academic_year' => '2026/2027',
        ]);

        $this->classB = SchoolClass::create([
            'name' => 'VIII-B',
            'academic_year' => '2026/2027',
        ]);

        $this->waliKelasA = User::factory()->waliKelas($this->classA)->create([
            'name' => 'Wali Kelas VIII-A',
        ]);

        $this->studentA1 = Student::create([
            'nis' => '1001',
            'name' => 'Ahmad Santoso',
            'class_id' => $this->classA->id,
            'gender' => 'L',
            'active' => true,
        ]);

        $this->studentA2 = Student::create([
            'nis' => '1002',
            'name' => 'Budi Pratama',
            'class_id' => $this->classA->id,
            'gender' => 'L',
            'active' => true,
        ]);

        $this->studentB1 = Student::create([
            'nis' => '2001',
            'name' => 'Citra Dewi',
            'class_id' => $this->classB->id,
            'gender' => 'P',
            'active' => true,
        ]);

        $today = Carbon::today('Asia/Jakarta')->toDateString();

        $this->attA1 = Attendance::create([
            'student_id' => $this->studentA1->id,
            'school_date' => $today,
            'status' => 'alpa',
            'policy_version' => 'v1.0',
        ]);

        $this->attA2 = Attendance::create([
            'student_id' => $this->studentA2->id,
            'school_date' => $today,
            'status' => 'alpa',
            'policy_version' => 'v1.0',
        ]);

        $this->attB1 = Attendance::create([
            'student_id' => $this->studentB1->id,
            'school_date' => $today,
            'status' => 'alpa',
            'policy_version' => 'v1.0',
        ]);
    }

    public function test_admin_can_bulk_correct_attendances(): void
    {
        $response = $this->actingAs($this->admin)->post(route('attendances.bulk-correct'), [
            'ids' => [$this->attA1->id, $this->attA2->id],
            'status' => 'izin',
            'override_reason' => 'Mengikuti Lomba O2SN Tingkat Kabupaten',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->attA1->refresh();
        $this->attA2->refresh();

        $this->assertSame('izin', $this->attA1->status);
        $this->assertTrue($this->attA1->is_manual_override);
        $this->assertSame('Mengikuti Lomba O2SN Tingkat Kabupaten', $this->attA1->override_reason);
        $this->assertSame($this->admin->id, $this->attA1->operator_id);

        $this->assertSame('izin', $this->attA2->status);
        $this->assertTrue($this->attA2->is_manual_override);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'bulk_manual_override_attendance',
            'entity_type' => 'Attendance',
            'entity_id' => $this->attA1->id,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'bulk_manual_override_attendance',
            'entity_type' => 'Attendance',
            'entity_id' => $this->attA2->id,
        ]);
    }

    public function test_bulk_correction_cancels_pending_notifications(): void
    {
        $guardian = Guardian::create([
            'student_id' => $this->studentA1->id,
            'name' => 'Wali Ahmad',
            'relationship' => 'Ayah',
            'wa_number' => '6281234567890',
            'consent_at' => now(),
            'active' => true,
        ]);
        $notification = NotificationLog::create([
            'attendance_id' => $this->attA1->id,
            'guardian_id' => $guardian->id,
            'event_type' => 'check_in',
            'idempotency_key' => 'BULK-CORRECTION-'.$this->attA1->id,
            'template_name' => 'siswa_hadir_v1',
            'recipient_phone' => '6281234567890',
            'payload' => [],
            'status' => 'queued',
            'attempts' => 0,
            'queued_at' => now(),
        ]);

        $this->actingAs($this->admin)->post(route('attendances.bulk-correct'), [
            'ids' => [$this->attA1->id],
            'status' => 'izin',
            'override_reason' => 'Surat izin diterima setelah antrean WA.',
        ])->assertRedirect()->assertSessionHas('success');

        $notification->refresh();

        $this->assertSame('cancelled', $notification->status);
        $this->assertStringContainsString('dikoreksi manual', $notification->error_message);
    }

    public function test_homeroom_teacher_is_forbidden_to_bulk_correct_attendances(): void
    {
        $response = $this->actingAs($this->waliKelasA)->post(route('attendances.bulk-correct'), [
            'ids' => [$this->attA1->id, $this->attA2->id],
            'status' => 'sakit',
            'override_reason' => 'Surat dokter terlampir',
        ]);

        $response->assertStatus(403);

        $this->attA1->refresh();
        $this->assertSame('alpa', $this->attA1->status);
    }

    public function test_homeroom_teacher_is_forbidden_to_bulk_correct_other_class_attendances(): void
    {
        $response = $this->actingAs($this->waliKelasA)->post(route('attendances.bulk-correct'), [
            'ids' => [$this->attA1->id, $this->attB1->id],
            'status' => 'izin',
            'override_reason' => 'Coba koreksi lintas kelas',
        ]);

        $response->assertStatus(403);

        $this->attA1->refresh();
        $this->attB1->refresh();

        $this->assertSame('alpa', $this->attA1->status);
        $this->assertSame('alpa', $this->attB1->status);
    }

    public function test_bulk_correct_validation_requires_ids_status_and_reason(): void
    {
        $response = $this->actingAs($this->admin)->post(route('attendances.bulk-correct'), [
            'ids' => [],
            'status' => 'invalid_status',
            'override_reason' => '',
        ]);

        $response->assertSessionHasErrors(['ids', 'status', 'override_reason']);
    }

    public function test_bulk_absence_rejects_batch_when_any_row_has_scan_evidence(): void
    {
        $this->attA1->update([
            'status' => 'hadir',
            'check_in_at' => Carbon::today('Asia/Jakarta')->setTime(7, 0),
            'check_in_status' => 'tepat_waktu',
        ]);

        $response = $this->actingAs($this->admin)->post(route('attendances.bulk-correct'), [
            'ids' => [$this->attA1->id, $this->attA2->id],
            'status' => 'izin',
            'override_reason' => 'Koreksi massal harus ditolak.',
        ]);

        $response->assertSessionHasErrors('ids');

        $this->assertSame('hadir', $this->attA1->fresh()->status);
        $this->assertSame('alpa', $this->attA2->fresh()->status);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'bulk_manual_override_attendance',
        ]);
    }

    public function test_bulk_correction_rejects_batch_when_manual_presence_has_no_scan(): void
    {
        $this->attA1->update([
            'status' => 'hadir',
            'is_manual_override' => true,
            'override_reason' => 'Hadir manual lama tanpa scan.',
            'operator_id' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin)->post(route('attendances.bulk-correct'), [
            'ids' => [$this->attA1->id, $this->attA2->id],
            'status' => 'izin',
            'override_reason' => 'Koreksi massal harus ditolak.',
        ]);

        $response->assertSessionHasErrors('ids');

        $this->assertSame('hadir', $this->attA1->fresh()->status);
        $this->assertSame('alpa', $this->attA2->fresh()->status);
    }

    public function test_bulk_correct_rejects_terlambat_status(): void
    {
        $response = $this->actingAs($this->admin)->post(route('attendances.bulk-correct'), [
            'ids' => [$this->attA1->id],
            'status' => 'terlambat',
            'override_reason' => 'Status terlambat tidak tersedia untuk massal.',
        ]);

        $response->assertSessionHasErrors('status');

        $this->assertSame('alpa', $this->attA1->fresh()->status);
    }
}
