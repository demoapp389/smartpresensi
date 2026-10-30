<?php

namespace Tests\Feature;

use App\Models\FingerprintDevice;
use App\Models\SchoolSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SidebarNavigationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        FingerprintDevice::create([
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

    public function test_admin_sees_full_sidebar_structure(): void
    {
        $admin = User::factory()->admin()->create(['name' => 'Admin Sekolah']);
        $response = $this->actingAs($admin)->get(route('dashboard'));

        $response->assertStatus(200);

        // Kategori utama
        foreach (['Dashboard', 'Presensi', 'Master Data', 'Perangkat &amp; Integrasi', 'Administrasi Sistem'] as $category) {
            $response->assertSee($category, false);
        }

        // Submenu Mesin Fingerprint
        $response->assertSee('Daftar Mesin', false);
        $response->assertSee('Pemetaan PIN Siswa', false);
        $response->assertSee('Riwayat Sinkronisasi', false);

        // Submenu WhatsApp
        $response->assertSee('Outbox Pesan', false);
        $response->assertSee('Validasi Nomor Wali', false);
        $response->assertSee('Pengaturan Gateway', false);

        // Menu lain
        foreach (['Riwayat Presensi', 'Laporan Presensi', 'Riwayat Scan', 'Perlu Peninjauan', 'Siswa &amp; Wali', 'Kelas', 'Jadwal Absensi', 'Manajemen Pengguna', 'Log Audit', 'Pusat Kontrol Sistem', 'Dokumentasi'] as $label) {
            $response->assertSee($label, false);
        }
    }

    public function test_operator_sees_only_permitted_sidebar_items(): void
    {
        $operator = User::factory()->operator()->create(['name' => 'Operator Absensi']);
        $response = $this->actingAs($operator)->get(route('dashboard'));

        $response->assertStatus(200);

        // Yang boleh diakses operator
        $response->assertSee('Riwayat Presensi', false);
        $response->assertSee('Laporan Presensi', false);
        $response->assertSee('Riwayat Scan', false);
        $response->assertSee('Siswa &amp; Wali', false);
        $response->assertSee('Kelas', false);
        $response->assertSee('Outbox Pesan', false);
        $response->assertSee('Validasi Nomor Wali', false);
        $response->assertSee('Koreksi Operator', false);

        // Yang tidak boleh diakses operator
        $response->assertDontSee('Jadwal Absensi', false);
        $response->assertDontSee('Daftar Mesin', false);
        $response->assertDontSee('Pemetaan PIN Siswa', false);
        $response->assertDontSee('Riwayat Sinkronisasi', false);
        $response->assertDontSee('Pengaturan Gateway', false);
        $response->assertDontSee('Manajemen Pengguna', false);
        $response->assertDontSee('Log Audit', false);
        $response->assertDontSee('Pusat Kontrol Sistem', false);
        $response->assertDontSee('Dokumentasi', false);
    }

    public function test_homeroom_teacher_sees_only_attendance_items(): void
    {
        $wali = User::factory()->waliKelas()->create(['name' => 'Wali Kelas']);
        $response = $this->actingAs($wali)->get(route('dashboard'));

        $response->assertStatus(200);

        $response->assertSee('Dashboard', false);
        $response->assertSee('Riwayat Presensi', false);
        $response->assertSee('Laporan Presensi', false);
        $response->assertSee('Presensi Manual', false);

        $response->assertDontSee('Riwayat Scan', false);
        $response->assertDontSee('Perlu Peninjauan', false);
        $response->assertDontSee('Siswa &amp; Wali', false);
        $response->assertDontSee('Daftar Mesin', false);
        $response->assertDontSee('Manajemen Pengguna', false);
        $response->assertDontSee('Dokumentasi', false);
    }

    public function test_sidebar_marks_current_route_group_as_active(): void
    {
        $admin = User::factory()->admin()->create();
        $response = $this->actingAs($admin)->get(route('devices.mappings'));

        $response->assertStatus(200);
        // Grup Mesin Fingerprint harus ter-highlight karena anak aktif.
        $response->assertSee('aria-current="page"', false);
    }
}
