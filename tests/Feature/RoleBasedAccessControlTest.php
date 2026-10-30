<?php

namespace Tests\Feature;

use App\Models\FingerprintDevice;
use App\Models\SchoolSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleBasedAccessControlTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $operator;

    protected FingerprintDevice $device;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create([
            'email' => 'admin@sekolah.sch.id',
            'name' => 'Administrator Sekolah',
        ]);

        $this->operator = User::factory()->operator()->create([
            'email' => 'operator@sekolah.sch.id',
            'name' => 'Operator Absensi',
        ]);

        $this->device = FingerprintDevice::create([
            'name' => 'Solution X601 Utama',
            'ip' => '192.168.1.201',
            'port' => 4370,
            'comm_key' => '0',
            'timezone' => 'Asia/Jakarta',
            'status' => 'active',
            'secret_token' => 'token_test_123',
            'active' => true,
        ]);

        SchoolSchedule::create([
            'day_of_week' => 1,
            'day_name' => 'Senin',
            'check_in_start' => '06:00:00',
            'start_at' => '07:00:00',
            'late_after' => '07:15:00',
            'check_out_start' => '14:00:00',
            'end_at' => '16:00:00',
            'day_pattern' => 'regular',
            'is_active' => true,
        ]);
    }

    public function test_admin_can_access_all_management_modules(): void
    {
        $this->actingAs($this->admin);

        $this->get(route('dashboard'))->assertStatus(200);
        $this->get(route('attendances.index'))->assertStatus(200);
        $this->get(route('students.index'))->assertStatus(200);
        $this->get(route('classes.index'))->assertStatus(200);
        $this->get(route('logs.raw'))->assertStatus(200);
        $this->get(route('logs.unmapped'))->assertStatus(200);
        $this->get(route('notifications.index'))->assertStatus(200);

        // Khusus Admin
        $this->get(route('devices.index'))->assertStatus(200);
        $this->get(route('schedules.index'))->assertStatus(200);
        $this->get(route('audit.index'))->assertStatus(200);
        $this->get(route('system.control'))->assertStatus(200);
    }

    public function test_operator_can_access_operational_modules(): void
    {
        $this->actingAs($this->operator);

        $this->get(route('dashboard'))->assertStatus(200);
        $this->get(route('attendances.index'))->assertStatus(200);
        $this->get(route('attendances.correction'))->assertStatus(200);
        $this->get(route('students.index'))->assertStatus(200);
        $this->get(route('classes.index'))->assertStatus(200);
        $this->get(route('logs.raw'))->assertStatus(200);
        $this->get(route('logs.unmapped'))->assertStatus(200);
        $this->get(route('notifications.index'))->assertStatus(200);
    }

    public function test_operator_is_forbidden_from_admin_modules(): void
    {
        $this->actingAs($this->operator);

        // Perangkat Fingerprint & Mapping PIN
        $this->get(route('devices.index'))->assertStatus(403);
        $this->post(route('devices.store'), [
            'name' => 'Mesin Ilegal',
            'ip' => '192.168.1.202',
            'port' => 4370,
        ])->assertStatus(403);

        // Jadwal & Hari Libur
        $this->get(route('schedules.index'))->assertStatus(403);

        // Log Audit Sistem
        $this->get(route('audit.index'))->assertStatus(403);

        // Pusat Kontrol Sistem & Background Services
        $this->get(route('system.control'))->assertStatus(403);
        $this->post(route('system.restart-all'))->assertStatus(403);

        // Backup Database
        $this->get(route('system.backup.database'))->assertStatus(403);
    }

    public function test_sidebar_hides_admin_links_for_operator(): void
    {
        $responseAdmin = $this->actingAs($this->admin)->get(route('dashboard'));
        $responseAdmin->assertSee('Daftar Mesin', false);
        $responseAdmin->assertSee('Jadwal Absensi', false);
        $responseAdmin->assertSee('Log Audit', false);
        $responseAdmin->assertSee('Pusat Kontrol Sistem', false);

        $responseOperator = $this->actingAs($this->operator)->get(route('dashboard'));
        $responseOperator->assertDontSee('Daftar Mesin', false);
        $responseOperator->assertDontSee('Jadwal Absensi', false);
        $responseOperator->assertDontSee('Log Audit', false);
        $responseOperator->assertDontSee('Pusat Kontrol Sistem', false);

        // Namun operator tetap melihat menu operasionalnya
        $responseOperator->assertSee('Koreksi Operator', false);
        $responseOperator->assertSee('Siswa &amp; Wali', false);
        $responseOperator->assertSee('Outbox Pesan', false);
    }
}
