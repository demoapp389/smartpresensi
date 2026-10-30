<?php

namespace Tests\Feature;

use App\Models\Guardian;
use App\Models\NotificationLog;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuthAndDashboardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate');
        $this->artisan('db:seed');
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $response = $this->get('/');
        $response->assertRedirect('/login');
    }

    public function test_login_page_renders_successfully(): void
    {
        $response = $this->get('/login');
        $response->assertStatus(200);
        $response->assertSee('Smart Presensi');
        $response->assertSee('Mesin Fingerprint');
        $response->assertSee('SmartPresensi Biometric Gateway');
        $response->assertDontSee('absensi_siswa');
        $response->assertDontSee('Akun Akses');
        $response->assertDontSee('admin123');
    }

    public function test_admin_can_login_with_valid_credentials(): void
    {
        $response = $this->post('/login', [
            'email' => 'admin@sekolah.sch.id',
            'password' => 'admin123',
        ]);

        $response->assertRedirect('/dashboard');
        $this->assertAuthenticated();
    }

    public function test_dashboard_renders_with_dummy_kpi_and_device_status(): void
    {
        $admin = User::where('email', 'admin@sekolah.sch.id')->first();
        $this->actingAs($admin);

        $response = $this->get('/dashboard');
        $response->assertStatus(200);
        $response->assertSee('Monitoring Presensi Realtime');
        $response->assertSee('Solution X601');
        $response->assertSee('192.168.1.201');
        $response->assertSee('WhatsApp Gateway (Outbox)');
        $response->assertSee('Tepat Waktu');

        // Footer menampilkan nama database dari konfigurasi, bukan string statis.
        $response->assertSee('Database MySQL:');
        $response->assertSee((string) config('database.connections.'.config('database.default').'.database'), false);
        $response->assertDontSee('absensi_siswa');
    }

    public function test_login_is_rate_limited_after_five_attempts(): void
    {
        $status = null;
        for ($i = 0; $i < 6; $i++) {
            $status = $this->post('/login', [
                'email' => 'admin@sekolah.sch.id',
                'password' => 'password-salah',
            ])->getStatusCode();
        }

        $this->assertSame(429, $status);
    }

    public function test_dashboard_stat_aggregations_match_direct_queries(): void
    {
        $admin = User::where('email', 'admin@sekolah.sch.id')->first();
        $this->actingAs($admin);

        $today = Carbon::today('Asia/Jakarta')->toDateString();

        $response = $this->get('/dashboard?date='.$today);
        $response->assertStatus(200);

        $response->assertViewHas('waStats', function (array $waStats) use ($today) {
            $count = fn (?string $status = null) => NotificationLog::whereDate('created_at', $today)
                ->when($status !== null, fn ($q) => $q->where('status', $status))
                ->count();

            return $waStats === [
                'total' => $count(),
                'queued' => $count('queued'),
                'held' => $count('held'),
                'sent' => $count('sent'),
                'delivered' => $count('delivered'),
                'failed' => $count('failed'),
                'cancelled' => $count('cancelled'),
            ];
        });

        $response->assertViewHas('guardianStats', function (array $stats) {
            $count = fn (?string $status = null) => Guardian::where('active', true)
                ->when($status === 'pending', fn ($q) => $q->where(fn ($x) => $x->where('wa_status', 'pending')->orWhereNull('wa_status')))
                ->when($status !== null && $status !== 'pending', fn ($q) => $q->where('wa_status', $status))
                ->count();

            return $stats === [
                'total' => $count(),
                'valid' => $count('valid'),
                'pending' => $count('pending'),
                'invalid' => $count('invalid'),
                'unknown' => $count('unknown'),
            ];
        });
    }

    public function test_user_can_logout(): void
    {
        $admin = User::where('email', 'admin@sekolah.sch.id')->first();
        $this->actingAs($admin);

        $response = $this->post('/logout');
        $response->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_dashboard_attendance_stats_use_aggregates_and_limit_the_preview(): void
    {
        $admin = User::where('email', 'admin@sekolah.sch.id')->first();
        $this->actingAs($admin);

        $class = SchoolClass::create(['name' => 'X-IPA-1', 'academic_year' => '2026/2027']);
        $date = '2026-10-14';

        // 55 siswa hadir → melebihi batas pratinjau 50.
        for ($i = 1; $i <= 55; $i++) {
            $student = Student::create([
                'nis' => '9'.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                'name' => 'Siswa '.$i,
                'gender' => 'L',
                'class_id' => $class->id,
                'active' => true,
            ]);

            // Insert mentah agar kolom DATE tersimpan apa adanya ('Y-m-d'),
            // selaras dengan pencarian `where('school_date', ...)` di controller.
            DB::table('attendances')->insert([
                'student_id' => $student->id,
                'school_date' => $date,
                'status' => 'hadir',
                'check_in_at' => $date.' 06:45:00',
                'policy_version' => 'v1.0',
                'is_manual_override' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $response = $this->get('/dashboard?date='.$date);
        $response->assertStatus(200);

        // Statistik dihitung dari agregat (semua 55 baris), bukan dari koleksi pratinjau.
        $response->assertViewHas('hadirCount', 55);
        $response->assertViewHas('belumHadirCount', 0);

        // Tabel pratinjau dibatasi agar tidak memuat seluruh baris ke memori.
        $response->assertViewHas('attendances', fn ($attendances) => $attendances->count() === 50);
    }
}
