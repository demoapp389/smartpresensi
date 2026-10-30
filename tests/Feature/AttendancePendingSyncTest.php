<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AttendanceLog;
use App\Models\DeviceUser;
use App\Models\FingerprintDevice;
use App\Models\SchoolClass;
use App\Models\SchoolHoliday;
use App\Models\SchoolSchedule;
use App\Models\Student;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendancePendingSyncTest extends TestCase
{
    use RefreshDatabase;

    private SchoolClass $class;

    protected function setUp(): void
    {
        parent::setUp();

        $this->class = SchoolClass::create([
            'name' => 'XII-RPL 1',
            'academic_year' => '2026/2027',
        ]);

        foreach ([1, 5] as $day) {
            SchoolSchedule::create([
                'day_pattern' => 'regular',
                'day_name' => $day === 1 ? 'Senin' : 'Jumat',
                'day_of_week' => $day,
                'check_in_start' => '06:00:00',
                'start_at' => '07:00:00',
                'late_after' => '07:15:00',
                'check_out_start' => '14:00:00',
                'end_at' => '15:00:00',
                'is_active' => true,
            ]);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_command_materializes_missing_students_as_pending_after_cutoff_and_is_idempotent(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-26 17:00:00', 'Asia/Jakarta'));

        $missing = $this->createStudent('11001', 'Siswa Tanpa Scan');
        $recorded = $this->createStudent('11002', 'Siswa Sudah Tercatat');
        $this->createStudent('11003', 'Siswa Nonaktif', active: false);

        Attendance::create([
            'student_id' => $recorded->id,
            'school_date' => '2026-10-26',
            'status' => 'izin',
            'is_manual_override' => true,
            'override_reason' => 'Surat izin.',
            'policy_version' => 'v1.0',
        ]);

        $this->artisan('attendance:sync-pending')->assertSuccessful();

        $attendance = Attendance::where('student_id', $missing->id)
            ->whereDate('school_date', '2026-10-26')
            ->firstOrFail();

        $this->assertSame('perlu_tinjauan', $attendance->status);
        $this->assertNull($attendance->check_in_at);
        $this->assertNull($attendance->check_out_at);
        $this->assertFalse($attendance->is_manual_override);
        $this->assertDatabaseCount('attendances', 2);

        $this->artisan('attendance:sync-pending')->assertSuccessful();

        $this->assertDatabaseCount('attendances', 2);
        $this->assertSame(1, Attendance::where('status', 'perlu_tinjauan')->count());
    }

    public function test_command_does_not_materialize_before_cutoff(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-26 14:00:00', 'Asia/Jakarta'));
        $this->createStudent('11004', 'Siswa Belum Cutoff');

        $this->artisan('attendance:sync-pending')->assertSuccessful();

        $this->assertDatabaseCount('attendances', 0);
    }

    public function test_command_skips_holidays(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-26 17:00:00', 'Asia/Jakarta'));
        $this->createStudent('11005', 'Siswa Libur');

        SchoolHoliday::create([
            'name' => 'Libur Sekolah',
            'start_date' => '2026-10-26',
            'end_date' => '2026-10-26',
            'is_active' => true,
        ]);

        $this->artisan('attendance:sync-pending')->assertSuccessful();

        $this->assertDatabaseCount('attendances', 0);
    }

    public function test_command_turns_only_old_no_scan_pending_into_alpa_on_the_next_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-26 00:30:00', 'Asia/Jakarta'));

        $noScan = $this->createStudent('11006', 'Siswa Tanpa Scan Lama');
        $checkInOnly = $this->createStudent('11007', 'Siswa Scan Masuk');
        $manualAbsence = $this->createStudent('11008', 'Siswa Izin Manual');
        $todayPending = $this->createStudent('11009', 'Siswa Pending Hari Ini');

        $oldNoScan = Attendance::create([
            'student_id' => $noScan->id,
            'school_date' => '2026-10-23',
            'status' => 'perlu_tinjauan',
            'policy_version' => 'v1.1',
        ]);

        $oldCheckInOnly = Attendance::create([
            'student_id' => $checkInOnly->id,
            'school_date' => '2026-10-23',
            'check_in_at' => '2026-10-23 07:05:00',
            'check_in_status' => 'tepat_waktu',
            'check_out_status' => 'belum_absen',
            'status' => 'hadir',
            'policy_version' => 'v1.0',
        ]);

        Attendance::create([
            'student_id' => $manualAbsence->id,
            'school_date' => '2026-10-23',
            'status' => 'izin',
            'is_manual_override' => true,
            'override_reason' => 'Surat izin.',
            'policy_version' => 'v1.0',
        ]);

        $currentPending = Attendance::create([
            'student_id' => $todayPending->id,
            'school_date' => '2026-10-26',
            'status' => 'perlu_tinjauan',
            'policy_version' => 'v1.1',
        ]);

        $this->artisan('attendance:sync-pending')->assertSuccessful();

        $oldNoScan->refresh();
        $this->assertSame('alpa', $oldNoScan->status);
        $this->assertSame('Auto-Alpa H+1: tidak ada scan masuk dan pulang serta belum ditinjau.', $oldNoScan->override_reason);
        $this->assertNull($oldNoScan->operator_id);

        $this->assertSame('hadir', $oldCheckInOnly->fresh()->status);
        $this->assertSame('izin', Attendance::findOrFail($manualAbsence->id)->status);
        $this->assertSame('perlu_tinjauan', $currentPending->fresh()->status);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'auto_alpa_pending_attendance',
            'entity_id' => $oldNoScan->id,
        ]);
    }

    public function test_auto_alpa_skips_student_with_unprocessed_scan(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-26 00:30:00', 'Asia/Jakarta'));

        $student = $this->createStudent('11011', 'Siswa Scan Belum Diproses');
        $attendance = Attendance::create([
            'student_id' => $student->id,
            'school_date' => '2026-10-23',
            'status' => 'perlu_tinjauan',
            'policy_version' => Attendance::POLICY_VERSION_PENDING,
        ]);

        $device = FingerprintDevice::create([
            'name' => 'Mesin Uji Auto-Alpa',
            'ip' => '192.168.1.250',
            'port' => 80,
            'comm_key' => '0',
            'timezone' => 'Asia/Jakarta',
            'status' => 'active',
            'secret_token' => 'token_auto_alpa_guard',
            'active' => true,
        ]);

        DeviceUser::create([
            'device_id' => $device->id,
            'machine_user_id' => $student->nis,
            'student_id' => $student->id,
        ]);

        AttendanceLog::create([
            'device_id' => $device->id,
            'machine_user_id' => $student->nis,
            'occurred_at' => '2026-10-23 07:05:00',
            'received_at' => now(),
            'source_key' => 'auto-alpa-guard-scan',
            'status' => 'unprocessed',
        ]);

        $this->artisan('attendance:sync-pending')->assertSuccessful();

        $attendance->refresh();

        $this->assertSame('perlu_tinjauan', $attendance->status);
        $this->assertNull($attendance->override_reason);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'auto_alpa_pending_attendance',
            'entity_id' => $attendance->id,
        ]);
    }

    public function test_auto_alpa_skips_student_with_failed_scan(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-26 00:30:00', 'Asia/Jakarta'));

        $student = $this->createStudent('11013', 'Siswa Scan Gagal');
        $attendance = $this->createPendingAttendance($student);
        $device = $this->createDevice('192.168.1.251', 'token_auto_alpa_failed');

        DeviceUser::create([
            'device_id' => $device->id,
            'machine_user_id' => $student->nis,
            'student_id' => $student->id,
        ]);

        AttendanceLog::create([
            'device_id' => $device->id,
            'machine_user_id' => $student->nis,
            'occurred_at' => '2026-10-23 07:05:00',
            'received_at' => now(),
            'source_key' => 'auto-alpa-failed-scan',
            'status' => AttendanceLog::STATUS_FAILED,
            'processing_note' => 'Gagal memproses scan.',
        ]);

        $this->artisan('attendance:sync-pending')->assertSuccessful();

        $this->assertSame('perlu_tinjauan', $attendance->fresh()->status);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'auto_alpa_pending_attendance',
            'entity_id' => $attendance->id,
        ]);
    }

    public function test_auto_alpa_skips_student_with_unmapped_scan_matching_nis(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-26 00:30:00', 'Asia/Jakarta'));

        $student = $this->createStudent('11014', 'Siswa Scan Belum Dipetakan');
        $attendance = $this->createPendingAttendance($student);
        $device = $this->createDevice('192.168.1.252', 'token_auto_alpa_unmapped');

        AttendanceLog::create([
            'device_id' => $device->id,
            'machine_user_id' => $student->nis,
            'occurred_at' => '2026-10-23 07:05:00',
            'received_at' => now(),
            'source_key' => 'auto-alpa-unmapped-scan',
            'status' => AttendanceLog::STATUS_UNMAPPED,
        ]);

        $this->artisan('attendance:sync-pending')->assertSuccessful();

        $this->assertSame('perlu_tinjauan', $attendance->fresh()->status);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'auto_alpa_pending_attendance',
            'entity_id' => $attendance->id,
        ]);
    }

    public function test_auto_alpa_does_not_block_on_unmapped_scan_for_another_pin(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-26 00:30:00', 'Asia/Jakarta'));

        $student = $this->createStudent('11015', 'Siswa Tanpa Scan Terkait');
        $attendance = $this->createPendingAttendance($student);
        $device = $this->createDevice('192.168.1.253', 'token_auto_alpa_other_pin');

        AttendanceLog::create([
            'device_id' => $device->id,
            'machine_user_id' => '99999',
            'occurred_at' => '2026-10-23 07:05:00',
            'received_at' => now(),
            'source_key' => 'auto-alpa-other-pin-scan',
            'status' => AttendanceLog::STATUS_UNMAPPED,
        ]);

        $this->artisan('attendance:sync-pending')->assertSuccessful();

        $this->assertSame('alpa', $attendance->fresh()->status);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'auto_alpa_pending_attendance',
            'entity_id' => $attendance->id,
        ]);
    }

    public function test_auto_alpa_does_not_overwrite_manual_correction(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-26 00:30:00', 'Asia/Jakarta'));

        $student = $this->createStudent('11012', 'Siswa Koreksi Manual');
        $admin = User::factory()->admin()->create();
        $attendance = Attendance::create([
            'student_id' => $student->id,
            'school_date' => '2026-10-23',
            'status' => 'sakit',
            'is_manual_override' => true,
            'override_reason' => 'Surat dokter.',
            'operator_id' => $admin->id,
            'policy_version' => Attendance::POLICY_VERSION_BASE,
        ]);

        $this->artisan('attendance:sync-pending')->assertSuccessful();

        $attendance->refresh();

        $this->assertSame('sakit', $attendance->status);
        $this->assertSame('Surat dokter.', $attendance->override_reason);
        $this->assertSame($admin->id, $attendance->operator_id);
        $this->assertTrue($attendance->is_manual_override);
    }

    public function test_dashboard_counts_pending_as_belum_hadir_not_alpa(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-26 17:00:00', 'Asia/Jakarta'));

        $student = $this->createStudent('11010', 'Siswa Pending');
        $admin = User::factory()->admin()->create();

        Attendance::create([
            'student_id' => $student->id,
            'school_date' => '2026-10-26',
            'status' => 'perlu_tinjauan',
            'policy_version' => 'v1.1',
        ]);

        $response = $this->actingAs($admin)->get(route('dashboard', [
            'date' => '2026-10-26',
        ]));

        $response->assertOk();
        $response->assertViewHas('pendingCount', 1);
        $response->assertViewHas('alpaCount', 0);
        $response->assertViewHas('virtualAlpaCount', 0);
        $response->assertViewHas('belumHadirCount', 1);
    }

    private function createPendingAttendance(Student $student): Attendance
    {
        return Attendance::create([
            'student_id' => $student->id,
            'school_date' => '2026-10-23',
            'status' => 'perlu_tinjauan',
            'policy_version' => Attendance::POLICY_VERSION_PENDING,
        ]);
    }

    private function createDevice(string $ip, string $token): FingerprintDevice
    {
        return FingerprintDevice::create([
            'name' => 'Mesin Uji '.$token,
            'ip' => $ip,
            'port' => 80,
            'comm_key' => '0',
            'timezone' => 'Asia/Jakarta',
            'status' => 'active',
            'secret_token' => $token,
            'active' => true,
        ]);
    }

    private function createStudent(string $nis, string $name, bool $active = true): Student
    {
        return Student::create([
            'nis' => $nis,
            'name' => $name,
            'class_id' => $this->class->id,
            'gender' => 'L',
            'active' => $active,
        ]);
    }
}
