<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\DeviceUser;
use App\Models\FingerprintDevice;
use App\Models\SchoolHoliday;
use App\Models\SchoolSchedule;
use App\Models\Student;
use App\Models\User;
use App\Services\AttendanceProcessingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchoolHolidayManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create();
    }

    public function test_admin_can_view_schedules_and_holidays_page(): void
    {
        SchoolHoliday::create([
            'name' => 'Hari Libur Nasional 1',
            'start_date' => '2026-08-17',
            'end_date' => '2026-08-17',
            'description' => 'HUT RI ke-81',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin)->get(route('schedules.index', ['tab' => 'holidays']));

        $response->assertStatus(200);
        $response->assertSee('Kalender Hari Libur Khusus');
        $response->assertSee('Hari Libur Nasional 1');
        $response->assertSee('17/08/2026');
    }

    public function test_admin_can_create_school_holiday(): void
    {
        $payload = [
            'name' => 'Libur Semester Ganjil',
            'start_date' => '2026-12-20',
            'end_date' => '2026-12-31',
            'description' => 'Libur akhir semester',
            'is_active' => 1,
        ];

        $response = $this->actingAs($this->admin)->post(route('schedules.holidays.store'), $payload);

        $response->assertRedirect(route('schedules.index', ['tab' => 'holidays']));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('school_holidays', [
            'name' => 'Libur Semester Ganjil',
            'is_active' => 1,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'create_holiday',
            'entity_type' => 'SchoolHoliday',
        ]);
    }

    public function test_holiday_validation_fails_if_end_date_is_before_start_date(): void
    {
        $payload = [
            'name' => 'Invalid Holiday',
            'start_date' => '2026-12-31',
            'end_date' => '2026-12-20', // Invalid: end_date < start_date
            'is_active' => 1,
        ];

        $response = $this->actingAs($this->admin)->post(route('schedules.holidays.store'), $payload);

        $response->assertSessionHasErrors(['end_date']);
        $this->assertDatabaseMissing('school_holidays', ['name' => 'Invalid Holiday']);
    }

    public function test_admin_can_update_school_holiday(): void
    {
        $holiday = SchoolHoliday::create([
            'name' => 'Libur Awal',
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-01',
            'description' => 'Hari Buruh',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin)->put(route('schedules.holidays.update', $holiday), [
            'name' => 'Hari Buruh Internasional',
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-02',
            'description' => 'Cuti bersama buruh',
            'is_active' => 1,
        ]);

        $response->assertRedirect(route('schedules.index', ['tab' => 'holidays']));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('school_holidays', [
            'id' => $holiday->id,
            'name' => 'Hari Buruh Internasional',
            'is_active' => 1,
        ]);
    }

    public function test_admin_can_delete_school_holiday(): void
    {
        $holiday = SchoolHoliday::create([
            'name' => 'Libur Dibatalkan',
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-01',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin)->delete(route('schedules.holidays.destroy', $holiday));

        $response->assertRedirect(route('schedules.index', ['tab' => 'holidays']));
        $this->assertDatabaseMissing('school_holidays', ['id' => $holiday->id]);
    }

    public function test_attendance_processing_does_not_mark_as_late_on_holiday(): void
    {
        // 1. Buat jadwal hari Senin: masuk 07:00, late_after 07:15
        SchoolSchedule::create([
            'day_of_week' => 1, // Senin
            'day_name' => 'Senin',
            'day_pattern' => 'regular',
            'check_in_start' => '06:00:00',
            'start_at' => '07:00:00',
            'late_after' => '07:15:00',
            'check_out_start' => '13:00:00',
            'end_at' => '17:00:00',
            'effective_date' => '2026-01-01',
            'is_active' => true,
        ]);

        $device = FingerprintDevice::create([
            'name' => 'Mesin Utama',
            'ip' => '192.168.1.201',
            'port' => 4370,
            'comm_key' => '0',
            'timezone' => 'Asia/Jakarta',
            'status' => 'active',
            'secret_token' => 'tok1',
            'active' => true,
        ]);

        $student = Student::create(['nis' => '9001', 'name' => 'Sudirman', 'gender' => 'L', 'active' => true]);
        DeviceUser::create(['device_id' => $device->id, 'student_id' => $student->id, 'machine_user_id' => '901']);

        // Tanggal 17 Agustus 2026 adalah hari Senin
        $holidayDate = '2026-08-17';

        // 2. Daftarkan hari libur pada tanggal tersebut
        SchoolHoliday::create([
            'name' => 'HUT Kemerdekaan RI',
            'start_date' => $holidayDate,
            'end_date' => $holidayDate,
            'is_active' => true,
        ]);

        // 3. Siswa scan pada jam 08:30 (melewati late_after 07:15)
        $log = AttendanceLog::create([
            'device_id' => $device->id,
            'machine_user_id' => '901',
            'occurred_at' => "{$holidayDate} 08:30:00",
            'received_at' => "{$holidayDate} 08:30:05",
            'source_key' => 'LOG_HOLIDAY_001',
            'status' => 'pending',
            'raw_payload' => ['pin' => '901'],
        ]);

        $service = app(AttendanceProcessingService::class);
        $attendance = $service->processLog($log);

        $this->assertNotNull($attendance);
        // Scan masuk saja tetap hadir; hari libur tidak diklasifikasikan terlambat.
        $this->assertSame('hadir', $attendance->status);
        $this->assertSame('tepat_waktu', $attendance->check_in_status);
        $this->assertSame(0, $attendance->late_minutes);
    }
}
