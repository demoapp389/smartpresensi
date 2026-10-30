<?php

namespace Tests\Feature;

use App\Models\FingerprintDevice;
use App\Models\SchoolSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Validasi nomor WhatsApp wali harus punya satu tempat eksekusi saja
 * (halaman Validasi Nomor Wali). Halaman lain hanya boleh menautkan ke sana,
 * bukan menyediakan aksi validasi sendiri.
 */
class NoDuplicateWaValidationTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();

        FingerprintDevice::create([
            'name' => 'X601 - Gerbang Depan',
            'ip' => '192.168.1.201',
            'port' => 4370,
            'comm_key' => '0',
            'timezone' => 'Asia/Jakarta',
            'status' => 'active',
            'secret_token' => 'token_abc',
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

    public function test_student_page_links_out_instead_of_running_validation(): void
    {
        $response = $this->actingAs($this->admin)->get(route('students.index'));

        $response->assertStatus(200);

        // Tidak ada form yang benar-benar menjalankan validasi...
        $response->assertDontSee('guardians/bulk-validate', false);
        $response->assertDontSee('guardians/1/validate', false);

        // ...tetapi tetap mengarahkan operator ke pusat validasi.
        $response->assertSee(route('guardians.validation'), false);
    }

    public function test_dashboard_links_out_instead_of_running_validation(): void
    {
        $response = $this->actingAs($this->admin)->get(route('dashboard'));

        $response->assertStatus(200);

        $response->assertDontSee('guardians/bulk-validate', false);
        $response->assertSee(route('guardians.validation'), false);
    }

    public function test_validation_page_is_the_single_place_running_validation(): void
    {
        $response = $this->actingAs($this->admin)->get(route('guardians.validation'));

        $response->assertStatus(200);
        $response->assertSee('guardians/bulk-validate', false);
        $response->assertSee('Validasi Pending', false);
        $response->assertSee('Validasi Ulang Invalid', false);
    }
}
