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

class AttendanceCorrectionWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private SchoolClass $class;

    private Student $student;

    private Student $otherStudent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->class = SchoolClass::create([
            'name' => 'X-IPA 1',
            'academic_year' => '2026/2027',
        ]);

        $this->student = Student::create([
            'nis' => '9001',
            'name' => 'Siswa Verifikasi',
            'class_id' => $this->class->id,
            'gender' => 'L',
            'active' => true,
        ]);

        $this->otherStudent = Student::create([
            'nis' => '9002',
            'name' => 'Siswa Pembanding',
            'class_id' => $this->class->id,
            'gender' => 'P',
            'active' => true,
        ]);

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

    public function test_scan_reason_preserves_pending_scan_and_records_reason(): void
    {
        $attendance = $this->createAttendance([
            'check_out_at' => '2026-10-14 15:05:00',
            'status' => 'perlu_tinjauan',
            'check_in_status' => 'tidak_absen',
            'check_out_status' => 'perlu_verifikasi',
            'early_leave_minutes' => 55,
        ]);

        $response = $this->actingAs($this->admin)->post(route('attendances.scan-reason', $attendance), [
            'override_reason' => 'Mesin pulang bermasalah, scan masuk tidak tercatat.',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $attendance->refresh();

        $this->assertSame('perlu_tinjauan', $attendance->status);
        $this->assertSame('2026-10-14 15:05:00', $attendance->check_out_at->format('Y-m-d H:i:s'));
        $this->assertNull($attendance->check_in_at);
        $this->assertSame('tidak_absen', $attendance->check_in_status);
        $this->assertSame('perlu_verifikasi', $attendance->check_out_status);
        $this->assertSame(55, $attendance->early_leave_minutes);
        $this->assertFalse($attendance->is_manual_override);
        $this->assertSame('Mesin pulang bermasalah, scan masuk tidak tercatat.', $attendance->override_reason);
        $this->assertSame($this->admin->id, $attendance->operator_id);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'record_scan_reason',
            'entity_id' => $attendance->id,
        ]);
    }

    public function test_scanned_attendance_cannot_be_corrected_and_preserves_scan(): void
    {
        $attendance = $this->createAttendance([
            'status' => 'terlambat',
            'check_in_at' => '2026-10-14 07:16:00',
            'check_in_status' => 'terlambat',
            'check_out_at' => '2026-10-14 15:05:00',
            'check_out_status' => 'pulang_cepat',
            'late_minutes' => 16,
            'early_leave_minutes' => 55,
        ]);

        $this->actingAs($this->admin)->post(route('attendances.correct', $attendance), [
            'status' => 'hadir',
            'override_reason' => 'Keterlambatan dimaafkan oleh sekolah.',
        ])->assertSessionHasErrors('status');

        $attendance->refresh();

        $this->assertSame('terlambat', $attendance->status);
        $this->assertSame('2026-10-14 07:16:00', $attendance->check_in_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-14 15:05:00', $attendance->check_out_at->format('Y-m-d H:i:s'));
        $this->assertSame('terlambat', $attendance->check_in_status);
        $this->assertSame(16, $attendance->late_minutes);
        $this->assertSame('pulang_cepat', $attendance->check_out_status);
        $this->assertSame(55, $attendance->early_leave_minutes);
        $this->assertFalse($attendance->is_manual_override);
    }

    public function test_absence_correction_allows_legacy_row_without_scan_times(): void
    {
        $attendance = $this->createAttendance([
            'status' => 'alpa',
            'check_in_status' => 'tidak_absen',
            'check_out_status' => 'belum_absen',
        ]);

        $this->actingAs($this->admin)->post(route('attendances.correct', $attendance), [
            'status' => 'izin',
            'override_reason' => 'Surat izin diterima.',
        ])->assertSessionHas('success');

        $attendance->refresh();

        $this->assertSame('izin', $attendance->status);
        $this->assertNull($attendance->check_in_status);
        $this->assertNull($attendance->check_out_status);
    }

    public function test_cannot_correct_scanned_attendance_to_absence_status(): void
    {
        $attendance = $this->createAttendance([
            'status' => 'hadir',
            'check_in_at' => '2026-10-14 07:05:00',
            'check_in_status' => 'tepat_waktu',
        ]);

        $this->actingAs($this->admin)->post(route('attendances.correct', $attendance), [
            'status' => 'izin',
            'override_reason' => 'Tidak boleh menimpa bukti scan.',
        ])->assertSessionHasErrors('status');

        $attendance->refresh();

        $this->assertSame('hadir', $attendance->status);
        $this->assertSame('tepat_waktu', $attendance->check_in_status);
        $this->assertFalse($attendance->is_manual_override);
    }

    public function test_admin_can_correct_complete_scan_to_absence_without_losing_scan(): void
    {
        $attendance = $this->createAttendance([
            'status' => 'terlambat',
            'check_in_at' => '2026-10-14 07:16:00',
            'check_in_status' => 'terlambat',
            'check_out_at' => '2026-10-14 15:05:00',
            'check_out_status' => 'pulang_cepat',
            'late_minutes' => 16,
            'early_leave_minutes' => 55,
        ]);

        $this->actingAs($this->admin)->post(route('attendances.correct', $attendance), [
            'status' => 'sakit',
            'override_reason' => 'Surat dokter menyusul, siswa sakit saat di sekolah.',
        ])->assertSessionHas('success');

        $attendance->refresh();

        $this->assertSame('sakit', $attendance->status);
        $this->assertSame('2026-10-14 07:16:00', $attendance->check_in_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-14 15:05:00', $attendance->check_out_at->format('Y-m-d H:i:s'));
        $this->assertSame('terlambat', $attendance->check_in_status);
        $this->assertSame('pulang_cepat', $attendance->check_out_status);
        $this->assertSame(16, $attendance->late_minutes);
        $this->assertSame(55, $attendance->early_leave_minutes);
        $this->assertTrue($attendance->is_manual_override);
        $this->assertSame('v1.2-scan-correction', $attendance->policy_version);
        $this->assertSame('Scan Lengkap', $attendance->scan_completeness_label);
        $this->assertSame('Sakit', $attendance->status_display_label);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'manual_override_attendance',
            'entity_id' => $attendance->id,
        ]);
    }

    public function test_operator_cannot_correct_complete_scan(): void
    {
        $operator = User::factory()->operator()->create();
        $attendance = $this->createAttendance([
            'status' => 'hadir',
            'check_in_at' => '2026-10-14 07:05:00',
            'check_in_status' => 'tepat_waktu',
            'check_out_at' => '2026-10-14 16:05:00',
            'check_out_status' => 'tepat_waktu',
        ]);

        $this->actingAs($operator)->post(route('attendances.correct', $attendance), [
            'status' => 'izin',
            'override_reason' => 'Operator tidak berwenang.',
        ])->assertSessionHasErrors('status');

        $attendance->refresh();

        $this->assertSame('hadir', $attendance->status);
        $this->assertSame('2026-10-14 07:05:00', $attendance->check_in_at->format('Y-m-d H:i:s'));
        $this->assertFalse($attendance->is_manual_override);
    }

    public function test_cannot_correct_attendance_to_terlambat_without_check_in(): void
    {
        $attendance = $this->createAttendance([
            'status' => 'alpa',
        ]);

        $this->actingAs($this->admin)->post(route('attendances.correct', $attendance), [
            'status' => 'terlambat',
            'override_reason' => 'Tidak ada jam masuk untuk dihitung.',
        ])->assertSessionHasErrors('status');

        $this->assertSame('alpa', $attendance->fresh()->status);
    }

    public function test_checkout_only_attendance_can_be_resolved_without_losing_scan(): void
    {
        $attendance = $this->createAttendance([
            'status' => 'perlu_tinjauan',
            'check_out_at' => '2026-10-14 15:05:00',
            'check_in_status' => 'tidak_absen',
            'check_out_status' => 'perlu_verifikasi',
            'early_leave_minutes' => 55,
        ]);

        $this->actingAs($this->admin)->post(route('attendances.admin-scan-exception', $attendance), [
            'status' => 'izin',
            'override_reason' => 'Siswa izin pulang lebih awal.',
        ])->assertSessionHas('success');

        $attendance->refresh();

        $this->assertSame('izin', $attendance->status);
        $this->assertSame('2026-10-14 15:05:00', $attendance->check_out_at->format('Y-m-d H:i:s'));
        $this->assertNull($attendance->check_in_at);
        $this->assertSame('tidak_absen', $attendance->check_in_status);
        $this->assertSame('perlu_verifikasi', $attendance->check_out_status);
        $this->assertSame(55, $attendance->early_leave_minutes);
        $this->assertTrue($attendance->is_manual_override);
        $this->assertSame('Siswa izin pulang lebih awal.', $attendance->override_reason);
        $this->assertSame($this->admin->id, $attendance->operator_id);
        $this->assertSame(Attendance::POLICY_VERSION_ADMIN_SCAN_EXCEPTION, $attendance->policy_version);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'admin_scan_exception_attendance',
            'entity_id' => $attendance->id,
        ]);
    }

    public function test_admin_scan_exception_rejects_hadir_status(): void
    {
        $attendance = $this->createAttendance([
            'status' => 'perlu_tinjauan',
            'check_out_at' => '2026-10-14 15:05:00',
            'check_in_status' => 'tidak_absen',
            'check_out_status' => 'perlu_verifikasi',
            'early_leave_minutes' => 55,
        ]);

        $this->actingAs($this->admin)->post(route('attendances.admin-scan-exception', $attendance), [
            'status' => 'hadir',
            'override_reason' => 'Tidak boleh mengarang scan masuk.',
        ])->assertSessionHasErrors('status');

        $attendance->refresh();

        $this->assertSame('perlu_tinjauan', $attendance->status);
        $this->assertNull($attendance->check_in_at);
        $this->assertFalse($attendance->is_manual_override);
    }

    public function test_operator_can_verify_checkout_only_attendance_as_present(): void
    {
        $operator = User::factory()->operator()->create();
        $attendance = $this->createAttendance([
            'status' => 'perlu_tinjauan',
            'check_out_at' => '2026-10-14 15:05:00',
            'check_in_status' => 'tidak_absen',
            'check_out_status' => 'perlu_verifikasi',
            'early_leave_minutes' => 55,
        ]);

        $this->actingAs($operator)->post(route('attendances.correct', $attendance), [
            'status' => 'hadir',
            'override_reason' => 'Scan masuk gagal, siswa terverifikasi hadir.',
        ])->assertSessionHas('success');

        $attendance->refresh();

        $this->assertSame('hadir', $attendance->status);
        $this->assertSame('diverifikasi', $attendance->check_in_status);
        $this->assertSame('2026-10-14 15:05:00', $attendance->check_out_at->format('Y-m-d H:i:s'));
        $this->assertSame(55, $attendance->early_leave_minutes);
        $this->assertTrue($attendance->is_manual_override);
    }

    public function test_correction_page_shows_admin_scan_exception_for_checkout_only(): void
    {
        $this->createAttendance([
            'check_out_at' => '2026-10-14 15:05:00',
            'status' => 'perlu_tinjauan',
            'check_in_status' => 'tidak_absen',
            'check_out_status' => 'perlu_verifikasi',
            'early_leave_minutes' => 55,
        ]);

        $response = $this->actingAs($this->admin)->get(route('attendances.correction', [
            'date' => '2026-10-14',
        ]));

        $response->assertOk();
        $response->assertSee('Scan Masuk Belum Ada');
        $response->assertSee('Perlu Tinjauan');
        $response->assertSee('Pengecualian Admin');
        $response->assertSee('Telat (Menit)');
        $response->assertSee('PLG Cepat (Menit)');
        $response->assertSee('adminScanModal = true', false);
    }

    public function test_correction_page_allows_admin_to_correct_complete_scan(): void
    {
        $this->createAttendance([
            'status' => 'hadir',
            'check_in_at' => '2026-10-14 06:55:00',
            'check_in_status' => 'tepat_waktu',
            'check_out_at' => '2026-10-14 16:05:00',
            'check_out_status' => 'tepat_waktu',
        ]);

        $response = $this->actingAs($this->admin)->get(route('attendances.correction', [
            'date' => '2026-10-14',
        ]));

        $response->assertOk();
        $response->assertSee($this->student->name);
        $response->assertSee('correctModal = true', false);
    }

    public function test_correction_page_hides_complete_scan_action_from_operator(): void
    {
        $operator = User::factory()->operator()->create();

        $this->createAttendance([
            'status' => 'hadir',
            'check_in_at' => '2026-10-14 06:55:00',
            'check_in_status' => 'tepat_waktu',
            'check_out_at' => '2026-10-14 16:05:00',
            'check_out_status' => 'tepat_waktu',
        ]);

        $response = $this->actingAs($operator)->get(route('attendances.correction', [
            'date' => '2026-10-14',
        ]));

        $response->assertOk();
        $response->assertDontSee('correctModal = true', false);
    }

    public function test_correction_page_uses_scan_reason_for_check_in_only_presence(): void
    {
        $this->createAttendance([
            'status' => 'hadir',
            'check_in_at' => '2026-10-14 07:05:00',
            'check_in_status' => 'tepat_waktu',
            'check_out_status' => 'belum_absen',
        ]);

        $response = $this->actingAs($this->admin)->get(route('attendances.correction', [
            'date' => '2026-10-14',
        ]));

        $response->assertOk();
        $response->assertSee('Isi Alasan');
        $response->assertSee('Scan Pulang Belum Ada');
        $response->assertDontSee('correctModal = true', false);
    }

    public function test_correction_and_bulk_correction_reject_legacy_pulang_cepat_status(): void
    {
        $attendance = $this->createAttendance([
            'status' => 'hadir',
            'check_in_at' => '2026-10-14 07:05:00',
            'check_in_status' => 'tepat_waktu',
            'check_out_status' => 'belum_absen',
        ]);

        $this->actingAs($this->admin)
            ->post(route('attendances.correct', $attendance), [
                'status' => 'pulang_cepat',
                'override_reason' => 'Status lama tidak boleh dipakai.',
            ])
            ->assertSessionHasErrors('status');

        $this->actingAs($this->admin)
            ->post(route('attendances.bulk-correct'), [
                'ids' => [$attendance->id],
                'status' => 'pulang_cepat',
                'override_reason' => 'Status lama tidak boleh dipakai.',
            ])
            ->assertSessionHasErrors('status');

        $this->assertSame('hadir', $attendance->fresh()->status);
    }

    public function test_manual_absence_for_student_without_scan_succeeds(): void
    {
        $response = $this->actingAs($this->admin)->post(route('attendances.manual'), [
            'student_id' => $this->student->id,
            'school_date' => '2026-10-14',
            'status' => 'izin',
            'override_reason' => 'Surat izin diterima.',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $attendance = Attendance::where('student_id', $this->student->id)
            ->whereDate('school_date', '2026-10-14')
            ->firstOrFail();

        $this->assertSame('izin', $attendance->status);
        $this->assertTrue($attendance->is_manual_override);
        $this->assertSame('Surat izin diterima.', $attendance->override_reason);
        $this->assertSame($this->admin->id, $attendance->operator_id);
        $this->assertNull($attendance->check_in_at);
        $this->assertNull($attendance->check_out_at);
    }

    public function test_manual_input_cannot_replace_existing_checkout_scan(): void
    {
        $attendance = $this->createAttendance([
            'check_out_at' => '2026-10-14 15:05:00',
            'status' => 'perlu_tinjauan',
            'check_in_status' => 'tidak_absen',
            'check_out_status' => 'perlu_verifikasi',
            'early_leave_minutes' => 55,
        ]);

        $this->actingAs($this->admin)->post(route('attendances.manual'), [
            'student_id' => $this->student->id,
            'school_date' => '2026-10-14',
            'status' => 'izin',
            'override_reason' => 'Tidak boleh menimpa scan pulang.',
        ])->assertSessionHasErrors('status');

        $attendance->refresh();

        $this->assertSame('perlu_tinjauan', $attendance->status);
        $this->assertSame('2026-10-14 15:05:00', $attendance->check_out_at->format('Y-m-d H:i:s'));
        $this->assertSame('perlu_verifikasi', $attendance->check_out_status);
        $this->assertFalse($attendance->is_manual_override);
    }

    public function test_manual_input_rejects_hadir_status(): void
    {
        $this->actingAs($this->admin)->post(route('attendances.manual'), [
            'student_id' => $this->student->id,
            'school_date' => '2026-10-14',
            'status' => 'hadir',
            'override_reason' => 'Hadir manual sudah tidak didukung.',
        ])->assertSessionHasErrors('status');

        $this->assertDatabaseMissing('attendances', [
            'student_id' => $this->student->id,
            'school_date' => '2026-10-14',
        ]);
    }

    public function test_manual_input_rejects_absence_status_for_existing_scan(): void
    {
        $attendance = $this->createAttendance([
            'status' => 'hadir',
            'check_in_at' => '2026-10-14 07:05:00',
            'check_in_status' => 'tepat_waktu',
        ]);

        $this->actingAs($this->admin)->post(route('attendances.manual'), [
            'student_id' => $this->student->id,
            'school_date' => '2026-10-14',
            'status' => 'alpa',
            'override_reason' => 'Tidak boleh menghapus bukti scan.',
        ])->assertSessionHasErrors('status');

        $attendance->refresh();

        $this->assertSame('hadir', $attendance->status);
        $this->assertSame('2026-10-14 07:05:00', $attendance->check_in_at->format('Y-m-d H:i:s'));
    }

    public function test_manual_input_ignores_scan_time_fields_for_absence(): void
    {
        $this->actingAs($this->admin)->post(route('attendances.manual'), [
            'student_id' => $this->student->id,
            'school_date' => '2026-10-14',
            'status' => 'izin',
            'check_in_at' => '07:05',
            'override_reason' => 'Izin dengan field jam yang tidak dipakai.',
        ])->assertSessionHas('success');

        $attendance = Attendance::where('student_id', $this->student->id)
            ->whereDate('school_date', '2026-10-14')
            ->firstOrFail();

        $this->assertSame('izin', $attendance->status);
        $this->assertNull($attendance->check_in_at);
        $this->assertNull($attendance->check_in_status);
    }

    public function test_manual_input_rejects_terlambat_status(): void
    {
        $this->actingAs($this->admin)->post(route('attendances.manual'), [
            'student_id' => $this->student->id,
            'school_date' => '2026-10-14',
            'status' => 'terlambat',
            'override_reason' => 'Status terlambat tidak tersedia untuk input manual.',
        ])->assertSessionHasErrors('status');

        $this->assertDatabaseMissing('attendances', [
            'student_id' => $this->student->id,
            'school_date' => '2026-10-14',
        ]);
    }

    public function test_attendance_history_filters_by_checkout_status_and_shows_minutes(): void
    {
        $this->createAttendance([
            'status' => 'terlambat',
            'check_in_at' => '2026-10-14 07:16:00',
            'check_in_status' => 'terlambat',
            'check_out_status' => 'belum_absen',
            'late_minutes' => 16,
        ]);

        Attendance::create([
            'student_id' => $this->otherStudent->id,
            'school_date' => '2026-10-14',
            'status' => 'hadir',
            'check_in_at' => '2026-10-14 06:55:00',
            'check_in_status' => 'tepat_waktu',
            'check_out_at' => '2026-10-14 16:05:00',
            'check_out_status' => 'tepat_waktu',
            'policy_version' => 'v1.0',
        ]);

        $response = $this->actingAs($this->admin)->get(route('attendances.index', [
            'date' => '2026-10-14',
            'out_status' => 'belum_absen',
        ]));

        $response->assertOk();
        $response->assertSee($this->student->name);
        $response->assertDontSee($this->otherStudent->name);
        $response->assertSee('Belum Absen Pulang');
        $response->assertSee('Telat (Menit)');
        $response->assertSee('PLG Cepat (Menit)');
        $response->assertDontSee('+16 mnt');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function test_correction_page_and_action_handle_soft_deleted_student(): void
    {
        $attendance = Attendance::create([
            'student_id' => $this->otherStudent->id,
            'school_date' => '2026-10-22',
            'status' => 'perlu_tinjauan',
            'policy_version' => 'v1.1',
        ]);

        $this->otherStudent->delete();

        $response = $this->actingAs($this->admin)->get(route('attendances.correction', [
            'date' => '2026-10-22',
        ]));

        $response->assertOk();
        $response->assertSee('Siswa Pembanding');
        $response->assertSee('NIS: 9002');

        $this->actingAs($this->admin)->post(route('attendances.correct', $attendance), [
            'status' => 'izin',
            'override_reason' => 'Surat izin diterima.',
        ])->assertSessionHas('success');

        $this->assertSame('izin', $attendance->fresh()->status);
    }

    public function test_manual_correction_cancels_only_pending_notifications(): void
    {
        $attendance = $this->createAttendance([
            'status' => 'perlu_tinjauan',
            'policy_version' => Attendance::POLICY_VERSION_PENDING,
        ]);

        $guardian = Guardian::create([
            'student_id' => $this->student->id,
            'name' => 'Wali Siswa',
            'relationship' => 'Ayah',
            'wa_number' => '6281234567890',
            'consent_at' => now(),
            'active' => true,
        ]);

        $notifications = collect(['queued', 'held', 'failed', 'sent'])->mapWithKeys(
            fn (string $status): array => [$status => NotificationLog::create([
                'attendance_id' => $attendance->id,
                'guardian_id' => $guardian->id,
                'event_type' => 'check_in',
                'idempotency_key' => 'CORRECTION-'.$status.'-'.$attendance->id,
                'template_name' => 'siswa_hadir_v1',
                'recipient_phone' => '6281234567890',
                'payload' => [],
                'status' => $status,
                'attempts' => 0,
                'queued_at' => now(),
            ])]
        );

        $this->actingAs($this->admin)->post(route('attendances.correct', $attendance), [
            'status' => 'izin',
            'override_reason' => 'Surat izin resmi diterima.',
        ])->assertSessionHas('success');

        foreach (['queued', 'held', 'failed'] as $status) {
            $notification = $notifications[$status]->fresh();

            $this->assertSame('cancelled', $notification->status);
            $this->assertStringContainsString('dikoreksi manual', $notification->error_message);
        }

        $this->assertSame('sent', $notifications['sent']->fresh()->status);
    }

    private function createAttendance(array $attributes): Attendance
    {
        return Attendance::create(array_merge([
            'student_id' => $this->student->id,
            'school_date' => '2026-10-14',
            'status' => 'hadir',
            'policy_version' => 'v1.0',
        ], $attributes));
    }
}
