<?php

namespace Database\Seeders;

use App\Models\FingerprintDevice;
use App\Models\SchoolSchedule;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed initial configuration and admin accounts.
     * Tidak ada data dummy siswa, raw log, atau kehadiran palsu (Tahap 3: Mesin Asli).
     */
    public function run(): void
    {
        // 1. Akun Autentikasi Pengelola Sekolah
        User::firstOrCreate(
            ['email' => 'admin@sekolah.sch.id'],
            [
                'name' => 'Administrator Sekolah',
                'password' => Hash::make('admin123'),
                'role' => 'admin',
                'is_active' => true,
            ]
        );

        User::firstOrCreate(
            ['email' => 'operator@sekolah.sch.id'],
            [
                'name' => 'Operator Absensi',
                'password' => Hash::make('operator123'),
                'role' => 'operator',
                'is_active' => true,
            ]
        );

        // 2. Konfigurasi Mesin Fingerprint Solution X601 (LAN Asli)
        FingerprintDevice::firstOrCreate(
            ['ip' => '192.168.1.201'],
            [
                'name' => 'Solution X601 - Gerbang Utama',
                'port' => 4370,
                'comm_key' => '0',
                'timezone' => 'Asia/Jakarta',
                'status' => 'active',
                'secret_token' => 'x601_sec_token_school_solution_lan',
                'last_seen_at' => now(),
                'active' => true,
            ]
        );

        // 3. Konfigurasi Jadwal Standar Sekolah (Senin - Sabtu)
        $days = [
            1 => ['Senin', '06:00:00', '07:00:00', '07:15:00', '14:00:00', '16:00:00', 'regular'],
            2 => ['Selasa', '06:00:00', '07:00:00', '07:15:00', '14:00:00', '16:00:00', 'regular'],
            3 => ['Rabu', '06:00:00', '07:00:00', '07:15:00', '14:00:00', '16:00:00', 'regular'],
            4 => ['Kamis', '06:00:00', '07:00:00', '07:15:00', '14:00:00', '16:00:00', 'regular'],
            5 => ['Jumat', '06:00:00', '07:00:00', '07:15:00', '11:30:00', '14:00:00', 'jumat'],
            6 => ['Sabtu', '06:00:00', '07:00:00', '07:15:00', '12:30:00', '15:00:00', 'sabtu'],
        ];

        foreach ($days as $dayNum => $d) {
            SchoolSchedule::firstOrCreate(
                ['day_of_week' => $dayNum],
                [
                    'day_name' => $d[0],
                    'check_in_start' => $d[1],
                    'start_at' => $d[2],
                    'late_after' => $d[3],
                    'check_out_start' => $d[4],
                    'end_at' => $d[5],
                    'day_pattern' => $d[6],
                    'is_active' => true,
                ]
            );
        }
    }
}
