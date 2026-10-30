<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AttendanceLog;
use App\Models\DeviceUser;
use App\Models\FingerprintDevice;
use App\Models\Guardian;
use App\Models\NotificationLog;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentBiometricTemplate;
use App\Models\User;
use App\Services\SoapFingerprintService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentBulkDeleteTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private SchoolClass $class;

    private FingerprintDevice $device;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();

        $this->class = SchoolClass::create([
            'name' => 'X-RPL 1',
            'academic_year' => '2026/2027',
        ]);

        $this->device = FingerprintDevice::create([
            'name' => 'Solution X601 Utama',
            'ip' => '192.168.1.201',
            'port' => 4370,
            'comm_key' => '0',
            'active' => true,
        ]);
    }

    private function createStudentWithRelations(string $name, string $nis): Student
    {
        $student = Student::create([
            'nis' => $nis,
            'name' => $name,
            'gender' => 'L',
            'class_id' => $this->class->id,
            'active' => true,
        ]);

        $guardian = Guardian::create([
            'student_id' => $student->id,
            'name' => 'Wali '.$name,
            'relationship' => 'Ayah',
            'wa_number' => '628111111111',
            'active' => true,
        ]);

        DeviceUser::create([
            'device_id' => $this->device->id,
            'machine_user_id' => $nis,
            'student_id' => $student->id,
        ]);

        $attendance = Attendance::create([
            'student_id' => $student->id,
            'school_date' => now()->toDateString(),
            'status' => 'hadir',
        ]);

        NotificationLog::create([
            'attendance_id' => $attendance->id,
            'guardian_id' => $guardian->id,
            'event_type' => 'check_in',
            'idempotency_key' => 'key-'.$student->id,
            'template_name' => 'check_in',
            'recipient_phone' => '628111111111',
            'status' => 'queued',
        ]);

        StudentBiometricTemplate::create([
            'student_id' => $student->id,
            'finger_index' => 0,
            'template_data' => 'dummy-template',
            'template_size' => 14,
            'source_device_id' => $this->device->id,
        ]);

        return $student;
    }

    public function test_admin_can_bulk_delete_students_with_soft_delete_and_relations_preserved(): void
    {
        $first = $this->createStudentWithRelations('Siswa Satu', '1001');
        $second = $this->createStudentWithRelations('Siswa Dua', '1002');
        $untouched = $this->createStudentWithRelations('Siswa Tiga', '1003');

        // Raw log tidak memiliki FK ke student — harus tetap utuh (immutable).
        AttendanceLog::create([
            'device_id' => $this->device->id,
            'machine_user_id' => '1001',
            'occurred_at' => now(),
            'received_at' => now(),
            'source_key' => 'raw-1001',
            'status' => 'processed',
        ]);

        $response = $this->actingAs($this->admin)->delete(route('students.bulk-destroy'), [
            'ids' => [$first->id, $second->id],
        ]);

        $response->assertRedirect(route('students.index'));
        $response->assertSessionHas('success');

        // Siswa terpilih masuk ke sampah (soft deleted), sisanya tetap aktif.
        $this->assertSoftDeleted('students', ['id' => $first->id]);
        $this->assertSoftDeleted('students', ['id' => $second->id]);
        $this->assertDatabaseHas('students', ['id' => $untouched->id, 'deleted_at' => null]);

        // Relasi dan data aman tersimpan agar bisa di-restore
        $this->assertDatabaseHas('guardians', ['student_id' => $first->id]);
        $this->assertDatabaseHas('device_users', ['student_id' => $first->id]);
        $this->assertDatabaseHas('attendances', ['student_id' => $first->id]);
        $this->assertDatabaseHas('student_biometric_templates', ['student_id' => $first->id]);

        // Raw log audit tetap ada (tidak terhapus).
        $this->assertDatabaseHas('attendance_logs', ['source_key' => 'raw-1001']);

        // Audit log mencatat aksi bulk.
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'delete_students_bulk',
            'entity_type' => 'Student',
        ]);
    }

    public function test_soft_deleted_students_can_be_restored(): void
    {
        $student = $this->createStudentWithRelations('Siswa Pulih', '1009');

        // Soft delete dulu
        $this->actingAs($this->admin)->delete(route('students.destroy', $student));
        $this->assertSoftDeleted('students', ['id' => $student->id]);

        // Restore
        $restoreResponse = $this->actingAs($this->admin)->post(route('students.restore', $student->id));
        $restoreResponse->assertRedirect();
        $this->assertDatabaseHas('students', ['id' => $student->id, 'deleted_at' => null]);

        // Test bulk restore
        $this->actingAs($this->admin)->delete(route('students.destroy', $student));
        $this->assertSoftDeleted('students', ['id' => $student->id]);

        $bulkRestoreResponse = $this->actingAs($this->admin)->post(route('students.bulk-restore'), [
            'ids' => [$student->id],
        ]);
        $bulkRestoreResponse->assertRedirect();
        $this->assertDatabaseHas('students', ['id' => $student->id, 'deleted_at' => null]);
    }

    public function test_students_can_be_permanently_deleted(): void
    {
        $student = $this->createStudentWithRelations('Siswa Force', '2001');

        // Soft delete dulu
        $this->actingAs($this->admin)->delete(route('students.destroy', $student));

        // Hapus permanen
        $this->actingAs($this->admin)->delete(route('students.force-delete', $student->id));

        $this->assertDatabaseMissing('students', ['id' => $student->id]);
        $this->assertDatabaseMissing('guardians', ['student_id' => $student->id]);
        $this->assertDatabaseMissing('device_users', ['student_id' => $student->id]);
        $this->assertDatabaseMissing('attendances', ['student_id' => $student->id]);
        $this->assertDatabaseMissing('notification_logs', ['idempotency_key' => 'key-'.$student->id]);
    }

    public function test_bulk_delete_requires_valid_student_ids(): void
    {
        $response = $this->actingAs($this->admin)->delete(route('students.bulk-destroy'), [
            'ids' => [99999],
        ]);

        $response->assertSessionHasErrors('ids.0');
    }

    public function test_bulk_delete_rejects_empty_selection(): void
    {
        $response = $this->actingAs($this->admin)->delete(route('students.bulk-destroy'), [
            'ids' => [],
        ]);

        $response->assertSessionHasErrors('ids');
    }

    public function test_homeroom_teacher_cannot_bulk_delete_students(): void
    {
        $student = $this->createStudentWithRelations('Siswa Wali', '3001');
        $waliKelas = User::factory()->waliKelas($this->class)->create();

        $response = $this->actingAs($waliKelas)->delete(route('students.bulk-destroy'), [
            'ids' => [$student->id],
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseHas('students', ['id' => $student->id, 'deleted_at' => null]);
    }

    public function test_operator_can_bulk_delete_students(): void
    {
        $student = $this->createStudentWithRelations('Siswa Operator', '4001');
        $operator = User::factory()->operator()->create();

        $response = $this->actingAs($operator)->delete(route('students.bulk-destroy'), [
            'ids' => [$student->id],
        ]);

        $response->assertRedirect(route('students.index'));
        $this->assertSoftDeleted('students', ['id' => $student->id]);
    }

    /**
     * Penghapusan data di aplikasi tidak boleh pernah menyentuh mesin fingerprint.
     * Membersihkan PIN mesin hanya dilakukan lewat halaman Perangkat.
     */
    public function test_deleting_students_never_calls_the_fingerprint_machine(): void
    {
        $soap = $this->createMock(SoapFingerprintService::class);
        $soap->expects($this->never())->method('deleteUser');
        $soap->expects($this->never())->method('sendSoapRequest');
        $this->app->instance(SoapFingerprintService::class, $soap);

        $student = $this->createStudentWithRelations('Siswa Tidak Sentuh Mesin', '5001');

        $this->actingAs($this->admin)->delete(route('students.destroy', $student));
        $this->assertSoftDeleted('students', ['id' => $student->id]);

        $this->actingAs($this->admin)->post(route('students.bulk-restore'), ['ids' => [$student->id]]);

        $this->actingAs($this->admin)->delete(route('students.bulk-destroy'), ['ids' => [$student->id]]);
        $this->assertSoftDeleted('students', ['id' => $student->id]);

        // Penghapusan permanen juga tidak boleh menyentuh mesin.
        $this->actingAs($this->admin)->delete(route('students.force-delete', $student->id));
        $this->assertDatabaseMissing('students', ['id' => $student->id]);
    }

    public function test_bulk_force_delete_never_calls_the_fingerprint_machine(): void
    {
        $soap = $this->createMock(SoapFingerprintService::class);
        $soap->expects($this->never())->method('deleteUser');
        $this->app->instance(SoapFingerprintService::class, $soap);

        $first = $this->createStudentWithRelations('Siswa Permanen Satu', '5002');
        $second = $this->createStudentWithRelations('Siswa Permanen Dua', '5003');

        $this->actingAs($this->admin)->delete(route('students.bulk-destroy'), [
            'ids' => [$first->id, $second->id],
        ]);

        $response = $this->actingAs($this->admin)->delete(route('students.bulk-force-delete'), [
            'ids' => [$first->id, $second->id],
        ]);

        $response->assertRedirect();
        $this->assertDatabaseMissing('students', ['id' => $first->id]);
        $this->assertDatabaseMissing('students', ['id' => $second->id]);
    }

    public function test_force_delete_message_reports_remaining_machine_pins(): void
    {
        $student = $this->createStudentWithRelations('Siswa Pinnnya', '5004');

        $this->actingAs($this->admin)->delete(route('students.destroy', $student));
        $response = $this->actingAs($this->admin)->delete(route('students.force-delete', $student->id));

        $response->assertSessionHas('success');
        $this->assertStringContainsString(
            '5004',
            session('success'),
            'PIN mesin yang tertinggal harus disebutkan agar operator tahu perlu dibersihkan manual.'
        );
    }
}
