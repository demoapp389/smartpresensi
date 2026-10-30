<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\DeviceUser;
use App\Models\FingerprintDevice;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentNisEditTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create();
    }

    public function test_updating_nis_does_not_change_device_mapping(): void
    {
        $class = SchoolClass::create(['name' => 'X-RPL NIS', 'academic_year' => '2026/2027']);
        $device = FingerprintDevice::create([
            'name' => 'Mesin NIS',
            'ip' => '192.168.1.214',
            'port' => 80,
            'comm_key' => '0',
            'active' => true,
        ]);

        $student = Student::create([
            'nis' => 'NIS-LAMA',
            'name' => 'Siswa Ganti NIS',
            'gender' => 'L',
            'class_id' => $class->id,
            'active' => true,
        ]);

        $student->guardians()->create([
            'name' => 'Wali Siswa',
            'relationship' => 'Ayah',
            'wa_number' => '628111111111',
            'active' => true,
        ]);

        $mapping = DeviceUser::create([
            'device_id' => $device->id,
            'machine_user_id' => 'PIN-101',
            'student_id' => $student->id,
        ]);

        $response = $this->actingAs($this->admin)->put(route('students.update', $student), [
            'nis' => 'NIS-BARU',
            'nisn' => '0012345678',
            'name' => 'Siswa Ganti NIS',
            'gender' => 'L',
            'class_id' => $class->id,
            'guardian_name' => 'Wali Siswa',
            'relationship' => 'Ayah',
            'wa_number' => '628111111111',
            'device_id' => $device->id,
            'machine_user_id' => 'PIN-101',
        ]);

        $response->assertRedirect(route('students.index'));
        $response->assertSessionHasNoErrors();

        $this->assertSame('NIS-BARU', $student->fresh()->nis);
        $this->assertDatabaseHas('device_users', [
            'id' => $mapping->id,
            'device_id' => $device->id,
            'machine_user_id' => 'PIN-101',
            'student_id' => $student->id,
        ]);
    }

    public function test_updating_mapping_reprocesses_unmapped_logs_after_commit(): void
    {
        $class = SchoolClass::create(['name' => 'X-REPLAY NIS', 'academic_year' => '2026/2027']);
        $device = FingerprintDevice::create([
            'name' => 'Mesin Replay',
            'ip' => '192.168.1.221',
            'port' => 80,
            'comm_key' => '0',
            'active' => true,
        ]);
        $student = Student::create([
            'nis' => 'NIS-REPLAY',
            'name' => 'Siswa Replay',
            'gender' => 'L',
            'class_id' => $class->id,
            'active' => true,
        ]);

        $occurredAt = now()->subDay()->setTime(7, 5);
        $log = AttendanceLog::create([
            'device_id' => $device->id,
            'machine_user_id' => 'PIN-REPLAY',
            'occurred_at' => $occurredAt,
            'received_at' => $occurredAt->copy()->addMinute(),
            'source_key' => 'x601_PIN-REPLAY_3001',
            'status' => AttendanceLog::STATUS_UNMAPPED,
        ]);

        $response = $this->actingAs($this->admin)->put(route('students.update', $student), [
            'nis' => 'NIS-REPLAY',
            'nisn' => '0099999999',
            'name' => 'Siswa Replay',
            'gender' => 'L',
            'class_id' => $class->id,
            'guardian_name' => 'Wali Replay',
            'relationship' => 'Ayah',
            'wa_number' => '628111111112',
            'device_id' => $device->id,
            'machine_user_id' => 'PIN-REPLAY',
        ]);

        $response->assertRedirect(route('students.index'));
        $response->assertSessionHasNoErrors();
        $this->assertSame(AttendanceLog::STATUS_PROCESSED, $log->fresh()->status);
        $this->assertDatabaseHas('attendances', [
            'student_id' => $student->id,
            'school_date' => $occurredAt->toDateString().' 00:00:00',
            'status' => 'hadir',
        ]);
    }

    public function test_updating_mapping_rejects_machine_id_used_by_another_student(): void
    {
        $class = SchoolClass::create(['name' => 'X-MM NIS', 'academic_year' => '2026/2027']);
        $device = FingerprintDevice::create([
            'name' => 'Mesin Mapping',
            'ip' => '192.168.1.219',
            'port' => 80,
            'comm_key' => '0',
            'active' => true,
        ]);

        $student = Student::create([
            'nis' => 'NIS-301',
            'name' => 'Siswa Pertama',
            'gender' => 'L',
            'class_id' => $class->id,
            'active' => true,
        ]);
        $student->guardians()->create([
            'name' => 'Wali Pertama',
            'relationship' => 'Ayah',
            'active' => true,
        ]);

        DeviceUser::create([
            'device_id' => $device->id,
            'machine_user_id' => 'PIN-301',
            'student_id' => $student->id,
        ]);

        $other = Student::create([
            'nis' => 'NIS-302',
            'name' => 'Siswa Kedua',
            'gender' => 'P',
            'class_id' => $class->id,
            'active' => true,
        ]);
        $other->guardians()->create([
            'name' => 'Wali Kedua',
            'relationship' => 'Ibu',
            'active' => true,
        ]);

        DeviceUser::create([
            'device_id' => $device->id,
            'machine_user_id' => 'PIN-302',
            'student_id' => $other->id,
        ]);

        $response = $this->actingAs($this->admin)->put(route('students.update', $student), [
            'nis' => 'NIS-301',
            'name' => 'Siswa Pertama',
            'gender' => 'L',
            'class_id' => $class->id,
            'guardian_name' => 'Wali Pertama',
            'relationship' => 'Ayah',
            'wa_number' => '',
            'device_id' => $device->id,
            'machine_user_id' => 'PIN-302',
        ]);

        $response->assertSessionHasErrors('machine_user_id');

        $this->assertDatabaseHas('device_users', [
            'device_id' => $device->id,
            'student_id' => $student->id,
            'machine_user_id' => 'PIN-301',
        ]);
        $this->assertDatabaseHas('device_users', [
            'device_id' => $device->id,
            'student_id' => $other->id,
            'machine_user_id' => 'PIN-302',
        ]);
    }

    public function test_store_rejects_machine_id_used_by_another_student(): void
    {
        $class = SchoolClass::create(['name' => 'X-MM Store', 'academic_year' => '2026/2027']);
        $device = FingerprintDevice::create([
            'name' => 'Mesin Store',
            'ip' => '192.168.1.220',
            'port' => 80,
            'comm_key' => '0',
            'active' => true,
        ]);

        $existing = Student::create([
            'nis' => 'NIS-401',
            'name' => 'Siswa Lama',
            'gender' => 'L',
            'class_id' => $class->id,
            'active' => true,
        ]);

        DeviceUser::create([
            'device_id' => $device->id,
            'machine_user_id' => 'PIN-401',
            'student_id' => $existing->id,
        ]);

        $response = $this->actingAs($this->admin)->post(route('students.store'), [
            'nis' => 'NIS-402',
            'name' => 'Siswa Baru',
            'gender' => 'P',
            'class_id' => $class->id,
            'guardian_name' => 'Wali Baru',
            'relationship' => 'Ibu',
            'wa_number' => '',
            'device_id' => $device->id,
            'machine_user_id' => 'PIN-401',
        ]);

        $response->assertSessionHasErrors('machine_user_id');
        $this->assertDatabaseMissing('students', ['nis' => 'NIS-402']);
    }

    public function test_updating_nis_rejects_number_used_by_another_student(): void
    {
        $class = SchoolClass::create(['name' => 'X-TKJ NIS', 'academic_year' => '2026/2027']);
        $student = Student::create([
            'nis' => 'NIS-201',
            'name' => 'Siswa Pertama',
            'gender' => 'L',
            'class_id' => $class->id,
            'active' => true,
        ]);
        $student->guardians()->create([
            'name' => 'Wali Pertama',
            'relationship' => 'Ayah',
            'active' => true,
        ]);

        Student::create([
            'nis' => 'NIS-202',
            'name' => 'Siswa Kedua',
            'gender' => 'P',
            'active' => true,
        ]);

        $response = $this->actingAs($this->admin)->put(route('students.update', $student), [
            'nis' => 'NIS-202',
            'name' => 'Siswa Pertama',
            'gender' => 'L',
            'class_id' => $class->id,
            'guardian_name' => 'Wali Pertama',
            'relationship' => 'Ayah',
            'wa_number' => '',
        ]);

        $response->assertSessionHasErrors('nis');
        $this->assertSame('NIS-201', $student->fresh()->nis);
    }
}
