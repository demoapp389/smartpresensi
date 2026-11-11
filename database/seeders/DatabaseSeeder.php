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
                // Token dibuat acak oleh FingerprintDevice::booted() agar tidak
                // ada kredensial bersama yang tertanam di repositori.
                'last_seen_at' => now(),
                'active' => true,
            ]
        );

        // 3. Konfigurasi Jadwal Standar Sekolah (Senin - Sabtu)
        $days = [
            1 => ['name' => 'Senin', 'check_in_start' => '06:00:00', 'start_at' => '07:00:00', 'check_in_end' => '09:00:00', 'late_tolerance_minutes' => 15, 'check_out_start' => '14:00:00', 'end_at' => '16:00:00', 'check_out_end' => '17:00:00', 'day_pattern' => 'regular'],
            2 => ['name' => 'Selasa', 'check_in_start' => '06:00:00', 'start_at' => '07:00:00', 'check_in_end' => '09:00:00', 'late_tolerance_minutes' => 15, 'check_out_start' => '14:00:00', 'end_at' => '16:00:00', 'check_out_end' => '17:00:00', 'day_pattern' => 'regular'],
            3 => ['name' => 'Rabu', 'check_in_start' => '06:00:00', 'start_at' => '07:00:00', 'check_in_end' => '09:00:00', 'late_tolerance_minutes' => 15, 'check_out_start' => '14:00:00', 'end_at' => '16:00:00', 'check_out_end' => '17:00:00', 'day_pattern' => 'regular'],
            4 => ['name' => 'Kamis', 'check_in_start' => '06:00:00', 'start_at' => '07:00:00', 'check_in_end' => '09:00:00', 'late_tolerance_minutes' => 15, 'check_out_start' => '14:00:00', 'end_at' => '16:00:00', 'check_out_end' => '17:00:00', 'day_pattern' => 'regular'],
            5 => ['name' => 'Jumat', 'check_in_start' => '06:00:00', 'start_at' => '07:00:00', 'check_in_end' => '09:00:00', 'late_tolerance_minutes' => 15, 'check_out_start' => '11:30:00', 'end_at' => '14:00:00', 'check_out_end' => '15:00:00', 'day_pattern' => 'jumat'],
            6 => ['name' => 'Sabtu', 'check_in_start' => '06:00:00', 'start_at' => '07:00:00', 'check_in_end' => '09:00:00', 'late_tolerance_minutes' => 15, 'check_out_start' => '12:30:00', 'end_at' => '15:00:00', 'check_out_end' => '16:00:00', 'day_pattern' => 'sabtu'],
        ];

        foreach ($days as $dayNum => $d) {
            SchoolSchedule::firstOrCreate(
                ['day_of_week' => $dayNum],
                [
                    'day_name' => $d['name'],
                    'check_in_start' => $d['check_in_start'],
                    'check_in_end' => $d['check_in_end'],
                    'start_at' => $d['start_at'],
                    'late_after' => '07:15:00',
                    'late_tolerance_minutes' => $d['late_tolerance_minutes'],
                    'check_out_start' => $d['check_out_start'],
                    'end_at' => $d['end_at'],
                    'check_out_end' => $d['check_out_end'],
                    'early_leave_tolerance_minutes' => 0,
                    'day_pattern' => $d['day_pattern'],
                    'is_active' => true,
                ]
            );
        }
    }
}
