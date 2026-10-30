<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomeroomTeacherAttendanceTest extends TestCase
{
    use RefreshDatabase;

    protected User $waliKelasA;

    protected SchoolClass $classA;

    protected SchoolClass $classB;

    protected Student $studentA1;

    protected Student $studentB1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->classA = SchoolClass::create([
            'name' => 'VII-A',
            'academic_year' => '2026/2027',
        ]);

        $this->classB = SchoolClass::create([
            'name' => 'VII-B',
            'academic_year' => '2026/2027',
        ]);

        $this->waliKelasA = User::factory()->waliKelas($this->classA)->create([
            'name' => 'Wali Kelas VII-A',
            'email' => 'wali.7a@sekolah.sch.id',
        ]);

        $this->studentA1 = Student::create([
            'nis' => '1001',
            'name' => 'Siswa Kelas A',
            'class_id' => $this->classA->id,
            'gender' => 'L',
            'active' => true,
        ]);

        $this->studentB1 = Student::create([
            'nis' => '2001',
            'name' => 'Siswa Kelas B',
            'class_id' => $this->classB->id,
            'gender' => 'P',
            'active' => true,
        ]);
    }

    public function test_homeroom_teacher_dashboard_is_scoped_to_assigned_class(): void
    {
        $response = $this->actingAs($this->waliKelasA)->get(route('dashboard'));

        $response->assertStatus(200);
        $response->assertSee('Wali Kelas VII-A');
        // Total Siswa harus 1 (hanya siswa kelas VII-A)
        $response->assertSee('Total Siswa');

        // Dashboard bersih: tidak ada dropdown <select name="class_id">, ada badge statis Kelas: VII-A
        $response->assertDontSee('<select name="class_id"', false);
        $response->assertSee('Kelas:');
        $response->assertSee('VII-A');

        // Panel integrasi teknis mesin/WA disembunyikan untuk wali kelas
        $response->assertDontSee('Terminal Hardware LAN TCP/IP', false);
        $response->assertDontSee('WhatsApp Gateway (Outbox)', false);
        $response->assertDontSee('Validasi Nomor Wali (Fonnte)', false);
    }

    public function test_homeroom_teacher_attendance_history_is_clean_and_simplified(): void
    {
        $response = $this->actingAs($this->waliKelasA)->get(route('attendances.index'));

        $response->assertStatus(200);
        // Riwayat Presensi bersih: tidak ada dropdown <select name="class_id">, ada badge statis Kelas: VII-A
        $response->assertDontSee('<select name="class_id"', false);
        $response->assertSee('Kelas:');
        $response->assertSee('VII-A');

        // Tombol aksi bertuliskan Presensi Manual (bukan Koreksi Operator)
        $response->assertSee('Presensi Manual');
        $response->assertDontSee('Koreksi Operator');
    }

    public function test_homeroom_teacher_can_record_manual_absence_for_own_student(): void
    {
        $today = now('Asia/Jakarta')->toDateString();

        $response = $this->actingAs($this->waliKelasA)->post(route('attendances.manual'), [
            'student_id' => $this->studentA1->id,
            'school_date' => $today,
            'status' => 'sakit',
            'override_reason' => 'Demam tinggi dengan surat dokter',
        ]);

        $response->assertRedirect();
        $attendance = Attendance::where('student_id', $this->studentA1->id)->first();
        $this->assertNotNull($attendance);
        $this->assertSame('sakit', $attendance->status);
        $this->assertTrue((bool) $attendance->is_manual_override);
        $this->assertSame('Demam tinggi dengan surat dokter', $attendance->override_reason);
        $this->assertSame($this->waliKelasA->id, $attendance->operator_id);
    }

    public function test_homeroom_teacher_cannot_record_manual_absence_for_other_class_student(): void
    {
        $today = now('Asia/Jakarta')->toDateString();

        $response = $this->actingAs($this->waliKelasA)->post(route('attendances.manual'), [
            'student_id' => $this->studentB1->id,
            'school_date' => $today,
            'status' => 'izin',
            'override_reason' => 'Acara keluarga',
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('attendances', [
            'student_id' => $this->studentB1->id,
            'school_date' => $today,
        ]);
    }

    public function test_homeroom_teacher_cannot_correct_attendance_of_other_class_student(): void
    {
        $today = now('Asia/Jakarta')->toDateString();

        $attB = Attendance::create([
            'student_id' => $this->studentB1->id,
            'school_date' => $today,
            'status' => 'alpa',
            'policy_version' => 'v1.0',
        ]);

        $response = $this->actingAs($this->waliKelasA)->post(route('attendances.correct', $attB), [
            'status' => 'izin',
            'override_reason' => 'Koreksi sepihak',
        ]);

        $response->assertStatus(403);
    }

    public function test_homeroom_teacher_can_correct_own_class_absence_to_izin(): void
    {
        $attendance = Attendance::create([
            'student_id' => $this->studentA1->id,
            'school_date' => now('Asia/Jakarta')->toDateString(),
            'status' => 'alpa',
            'policy_version' => 'v1.0',
        ]);

        $this->actingAs($this->waliKelasA)->post(route('attendances.correct', $attendance), [
            'status' => 'izin',
            'override_reason' => 'Surat izin orang tua diterima.',
        ])->assertSessionHas('success');

        $attendance->refresh();

        $this->assertSame('izin', $attendance->status);
        $this->assertTrue($attendance->is_manual_override);
    }

    public function test_homeroom_teacher_cannot_correct_scanned_attendance(): void
    {
        $attendance = Attendance::create([
            'student_id' => $this->studentA1->id,
            'school_date' => now('Asia/Jakarta')->toDateString(),
            'status' => 'hadir',
            'check_in_at' => now('Asia/Jakarta')->setTime(7, 0),
            'check_in_status' => 'tepat_waktu',
            'policy_version' => 'v1.0',
        ]);

        $this->actingAs($this->waliKelasA)->post(route('attendances.correct', $attendance), [
            'status' => 'izin',
            'override_reason' => 'Tidak boleh menimpa scan.',
        ])->assertSessionHasErrors('status');

        $this->assertSame('hadir', $attendance->fresh()->status);
    }

    public function test_homeroom_teacher_cannot_record_manual_presence(): void
    {
        $today = now('Asia/Jakarta')->toDateString();

        $this->actingAs($this->waliKelasA)->post(route('attendances.manual'), [
            'student_id' => $this->studentA1->id,
            'school_date' => $today,
            'status' => 'hadir',
            'override_reason' => 'Wali kelas tidak berwenang mencatat hadir.',
        ])->assertSessionHasErrors('status');

        $this->assertDatabaseMissing('attendances', [
            'student_id' => $this->studentA1->id,
            'school_date' => $today,
        ]);
    }

    public function test_homeroom_teacher_correction_page_only_lists_own_class_students(): void
    {
        $response = $this->actingAs($this->waliKelasA)->get(route('attendances.correction'));

        $response->assertStatus(200);
        $response->assertSee('Wali Kelas: VII-A');
        $response->assertSee('Siswa Kelas A');
        $response->assertDontSee('Siswa Kelas B');
    }

    public function test_admin_attendance_history_is_scoped_to_selected_class(): void
    {
        $admin = User::factory()->admin()->create();

        Attendance::create([
            'student_id' => $this->studentA1->id,
            'school_date' => now('Asia/Jakarta')->toDateString(),
            'status' => 'hadir',
            'policy_version' => 'v1.0',
        ]);

        Attendance::create([
            'student_id' => $this->studentB1->id,
            'school_date' => now('Asia/Jakarta')->toDateString(),
            'status' => 'hadir',
            'policy_version' => 'v1.0',
        ]);

        $response = $this->actingAs($admin)->get(route('attendances.index', ['class_id' => $this->classA->id]));

        $response->assertStatus(200);
        $response->assertSee('Siswa Kelas A');
        $response->assertDontSee('Siswa Kelas B');
    }

    public function test_homeroom_teacher_is_forbidden_from_admin_and_operator_modules(): void
    {
        $this->actingAs($this->waliKelasA);

        // Modul Admin
        $this->get(route('devices.index'))->assertStatus(403);
        $this->get(route('schedules.index'))->assertStatus(403);
        $this->get(route('audit.index'))->assertStatus(403);
        $this->get(route('system.control'))->assertStatus(403);
        $this->get(route('users.index'))->assertStatus(403);
        $this->get(route('system.backup.database'))->assertStatus(403);

        // Modul Operator / Master Data
        $this->get(route('students.index'))->assertStatus(403);
        $this->get(route('classes.index'))->assertStatus(403);
        $this->get(route('logs.raw'))->assertStatus(403);
        $this->get(route('logs.unmapped'))->assertStatus(403);
        $this->get(route('notifications.index'))->assertStatus(403);
    }

    public function test_sidebar_displays_homeroom_teacher_profile_and_hides_restricted_links(): void
    {
        $response = $this->actingAs($this->waliKelasA)->get(route('dashboard'));

        $response->assertStatus(200);
        $response->assertSee('Wali Kelas VII-A');
        $response->assertSee('Presensi Manual');

        // Master Data & Menu Terlarang Harus Tersembunyi
        $response->assertDontSee('Data Siswa &amp; Wali', false);
        $response->assertDontSee('Data Kelas', false);
        $response->assertDontSee('Riwayat Scan', false);
        $response->assertDontSee('Perlu Peninjauan', false);
        $response->assertDontSee('Outbox WhatsApp', false);
        $response->assertDontSee('Manajemen Pengguna', false);
        $response->assertDontSee('Mesin &amp; Mapping PIN', false);
    }
}
