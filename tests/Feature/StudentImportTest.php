<?php

namespace Tests\Feature;

use App\Jobs\ImportStudentsFromExcel;
use App\Models\AttendanceLog;
use App\Models\DeviceUser;
use App\Models\FingerprintDevice;
use App\Models\Guardian;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\TaskProgress;
use App\Models\User;
use App\Services\SimpleXlsxExporter;
use App\Services\SimpleXlsxReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class StudentImportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create();
    }

    public function test_admin_can_download_student_import_template(): void
    {
        $response = $this->actingAs($this->admin)->get(route('students.template'));

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $rows = SimpleXlsxReader::parse($response->getFile()->getPathname());
        $this->assertContains('Persetujuan WA (Ya/Tidak)', $rows[0]);
    }

    public function test_admin_can_import_students_from_valid_xlsx(): void
    {
        $headers = [
            'NIS',
            'NISN',
            'Nama Siswa',
            'Jenis Kelamin (L/P)',
            'Kelas',
            'Nama Wali',
            'Hubungan',
            'No WhatsApp Wali',
            'PIN Mesin (Opsional)',
        ];

        $rows = [
            [
                '2026001',
                '0012345671',
                'Siswa Uji Coba Satu',
                'L',
                'X-TKJ 1',
                'Wali Siswa Satu',
                'Ayah',
                '081234567891',
                '',
            ],
            [
                '2026002',
                '0012345672',
                'Siswa Uji Coba Dua',
                'P',
                'X-TKJ 1',
                'Wali Siswa Dua',
                'Ibu',
                '081234567892',
                '',
            ],
        ];

        $tempPath = tempnam(sys_get_temp_dir(), 'test_import_').'.xlsx';
        $downloadResponse = SimpleXlsxExporter::download('dummy.xlsx', $headers, $rows);
        copy($downloadResponse->getFile()->getPathname(), $tempPath);

        $uploadedFile = new UploadedFile(
            $tempPath,
            'import_siswa.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true
        );

        $response = $this->actingAs($this->admin)->post(route('students.import'), [
            'file' => $uploadedFile,
        ]);

        $response->assertRedirect(route('students.index'));
        $response->assertSessionHas('success');

        // Verifikasi kelas dibuat otomatis
        $this->assertDatabaseHas('classes', [
            'name' => 'X-TKJ 1',
        ]);

        // Verifikasi siswa tersimpan
        $this->assertDatabaseHas('students', [
            'nis' => '2026001',
            'name' => 'Siswa Uji Coba Satu',
            'gender' => 'L',
        ]);

        $this->assertDatabaseHas('students', [
            'nis' => '2026002',
            'name' => 'Siswa Uji Coba Dua',
            'gender' => 'P',
        ]);

        // Verifikasi wali murid dengan E.164 (628...)
        $this->assertDatabaseHas('guardians', [
            'name' => 'Wali Siswa Satu',
            'wa_number' => '6281234567891',
        ]);

        $this->assertDatabaseHas('guardians', [
            'name' => 'Wali Siswa Dua',
            'wa_number' => '6281234567892',
        ]);

        if (file_exists($tempPath)) {
            @unlink($tempPath);
        }
    }

    public function test_import_updates_existing_student_by_nis(): void
    {
        $class = SchoolClass::create(['name' => 'X-RPL 1', 'academic_year' => '2026/2027']);
        $student = Student::create([
            'nis' => '10001',
            'name' => 'Nama Lama',
            'gender' => 'L',
            'class_id' => $class->id,
            'active' => true,
        ]);
        Guardian::create([
            'student_id' => $student->id,
            'name' => 'Wali Lama',
            'relationship' => 'Wali',
            'wa_number' => '628111111111',
            'active' => true,
        ]);

        $headers = ['NIS', 'NISN', 'Nama Siswa', 'Jenis Kelamin', 'Kelas', 'Nama Wali', 'Hubungan', 'No WA', 'PIN'];
        $rows = [
            ['10001', '0099887766', 'Nama Baru Setelah Import', 'L', 'X-RPL 1', 'Wali Diperbarui', 'Ayah', '081299999999', ''],
        ];

        $tempPath = tempnam(sys_get_temp_dir(), 'test_update_').'.xlsx';
        $downloadResponse = SimpleXlsxExporter::download('dummy.xlsx', $headers, $rows);
        copy($downloadResponse->getFile()->getPathname(), $tempPath);

        $uploadedFile = new UploadedFile(
            $tempPath,
            'update_siswa.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true
        );

        $response = $this->actingAs($this->admin)->post(route('students.import'), [
            'file' => $uploadedFile,
        ]);

        $response->assertRedirect(route('students.index'));
        $response->assertSessionHas('success');

        // Pastikan nama siswa diperbarui
        $this->assertDatabaseHas('students', [
            'id' => $student->id,
            'nis' => '10001',
            'name' => 'Nama Baru Setelah Import',
        ]);

        // Pastikan nama wali diperbarui
        $this->assertDatabaseHas('guardians', [
            'student_id' => $student->id,
            'name' => 'Wali Diperbarui',
            'wa_number' => '6281299999999',
        ]);

        if (file_exists($tempPath)) {
            @unlink($tempPath);
        }
    }

    public function test_import_with_empty_whatsapp_column_leaves_number_and_consent_blank(): void
    {
        $headers = ['NIS', 'NISN', 'Nama Siswa', 'Jenis Kelamin', 'Kelas', 'Nama Wali', 'Hubungan', 'No WhatsApp Wali', 'PIN Mesin'];
        $rows = [
            ['2026999', '0099999999', 'Siswa Tanpa Nomor WA', 'L', 'X-TMJ 1', 'Wali Tanpa Nomor', 'Ayah', '', ''],
        ];

        $tempPath = tempnam(sys_get_temp_dir(), 'test_no_wa_').'.xlsx';
        $downloadResponse = SimpleXlsxExporter::download('dummy.xlsx', $headers, $rows);
        copy($downloadResponse->getFile()->getPathname(), $tempPath);

        $uploadedFile = new UploadedFile(
            $tempPath,
            'import_tanpa_wa.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true
        );

        $response = $this->actingAs($this->admin)->post(route('students.import'), [
            'file' => $uploadedFile,
        ]);

        $response->assertRedirect(route('students.index'));
        $response->assertSessionHas('success');

        $guardian = Guardian::whereHas('student', fn ($query) => $query->where('nis', '2026999'))->first();

        $this->assertNotNull($guardian);
        $this->assertNull($guardian->wa_number, 'Nomor WA kosong harus disimpan kosong, bukan nomor placeholder.');
        $this->assertNull($guardian->consent_at, 'Consent WA harus kosong secara default.');

        if (file_exists($tempPath)) {
            @unlink($tempPath);
        }
    }

    public function test_import_preserves_existing_consent_when_number_is_unchanged(): void
    {
        $class = SchoolClass::create(['name' => 'X-RPL 3', 'academic_year' => '2026/2027']);
        $student = Student::create([
            'nis' => '10002',
            'name' => 'Siswa Consent Tetap',
            'gender' => 'L',
            'class_id' => $class->id,
            'active' => true,
        ]);
        $guardian = Guardian::create([
            'student_id' => $student->id,
            'name' => 'Wali Consent Tetap',
            'relationship' => 'Ayah',
            'wa_number' => '628222222222',
            'consent_at' => now(),
            'active' => true,
        ]);

        $headers = ['NIS', 'NISN', 'Nama Siswa', 'Jenis Kelamin', 'Kelas', 'Nama Wali', 'Hubungan', 'No WA', 'PIN'];
        $rows = [
            ['10002', '0011111111', 'Siswa Consent Tetap', 'L', 'X-RPL 3', 'Wali Consent Tetap', 'Ayah', '628222222222', ''],
        ];

        $tempPath = tempnam(sys_get_temp_dir(), 'test_consent_').'.xlsx';
        $downloadResponse = SimpleXlsxExporter::download('dummy.xlsx', $headers, $rows);
        copy($downloadResponse->getFile()->getPathname(), $tempPath);

        $uploadedFile = new UploadedFile(
            $tempPath,
            'import_consent.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true
        );

        $response = $this->actingAs($this->admin)->post(route('students.import'), [
            'file' => $uploadedFile,
        ]);

        $response->assertRedirect(route('students.index'));
        $response->assertSessionHas('success');

        $this->assertTrue($guardian->fresh()->consent_at !== null, 'Consent yang sudah diberikan tidak boleh dihapus oleh import.');
        $this->assertSame('628222222222', $guardian->fresh()->wa_number);

        if (file_exists($tempPath)) {
            @unlink($tempPath);
        }
    }

    public function test_import_grants_consent_for_new_guardian_when_marked_yes(): void
    {
        $file = $this->makeXlsxUpload(
            $this->importHeaders(),
            [['2026998', '0099999998', 'Siswa Consent Baru', 'P', 'X-TMJ 2', 'Wali Consent Baru', 'Ibu', '081234567899', '', 'Ya']],
            'import_consent_yes.xlsx'
        );

        $response = $this->actingAs($this->admin)->post(route('students.import'), [
            'file' => $file,
        ]);

        $response->assertRedirect(route('students.index'));
        $response->assertSessionHas('success');

        $guardian = Guardian::whereHas('student', fn ($query) => $query->where('nis', '2026998'))->firstOrFail();

        $this->assertSame('6281234567899', $guardian->wa_number);
        $this->assertNotNull($guardian->consent_at);
    }

    public function test_import_can_revoke_consent_when_marked_no(): void
    {
        $class = SchoolClass::create(['name' => 'X-RPL 4', 'academic_year' => '2026/2027']);
        $student = Student::create([
            'nis' => '10003',
            'name' => 'Siswa Consent Dicabut',
            'gender' => 'P',
            'class_id' => $class->id,
            'active' => true,
        ]);
        $guardian = Guardian::create([
            'student_id' => $student->id,
            'name' => 'Wali Consent Dicabut',
            'relationship' => 'Ibu',
            'wa_number' => '628333333333',
            'consent_at' => now(),
            'active' => true,
        ]);

        $file = $this->makeXlsxUpload(
            $this->importHeaders(),
            [['10003', '', 'Siswa Consent Dicabut', 'P', 'X-RPL 4', 'Wali Consent Dicabut', 'Ibu', '628333333333', '', 'Tidak']],
            'import_consent_no.xlsx'
        );

        $this->actingAs($this->admin)->post(route('students.import'), [
            'file' => $file,
        ])->assertRedirect(route('students.index'));

        $this->assertNull($guardian->fresh()->consent_at);
    }

    public function test_import_resets_consent_when_whatsapp_number_changes_without_explicit_consent(): void
    {
        $class = SchoolClass::create(['name' => 'X-RPL 5', 'academic_year' => '2026/2027']);
        $student = Student::create([
            'nis' => '10004',
            'name' => 'Siswa Nomor Berubah',
            'gender' => 'L',
            'class_id' => $class->id,
            'active' => true,
        ]);
        $guardian = Guardian::create([
            'student_id' => $student->id,
            'name' => 'Wali Nomor Berubah',
            'relationship' => 'Ayah',
            'wa_number' => '628444444444',
            'consent_at' => now(),
            'active' => true,
        ]);

        $headers = ['NIS', 'NISN', 'Nama Siswa', 'Jenis Kelamin', 'Kelas', 'Nama Wali', 'Hubungan', 'No WA', 'PIN'];
        $file = $this->makeXlsxUpload(
            $headers,
            [['10004', '', 'Siswa Nomor Berubah', 'L', 'X-RPL 5', 'Wali Nomor Berubah', 'Ayah', '628555555555', '']],
            'import_consent_number_changed.xlsx'
        );

        $this->actingAs($this->admin)->post(route('students.import'), [
            'file' => $file,
        ])->assertRedirect(route('students.index'));

        $guardian->refresh();
        $this->assertSame('628555555555', $guardian->wa_number);
        $this->assertNull($guardian->consent_at);
    }

    public function test_import_warns_when_consent_is_yes_without_whatsapp_number(): void
    {
        $file = $this->makeXlsxUpload(
            $this->importHeaders(),
            [['2026997', '', 'Siswa Consent Tanpa WA', 'L', 'X-TMJ 3', 'Wali Tanpa WA', 'Ayah', '', '', 'Ya']],
            'import_consent_without_wa.xlsx'
        );

        $response = $this->actingAs($this->admin)->post(route('students.import'), [
            'file' => $file,
        ]);

        $response->assertRedirect(route('students.index'));

        $guardian = Guardian::whereHas('student', fn ($query) => $query->where('nis', '2026997'))->firstOrFail();
        $this->assertNull($guardian->wa_number);
        $this->assertNull($guardian->consent_at);

        $results = TaskProgress::where('type', 'students.import')->latest('id')->firstOrFail()->results;
        $this->assertStringContainsString('nomor WhatsApp kosong', implode(' ', $results['errors']));
    }

    public function test_import_with_device_id_maps_machine_user_id(): void
    {
        $device = FingerprintDevice::create([
            'name' => 'Mesin Fingerprint Gerbang',
            'ip' => '192.168.1.201',
            'port' => 80,
            'comm_key' => '0',
            'active' => true,
        ]);

        $headers = ['NIS', 'NISN', 'Nama Siswa', 'Jenis Kelamin', 'Kelas', 'Nama Wali', 'Hubungan', 'No WA', 'PIN Mesin'];
        $rows = [
            ['10005', '0012345675', 'Siswa Finger', 'L', 'X-RPL 2', 'Wali Finger', 'Ayah', '081234567895', '888'],
        ];

        $tempPath = tempnam(sys_get_temp_dir(), 'test_device_').'.xlsx';
        $downloadResponse = SimpleXlsxExporter::download('dummy.xlsx', $headers, $rows);
        copy($downloadResponse->getFile()->getPathname(), $tempPath);

        $uploadedFile = new UploadedFile(
            $tempPath,
            'device_siswa.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true
        );

        $response = $this->actingAs($this->admin)->post(route('students.import'), [
            'file' => $uploadedFile,
            'device_id' => $device->id,
        ]);

        $response->assertRedirect(route('students.index'));
        $response->assertSessionHas('success');

        $student = Student::where('nis', '10005')->firstOrFail();

        $this->assertDatabaseHas('device_users', [
            'device_id' => $device->id,
            'student_id' => $student->id,
            'machine_user_id' => '888',
        ]);

        if (file_exists($tempPath)) {
            @unlink($tempPath);
        }
    }

    public function test_import_with_pin_but_without_device_records_row_error(): void
    {
        $file = $this->makeXlsxUpload(
            $this->importHeaders(),
            [['10007', '', 'Siswa Tanpa Mesin', 'L', 'X-RPL 4', 'Wali', 'Ayah', '081234567890', '777']],
            'pin_without_device.xlsx'
        );

        $response = $this->actingAs($this->admin)->post(route('students.import'), [
            'file' => $file,
        ]);

        $response->assertRedirect(route('students.index'));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('students', ['nis' => '10007']);
        $this->assertDatabaseCount('device_users', 0);

        $results = TaskProgress::where('type', 'students.import')->latest('id')->firstOrFail()->results;
        $this->assertStringContainsString('belum dipilih', implode(' ', $results['errors']));
    }

    public function test_import_mapping_only_row_maps_existing_student_without_touching_profile(): void
    {
        $device = FingerprintDevice::create([
            'name' => 'Mesin Mapping Saja',
            'ip' => '192.168.1.231',
            'port' => 80,
            'comm_key' => '0',
            'active' => true,
        ]);

        $student = Student::create([
            'nis' => '5001',
            'name' => 'Nama Asli Tetap',
            'gender' => 'L',
            'active' => true,
        ]);

        $file = $this->makeXlsxUpload(
            $this->importHeaders(),
            [['5001', '', '', '', '', '', '', '', '555']],
            'mapping_only.xlsx'
        );

        $this->actingAs($this->admin)->post(route('students.import'), [
            'file' => $file,
            'device_id' => $device->id,
        ])->assertRedirect(route('students.index'));

        $this->assertDatabaseHas('device_users', [
            'device_id' => $device->id,
            'student_id' => $student->id,
            'machine_user_id' => '555',
        ]);
        $this->assertSame('Nama Asli Tetap', $student->fresh()->name);
        $this->assertDatabaseCount('guardians', 0);
        $this->assertDatabaseCount('classes', 0);

        $results = TaskProgress::where('type', 'students.import')->latest('id')->firstOrFail()->results;
        $this->assertSame(1, $results['mapped']);
        $this->assertSame(0, $results['created']);
    }

    public function test_import_mapping_reprocesses_unmapped_logs(): void
    {
        $device = FingerprintDevice::create([
            'name' => 'Mesin Replay Import',
            'ip' => '192.168.1.236',
            'port' => 80,
            'comm_key' => '0',
            'active' => true,
        ]);
        $student = Student::create([
            'nis' => '5002',
            'name' => 'Siswa Replay Import',
            'gender' => 'L',
            'active' => true,
        ]);

        $occurredAt = now()->subDay()->setTime(7, 5);
        $log = AttendanceLog::create([
            'device_id' => $device->id,
            'machine_user_id' => '556',
            'occurred_at' => $occurredAt,
            'received_at' => $occurredAt->copy()->addMinute(),
            'source_key' => 'x601_556_4001',
            'status' => AttendanceLog::STATUS_UNMAPPED,
        ]);

        $file = $this->makeXlsxUpload(
            $this->importHeaders(),
            [['5002', '', '', '', '', '', '', '', '556']],
            'mapping_replay.xlsx'
        );

        $this->actingAs($this->admin)->post(route('students.import'), [
            'file' => $file,
            'device_id' => $device->id,
        ])->assertRedirect(route('students.index'));

        $this->assertSame(AttendanceLog::STATUS_PROCESSED, $log->fresh()->status);
        $this->assertDatabaseHas('attendances', [
            'student_id' => $student->id,
            'school_date' => $occurredAt->toDateString().' 00:00:00',
            'status' => 'hadir',
        ]);
    }

    public function test_import_mapping_rejects_pin_used_by_another_student(): void
    {
        $device = FingerprintDevice::create([
            'name' => 'Mesin Konflik PIN Import',
            'ip' => '192.168.1.232',
            'port' => 80,
            'comm_key' => '0',
            'active' => true,
        ]);

        $first = Student::create(['nis' => '6001', 'name' => 'Siswa Punya PIN', 'gender' => 'L', 'active' => true]);
        $second = Student::create(['nis' => '6002', 'name' => 'Siswa Lain', 'gender' => 'P', 'active' => true]);

        DeviceUser::create([
            'device_id' => $device->id,
            'student_id' => $first->id,
            'machine_user_id' => '601',
        ]);

        $file = $this->makeXlsxUpload(
            $this->importHeaders(),
            [['6002', '', '', '', '', '', '', '', '601']],
            'mapping_conflict.xlsx'
        );

        $this->actingAs($this->admin)->post(route('students.import'), [
            'file' => $file,
            'device_id' => $device->id,
        ])->assertRedirect(route('students.index'));

        $this->assertDatabaseMissing('device_users', [
            'device_id' => $device->id,
            'student_id' => $second->id,
        ]);

        $results = TaskProgress::where('type', 'students.import')->latest('id')->firstOrFail()->results;
        $this->assertSame(1, $results['skipped']);
        $this->assertStringContainsString('sudah dipakai siswa lain', implode(' ', $results['errors']));
    }

    public function test_import_mapping_rejects_student_already_mapped_to_different_pin(): void
    {
        $device = FingerprintDevice::create([
            'name' => 'Mesin Siswa Sudah Terpetakan',
            'ip' => '192.168.1.235',
            'port' => 80,
            'comm_key' => '0',
            'active' => true,
        ]);

        $student = Student::create(['nis' => '9000', 'name' => 'Siswa Sudah Punya PIN', 'gender' => 'L', 'active' => true]);

        DeviceUser::create([
            'device_id' => $device->id,
            'student_id' => $student->id,
            'machine_user_id' => '901',
        ]);

        $file = $this->makeXlsxUpload(
            $this->importHeaders(),
            [['9000', '', '', '', '', '', '', '', '990']],
            'mapping_already.xlsx'
        );

        $this->actingAs($this->admin)->post(route('students.import'), [
            'file' => $file,
            'device_id' => $device->id,
        ])->assertRedirect(route('students.index'));

        $this->assertDatabaseHas('device_users', [
            'device_id' => $device->id,
            'student_id' => $student->id,
            'machine_user_id' => '901',
        ]);

        $results = TaskProgress::where('type', 'students.import')->latest('id')->firstOrFail()->results;
        $this->assertSame(1, $results['skipped']);
        $this->assertStringContainsString('Lepas pemetaan lama', implode(' ', $results['errors']));
    }

    public function test_import_mapping_is_idempotent_for_same_student_and_pin(): void
    {
        $device = FingerprintDevice::create([
            'name' => 'Mesin Idempoten',
            'ip' => '192.168.1.233',
            'port' => 80,
            'comm_key' => '0',
            'active' => true,
        ]);

        $student = Student::create(['nis' => '7001', 'name' => 'Siswa Idempoten', 'gender' => 'L', 'active' => true]);

        DeviceUser::create([
            'device_id' => $device->id,
            'student_id' => $student->id,
            'machine_user_id' => '701',
        ]);

        $file = $this->makeXlsxUpload(
            $this->importHeaders(),
            [['7001', '', '', '', '', '', '', '', '701']],
            'mapping_idempotent.xlsx'
        );

        $this->actingAs($this->admin)->post(route('students.import'), [
            'file' => $file,
            'device_id' => $device->id,
        ])->assertRedirect(route('students.index'));

        $this->assertDatabaseCount('device_users', 1);

        $results = TaskProgress::where('type', 'students.import')->latest('id')->firstOrFail()->results;
        $this->assertArrayNotHasKey('errors', $results);
        $this->assertSame(1, $results['mapped']);
    }

    public function test_import_mapping_writes_audit_log(): void
    {
        $device = FingerprintDevice::create([
            'name' => 'Mesin Audit Mapping',
            'ip' => '192.168.1.234',
            'port' => 80,
            'comm_key' => '0',
            'active' => true,
        ]);

        Student::create(['nis' => '8001', 'name' => 'Siswa Audit', 'gender' => 'L', 'active' => true]);

        $file = $this->makeXlsxUpload(
            $this->importHeaders(),
            [['8001', '', '', '', '', '', '', '', '801']],
            'mapping_audit.xlsx'
        );

        $this->actingAs($this->admin)->post(route('students.import'), [
            'file' => $file,
            'device_id' => $device->id,
        ])->assertRedirect(route('students.index'));

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'create_device_mapping',
            'entity_type' => 'DeviceUser',
        ]);
    }

    public function test_import_updates_student_matched_by_machine_id_when_nis_differs(): void
    {
        $device = FingerprintDevice::create([
            'name' => 'Mesin Fallback NIS',
            'ip' => '192.168.1.241',
            'port' => 80,
            'comm_key' => '0',
            'active' => true,
        ]);

        $student = Student::create(['nis' => '10101', 'name' => 'Nama Lama', 'gender' => 'L', 'active' => true]);

        DeviceUser::create([
            'device_id' => $device->id,
            'student_id' => $student->id,
            'machine_user_id' => '12701',
        ]);

        $file = $this->makeXlsxUpload(
            $this->importHeaders(),
            [['999999999999', '', 'Nama Baru', 'P', 'X-11', 'Wali', 'Ayah', '081234567890', '12701']],
            'fallback_nis.xlsx'
        );

        $this->actingAs($this->admin)->post(route('students.import'), [
            'file' => $file,
            'device_id' => $device->id,
        ])->assertRedirect(route('students.index'));

        $this->assertDatabaseCount('students', 1);

        $fresh = $student->fresh();
        $this->assertSame('999999999999', $fresh->nis);
        $this->assertSame('Nama Baru', $fresh->name);
        $this->assertSame('P', $fresh->gender);
        $this->assertDatabaseHas('device_users', [
            'device_id' => $device->id,
            'student_id' => $student->id,
            'machine_user_id' => '12701',
        ]);

        $results = TaskProgress::where('type', 'students.import')->latest('id')->firstOrFail()->results;
        $this->assertSame(1, $results['updated']);
        $this->assertSame(0, $results['created']);
    }

    public function test_import_machine_match_without_nis_updates_without_creating_duplicate(): void
    {
        $device = FingerprintDevice::create([
            'name' => 'Mesin Fallback Tanpa NIS',
            'ip' => '192.168.1.243',
            'port' => 80,
            'comm_key' => '0',
            'active' => true,
        ]);

        $student = Student::create(['nis' => null, 'name' => 'Nama Lama Tanpa NIS', 'gender' => 'L', 'active' => true]);

        DeviceUser::create([
            'device_id' => $device->id,
            'student_id' => $student->id,
            'machine_user_id' => '12703',
        ]);

        $file = $this->makeXlsxUpload(
            $this->importHeaders(),
            [['', '', 'Nama Diperbarui', 'L', 'X-13', 'Wali', 'Ayah', '081234567890', '12703']],
            'fallback_no_nis.xlsx'
        );

        $this->actingAs($this->admin)->post(route('students.import'), [
            'file' => $file,
            'device_id' => $device->id,
        ])->assertRedirect(route('students.index'));

        $this->assertDatabaseCount('students', 1);
        $this->assertSame('Nama Diperbarui', $student->fresh()->name);
        $this->assertNull($student->fresh()->nis);
    }

    public function test_import_machine_match_keeps_nis_when_file_nis_held_by_trashed_student(): void
    {
        $device = FingerprintDevice::create([
            'name' => 'Mesin NIS Sampah',
            'ip' => '192.168.1.242',
            'port' => 80,
            'comm_key' => '0',
            'active' => true,
        ]);

        $target = Student::create(['nis' => '10102', 'name' => 'Target', 'gender' => 'L', 'active' => true]);

        DeviceUser::create([
            'device_id' => $device->id,
            'student_id' => $target->id,
            'machine_user_id' => '12702',
        ]);

        $trashed = Student::create(['nis' => '888888888888', 'name' => 'Siswa Sampah', 'gender' => 'P', 'active' => true]);
        $trashed->delete();

        $file = $this->makeXlsxUpload(
            $this->importHeaders(),
            [['888888888888', '', 'Nama Target Baru', 'L', 'X-12', 'Wali', 'Ayah', '081234567890', '12702']],
            'fallback_conflict.xlsx'
        );

        $this->actingAs($this->admin)->post(route('students.import'), [
            'file' => $file,
            'device_id' => $device->id,
        ])->assertRedirect(route('students.index'));

        $this->assertSame('10102', $target->fresh()->nis);
        $this->assertSame('Nama Target Baru', $target->fresh()->name);

        $results = TaskProgress::where('type', 'students.import')->latest('id')->firstOrFail()->results;
        $this->assertStringContainsString('sudah dipakai siswa lain', implode(' ', $results['errors']));
    }

    public function test_import_full_row_without_nis_and_without_mapping_is_skipped(): void
    {
        $file = $this->makeXlsxUpload(
            $this->importHeaders(),
            [['', '', 'Siswa Tanpa NIS', 'L', 'X-14', 'Wali', 'Ayah', '081234567890', '']],
            'no_nis_no_mapping.xlsx'
        );

        $this->actingAs($this->admin)->post(route('students.import'), [
            'file' => $file,
        ])->assertRedirect(route('students.index'));

        $this->assertDatabaseCount('students', 0);
        $this->assertDatabaseCount('classes', 0);

        $results = TaskProgress::where('type', 'students.import')->latest('id')->firstOrFail()->results;
        $this->assertSame(1, $results['skipped']);
        $this->assertStringContainsString('NIS wajib diisi', implode(' ', $results['errors']));
    }

    public function test_import_rejects_non_xlsx_file(): void
    {
        $fakeFile = UploadedFile::fake()->create('document.csv', 100, 'text/csv');

        $response = $this->actingAs($this->admin)->post(route('students.import'), [
            'file' => $fakeFile,
        ]);

        $response->assertSessionHasErrors('file');
    }

    public function test_import_job_logs_warning_when_temp_file_deletion_fails(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'test_cleanup_').'.xlsx';
        file_put_contents($path, 'dummy');

        File::shouldReceive('delete')->once()->with($path)->andReturn(false);
        Log::spy();

        $job = new ImportStudentsFromExcel($path, 'import.xlsx', null, null, null, 'uuid-cleanup-test');

        $cleanup = new \ReflectionMethod($job, 'cleanupUploadedFile');
        $cleanup->setAccessible(true);
        $cleanup->invoke($job);

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message) => str_contains($message, 'Gagal menghapus berkas sementara'));

        // Kegagalan hapus tidak boleh membungkam galat: berkas tetap ada dan dicatat.
        $this->assertFileExists($path);

        @unlink($path);
    }

    /**
     * @return array<int, string>
     */
    private function importHeaders(): array
    {
        return [
            'NIS',
            'NISN',
            'Nama Siswa',
            'Jenis Kelamin (L/P)',
            'Kelas',
            'Nama Wali',
            'Hubungan',
            'No WhatsApp Wali',
            'PIN Mesin (Opsional)',
            'Persetujuan WA (Ya/Tidak)',
        ];
    }

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, string>>  $rows
     */
    private function makeXlsxUpload(array $headers, array $rows, string $filename): UploadedFile
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'test_rename_').'.xlsx';
        $download = SimpleXlsxExporter::download('dummy.xlsx', $headers, $rows);
        copy($download->getFile()->getPathname(), $tempPath);

        return new UploadedFile(
            $tempPath,
            $filename,
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true
        );
    }
}
