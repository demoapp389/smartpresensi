<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AttendanceLog;
use App\Models\DeviceUser;
use App\Models\FingerprintDevice;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Tests\TestCase;

class Phase2FeaturesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate');
        $this->artisan('db:seed');
    }

    public function test_can_create_and_delete_class(): void
    {
        $admin = User::where('role', 'admin')->first();
        $this->actingAs($admin);

        $response = $this->post(route('classes.store'), [
            'name' => 'XII MIPA 2',
            'academic_year' => '2026/2027',
        ]);

        $response->assertRedirect(route('classes.index'));
        $this->assertDatabaseHas('classes', ['name' => 'XII MIPA 2']);

        $class = SchoolClass::where('name', 'XII MIPA 2')->first();
        $deleteResponse = $this->delete(route('classes.destroy', $class));
        $deleteResponse->assertRedirect(route('classes.index'));
        $this->assertDatabaseMissing('classes', ['name' => 'XII MIPA 2']);
    }

    public function test_can_create_student_with_guardian_and_device_mapping(): void
    {
        $admin = User::where('role', 'admin')->first();
        $this->actingAs($admin);

        $class = SchoolClass::create(['name' => 'X MIPA 1', 'academic_year' => '2026/2027']);
        $device = FingerprintDevice::first();

        $response = $this->post(route('students.store'), [
            'nis' => '20269999',
            'nisn' => '0079999999',
            'name' => 'Zaky Pratama',
            'gender' => 'L',
            'class_id' => $class->id,
            'guardian_name' => 'Hasan Pratama',
            'relationship' => 'Ayah',
            'wa_number' => '081299998888',
            'consent_given' => '1',
            'device_id' => $device->id,
            'machine_user_id' => '1099',
        ]);

        $response->assertRedirect(route('students.index'));
        $this->assertDatabaseHas('students', ['nis' => '20269999', 'name' => 'Zaky Pratama']);
        $this->assertDatabaseHas('guardians', ['wa_number' => '6281299998888', 'name' => 'Hasan Pratama']);
        $this->assertDatabaseHas('device_users', ['device_id' => $device->id, 'machine_user_id' => '1099']);
    }

    public function test_can_create_student_without_guardian_whatsapp_number(): void
    {
        $admin = User::where('role', 'admin')->first();
        $this->actingAs($admin);

        $class = SchoolClass::create(['name' => 'X MIPA 4', 'academic_year' => '2026/2027']);

        $response = $this->post(route('students.store'), [
            'nis' => '20268888',
            'nisn' => '0078888888',
            'name' => 'Tanpa Nomor Wa',
            'gender' => 'P',
            'class_id' => $class->id,
            'guardian_name' => 'Wali Tanpa Nomor',
            'relationship' => 'Ibu',
            'wa_number' => '',
        ]);

        $response->assertRedirect(route('students.index'));
        $response->assertSessionHasNoErrors();

        $student = Student::where('nis', '20268888')->first();
        $this->assertNotNull($student);

        $guardian = $student->guardians()->first();
        $this->assertNotNull($guardian);
        $this->assertNull($guardian->wa_number, 'Nomor WA kosong harus disimpan kosong.');
        $this->assertNull($guardian->consent_at, 'Consent WA harus kosong secara default.');
    }

    public function test_can_clear_guardian_whatsapp_number_when_editing_student(): void
    {
        $admin = User::where('role', 'admin')->first();
        $this->actingAs($admin);

        $class = SchoolClass::create(['name' => 'X MIPA 5', 'academic_year' => '2026/2027']);
        $student = Student::create([
            'nis' => '20267777',
            'name' => 'Nanti Dihapus',
            'gender' => 'L',
            'class_id' => $class->id,
            'active' => true,
        ]);
        $student->guardians()->create([
            'name' => 'Wali Lama',
            'relationship' => 'Ayah',
            'wa_number' => '628555555555',
            'consent_at' => now(),
            'active' => true,
        ]);

        $response = $this->put(route('students.update', $student), [
            'nis' => '20267777',
            'nisn' => '0077777777',
            'name' => 'Nanti Dihapus',
            'gender' => 'L',
            'class_id' => $class->id,
            'guardian_name' => 'Wali Lama',
            'relationship' => 'Ayah',
            'wa_number' => '',
            'consent_given' => '1',
        ]);

        $response->assertRedirect(route('students.index'));
        $response->assertSessionHasNoErrors();

        $guardian = $student->guardians()->first();
        $this->assertNull($guardian->fresh()->wa_number);
        $this->assertNotNull($guardian->fresh()->consent_at, 'Consent yang dicentang tetap tersimpan.');
    }

    public function test_operator_can_correct_attendance_and_record_audit_log(): void
    {
        $operator = User::where('role', 'operator')->first();
        $this->actingAs($operator);

        $class = SchoolClass::create(['name' => 'X MIPA 1', 'academic_year' => '2026/2027']);
        $student = Student::create([
            'nis' => '20260001',
            'name' => 'Budi',
            'gender' => 'L',
            'class_id' => $class->id,
            'active' => true,
        ]);

        $attendance = Attendance::create([
            'student_id' => $student->id,
            'school_date' => Carbon::today('Asia/Jakarta')->toDateString(),
            'status' => 'hadir',
            'policy_version' => 'v1.0',
        ]);

        $response = $this->post(route('attendances.correct', $attendance), [
            'status' => 'izin',
            'override_reason' => 'Siswa menyerahkan surat izin orang tua karena ada urusan keluarga.',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('attendances', [
            'id' => $attendance->id,
            'status' => 'izin',
            'is_manual_override' => true,
            'override_reason' => 'Siswa menyerahkan surat izin orang tua karena ada urusan keluarga.',
            'operator_id' => $operator->id,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $operator->id,
            'action' => 'manual_override_attendance',
            'entity_type' => 'Attendance',
            'entity_id' => $attendance->id,
        ]);
    }

    public function test_fingerprint_api_ingest_from_csharp_connector(): void
    {
        $device = FingerprintDevice::first();
        $class = SchoolClass::create(['name' => 'X MIPA 1', 'academic_year' => '2026/2027']);
        $student = Student::create([
            'nis' => '20260002',
            'name' => 'Ahmad',
            'gender' => 'L',
            'class_id' => $class->id,
            'active' => true,
        ]);

        $deviceUser = DeviceUser::create([
            'device_id' => $device->id,
            'machine_user_id' => '7777',
            'student_id' => $student->id,
        ]);

        $now = Carbon::now('Asia/Jakarta');

        $response = $this->withHeaders([
            'X-Device-Token' => $device->secret_token,
        ])->postJson(route('api.fingerprint.ingest'), [
            'logs' => [
                [
                    'pin' => '7777',
                    'time' => $now->toDateTimeString(),
                    'source_key' => $device->id.'_7777_'.$now->timestamp,
                ],
            ],
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
            ]);

        $this->assertDatabaseHas('attendance_logs', [
            'device_id' => $device->id,
            'machine_user_id' => '7777',
            'status' => 'processed',
        ]);
    }

    public function test_unmapped_log_can_be_mapped_and_auto_processed(): void
    {
        $admin = User::where('role', 'admin')->first();
        $this->actingAs($admin);

        $device = FingerprintDevice::first();
        $class = SchoolClass::create(['name' => 'X MIPA 1', 'academic_year' => '2026/2027']);
        $student = Student::create([
            'nis' => '20260003',
            'name' => 'Citra',
            'gender' => 'P',
            'class_id' => $class->id,
            'active' => true,
        ]);

        $unmappedTime = Carbon::now('Asia/Jakarta');
        $rawLog = AttendanceLog::create([
            'device_id' => $device->id,
            'machine_user_id' => '8888',
            'occurred_at' => $unmappedTime,
            'received_at' => $unmappedTime,
            'source_key' => $device->id.'_8888_'.$unmappedTime->timestamp,
            'status' => 'unmapped',
        ]);

        $response = $this->post(route('logs.unmapped.map'), [
            'device_id' => $device->id,
            'machine_user_id' => '8888',
            'student_id' => $student->id,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('device_users', [
            'device_id' => $device->id,
            'machine_user_id' => '8888',
            'student_id' => $student->id,
        ]);

        // Log mentah harus berubah dari unmapped menjadi processed
        $this->assertDatabaseHas('attendance_logs', [
            'id' => $rawLog->id,
            'status' => 'processed',
        ]);
    }

    public function test_all_sidebar_pages_render_status_200_for_authenticated_admin(): void
    {
        $admin = User::where('role', 'admin')->first();
        $this->actingAs($admin);

        $routes = [
            route('dashboard'),
            route('attendances.index'),
            route('attendances.correction'),
            route('logs.raw'),
            route('logs.unmapped'),
            route('students.index'),
            route('classes.index'),
            route('devices.index'),
            route('devices.mappings'),
            route('devices.sync-history'),
            route('devices.show', FingerprintDevice::first()),
            route('schedules.index'),
            route('notifications.index'),
            route('notifications.gateway'),
            route('guardians.validation'),
            route('audit.index'),
        ];

        foreach ($routes as $url) {
            $response = $this->get($url);
            $response->assertStatus(200);
        }
    }

    public function test_raw_log_page_renders_client_side_datatables_without_server_pagination(): void
    {
        $admin = User::where('role', 'admin')->first();

        $response = $this->actingAs($admin)->get(route('logs.raw'));

        $response->assertOk();

        // Data dikirim penuh ke DataTables sisi klien, bukan paginasi server.
        $response->assertViewHas('logs', fn ($logs) => $logs instanceof Collection);

        $html = $response->getContent();
        $this->assertStringContainsString('data-datatable', $html);
        $this->assertStringContainsString('data-toolbar-filter="#raw-filter"', $html);
        $this->assertStringNotContainsString('Halaman 1 dari', $html);
    }

    public function test_unmapped_log_page_renders_client_side_datatables_without_server_pagination(): void
    {
        $admin = User::where('role', 'admin')->first();

        $response = $this->actingAs($admin)->get(route('logs.unmapped'));

        $response->assertOk();
        $response->assertViewHas('unmappedLogs', fn ($logs) => $logs instanceof Collection);

        $html = $response->getContent();
        $this->assertStringContainsString('data-toolbar-filter="#unmapped-filter"', $html);
        $this->assertStringNotContainsString('Halaman 1 dari', $html);
    }

    public function test_correction_page_renders_client_side_datatables_without_server_pagination(): void
    {
        $admin = User::where('role', 'admin')->first();

        $response = $this->actingAs($admin)->get(route('attendances.correction'));

        $response->assertOk();
        $response->assertViewHas('attendances', fn ($attendances) => $attendances instanceof Collection);

        $html = $response->getContent();
        $this->assertStringContainsString('data-toolbar-filter="#correction-filter"', $html);
        $this->assertStringNotContainsString('Halaman 1 dari', $html);
    }
}
