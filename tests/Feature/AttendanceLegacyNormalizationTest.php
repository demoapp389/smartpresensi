<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Guardian;
use App\Models\NotificationLog;
use App\Models\SchoolClass;
use App\Models\SchoolSchedule;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceLegacyNormalizationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private SchoolClass $class;

    private Student $lateCheckInStudent;

    private Student $checkOutOnlyStudent;

    private Student $autoAlpaStudent;

    private Student $manualAlpaStudent;

    private Student $notifiedAlpaStudent;

    private Student $manualPulangCepatStudent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->class = SchoolClass::create([
            'name' => 'XI-IPS 1',
            'academic_year' => '2026/2027',
        ]);

        $this->lateCheckInStudent = $this->createStudent('9101', 'Siswa Pulang Cepat');
        $this->checkOutOnlyStudent = $this->createStudent('9102', 'Siswa Scan Pulang');
        $this->autoAlpaStudent = $this->createStudent('9103', 'Siswa Alpa Otomatis');
        $this->manualAlpaStudent = $this->createStudent('9104', 'Siswa Alpa Manual');
        $this->notifiedAlpaStudent = $this->createStudent('9105', 'Siswa Alpa Ternotifikasi');
        $this->manualPulangCepatStudent = $this->createStudent('9106', 'Siswa Pulang Cepat Manual');

        SchoolSchedule::create([
            'day_pattern' => 'regular',
            'day_name' => 'Rabu',
            'day_of_week' => 3,
            'check_in_start' => '06:00:00',
            'start_at' => '07:00:00',
            'late_after' => '07:15:00',
            'check_out_start' => '14:00:00',
            'end_at' => '16:00:00',
            'is_active' => true,
        ]);
    }

    public function test_command_normalizes_legacy_rows_and_is_idempotent(): void
    {
        $lateCheckIn = $this->createAttendance($this->lateCheckInStudent, [
            'check_in_at' => '2026-10-14 07:16:00',
            'status' => 'pulang_cepat',
            'late_minutes' => 0,
        ]);

        $checkOutOnly = $this->createAttendance($this->checkOutOnlyStudent, [
            'check_out_at' => '2026-10-14 15:05:00',
            'status' => 'terlambat',
        ]);

        $autoAlpa = $this->createAttendance($this->autoAlpaStudent, [
            'status' => 'alpa',
        ]);

        $manualAlpa = $this->createAttendance($this->manualAlpaStudent, [
            'status' => 'alpa',
            'is_manual_override' => true,
            'override_reason' => 'Surat izin tidak masuk.',
            'operator_id' => $this->admin->id,
        ]);

        $notifiedAlpa = $this->createAttendance($this->notifiedAlpaStudent, [
            'status' => 'alpa',
        ]);

        $guardian = Guardian::create([
            'student_id' => $this->notifiedAlpaStudent->id,
            'name' => 'Wali Siswa Ternotifikasi',
            'relationship' => 'Ibu',
            'wa_number' => '6281234567890',
            'active' => true,
        ]);

        NotificationLog::create([
            'attendance_id' => $notifiedAlpa->id,
            'guardian_id' => $guardian->id,
            'event_type' => 'absent',
            'idempotency_key' => 'legacy-alpa-notified',
            'template_name' => 'absent',
            'recipient_phone' => '6281234567890',
            'status' => 'sent',
        ]);

        $manualPulangCepat = $this->createAttendance($this->manualPulangCepatStudent, [
            'status' => 'pulang_cepat',
            'is_manual_override' => true,
            'override_reason' => 'Siswa dijemput lebih awal.',
            'operator_id' => $this->admin->id,
        ]);

        $this->artisan('attendance:normalize-legacy')->assertSuccessful();

        $lateCheckIn->refresh();
        $this->assertSame('terlambat', $lateCheckIn->status);
        $this->assertSame('terlambat', $lateCheckIn->check_in_status);
        $this->assertSame('belum_absen', $lateCheckIn->check_out_status);
        $this->assertSame(16, $lateCheckIn->late_minutes);
        $this->assertNull($lateCheckIn->override_reason);
        $this->assertSame('Scan Pulang Belum Ada', $lateCheckIn->scan_completeness_label);

        $checkOutOnly->refresh();
        $this->assertSame('perlu_tinjauan', $checkOutOnly->status);
        $this->assertSame('tidak_absen', $checkOutOnly->check_in_status);
        $this->assertSame('perlu_verifikasi', $checkOutOnly->check_out_status);
        $this->assertSame(55, $checkOutOnly->early_leave_minutes);
        $this->assertSame('Normalisasi: hanya ada scan pulang, scan masuk belum tersedia.', $checkOutOnly->override_reason);

        $this->assertDatabaseMissing('attendances', ['id' => $autoAlpa->id]);
        $this->assertDatabaseHas('attendances', ['id' => $manualAlpa->id, 'status' => 'alpa']);
        $this->assertDatabaseHas('attendances', ['id' => $notifiedAlpa->id, 'status' => 'alpa']);

        $manualPulangCepat->refresh();
        $this->assertSame('hadir', $manualPulangCepat->status);
        $this->assertSame('diverifikasi', $manualPulangCepat->check_in_status);
        $this->assertSame('belum_absen', $manualPulangCepat->check_out_status);

        $this->assertSame(0, Attendance::where('status', 'pulang_cepat')->count());

        $this->artisan('attendance:normalize-legacy')->assertSuccessful();

        $this->assertSame(5, Attendance::count());
        $this->assertSame('terlambat', $lateCheckIn->fresh()->status);
        $this->assertSame('perlu_tinjauan', $checkOutOnly->fresh()->status);
    }

    public function test_command_keeps_check_in_only_presence_out_of_pending_review(): void
    {
        $attendance = $this->createAttendance($this->lateCheckInStudent, [
            'school_date' => '2026-10-21',
            'check_in_at' => '2026-10-21 07:20:00',
            'status' => 'perlu_tinjauan',
            'override_reason' => 'Normalisasi: hanya ada scan masuk, scan pulang belum tersedia.',
            'policy_version' => 'v1.0',
        ]);

        $this->artisan('attendance:normalize-legacy')->assertSuccessful();

        $attendance->refresh();
        $this->assertSame('terlambat', $attendance->status);
        $this->assertSame('terlambat', $attendance->check_in_status);
        $this->assertSame('belum_absen', $attendance->check_out_status);
        $this->assertSame(20, $attendance->late_minutes);
        $this->assertNull($attendance->override_reason);
    }

    public function test_command_finalizes_complete_scan_that_was_still_pending(): void
    {
        $attendance = $this->createAttendance($this->notifiedAlpaStudent, [
            'school_date' => '2026-10-21',
            'check_in_at' => '2026-10-21 07:16:00',
            'check_out_at' => '2026-10-21 15:05:00',
            'status' => 'perlu_tinjauan',
            'policy_version' => 'v1.1',
        ]);

        $this->artisan('attendance:normalize-legacy')->assertSuccessful();

        $attendance->refresh();
        $this->assertSame('terlambat', $attendance->status);
        $this->assertSame('terlambat', $attendance->check_in_status);
        $this->assertSame('pulang_cepat', $attendance->check_out_status);
        $this->assertSame(16, $attendance->late_minutes);
        $this->assertSame(55, $attendance->early_leave_minutes);
    }

    public function test_command_reports_legacy_manual_presence_and_manual_absence_with_scan(): void
    {
        $manualPresence = $this->createAttendance($this->manualAlpaStudent, [
            'school_date' => '2026-10-15',
            'status' => 'hadir',
            'is_manual_override' => true,
            'override_reason' => 'Hadir manual lama tanpa scan.',
            'operator_id' => $this->admin->id,
        ]);

        $manualAbsence = $this->createAttendance($this->notifiedAlpaStudent, [
            'school_date' => '2026-10-15',
            'status' => 'izin',
            'check_in_at' => '2026-10-15 07:05:00',
            'is_manual_override' => true,
            'override_reason' => 'Izin manual lama dengan scan.',
            'operator_id' => $this->admin->id,
        ]);

        $this->artisan('attendance:normalize-legacy')
            ->expectsOutputToContain('Manual hadir tanpa scan (dibiarkan)')
            ->expectsOutputToContain('Koreksi manual dengan scan (perlu audit)')
            ->assertSuccessful();

        $this->assertSame('hadir', $manualPresence->fresh()->status);
        $this->assertSame('izin', $manualAbsence->fresh()->status);
    }

    public function test_dry_run_reports_changes_without_writing(): void
    {
        $legacy = $this->createAttendance($this->lateCheckInStudent, [
            'check_in_at' => '2026-10-14 07:16:00',
            'status' => 'pulang_cepat',
        ]);

        $autoAlpa = $this->createAttendance($this->autoAlpaStudent, [
            'status' => 'alpa',
        ]);

        $this->artisan('attendance:normalize-legacy', ['--dry-run' => true])
            ->expectsOutputToContain('Dry-run selesai. Tidak ada data yang diubah.')
            ->assertSuccessful();

        $this->assertSame('pulang_cepat', $legacy->fresh()->status);
        $this->assertDatabaseHas('attendances', ['id' => $autoAlpa->id, 'status' => 'alpa']);
    }

    public function test_command_preserves_auto_alpa_with_reason(): void
    {
        $attendance = $this->createAttendance($this->autoAlpaStudent, [
            'school_date' => '2026-10-15',
            'status' => 'alpa',
            'override_reason' => 'Auto-Alpa H+1: tidak ada scan masuk dan pulang serta belum ditinjau.',
            'policy_version' => 'v1.1-auto-alpa',
        ]);

        $this->artisan('attendance:normalize-legacy')->assertSuccessful();

        $this->assertDatabaseHas('attendances', [
            'id' => $attendance->id,
            'status' => 'alpa',
            'override_reason' => 'Auto-Alpa H+1: tidak ada scan masuk dan pulang serta belum ditinjau.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createAttendance(Student $student, array $attributes): Attendance
    {
        return Attendance::create(array_merge([
            'student_id' => $student->id,
            'school_date' => '2026-10-14',
            'status' => 'hadir',
            'policy_version' => 'v1.0',
        ], $attributes));
    }

    private function createStudent(string $nis, string $name): Student
    {
        return Student::create([
            'nis' => $nis,
            'name' => $name,
            'class_id' => $this->class->id,
            'gender' => 'L',
            'active' => true,
        ]);
    }
}
