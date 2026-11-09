<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Isi jadwal default saat instalasi baru agar halaman Jadwal Absensi
     * langsung dapat digunakan tanpa INSERT manual ke database.
     */
    public function up(): void
    {
        // Jangan mengisi data contoh saat test suite berjalan; test membuat
        // jadwalnya sendiri dan data seed akan membuat query jadwal ambigu.
        if (app()->environment('testing')) {
            return;
        }

        if (DB::table('school_schedules')->exists()) {
            return;
        }

        $now = now();

        $days = [
            ['Senin', 1, 'regular', '06:00:00', '07:00:00', '09:00:00', 15, '14:00:00', '16:00:00', '17:00:00', 0],
            ['Selasa', 2, 'regular', '06:00:00', '07:00:00', '09:00:00', 15, '14:00:00', '16:00:00', '17:00:00', 0],
            ['Rabu', 3, 'regular', '06:00:00', '07:00:00', '09:00:00', 15, '14:00:00', '16:00:00', '17:00:00', 0],
            ['Kamis', 4, 'regular', '06:00:00', '07:00:00', '09:00:00', 15, '14:00:00', '16:00:00', '17:00:00', 0],
            ['Jumat', 5, 'jumat', '06:00:00', '07:00:00', '09:00:00', 15, '11:30:00', '14:00:00', '15:00:00', 0],
            ['Sabtu', 6, 'sabtu', '06:00:00', '07:00:00', '09:00:00', 15, '12:30:00', '15:00:00', '16:00:00', 0],
        ];

        DB::table('school_schedules')->insert(
            array_map(fn (array $day): array => [
                'day_name' => $day[0],
                'day_of_week' => $day[1],
                'day_pattern' => $day[2],
                'check_in_start' => $day[3],
                'start_at' => $day[4],
                'check_in_end' => $day[5],
                'late_after' => '07:15:00',
                'late_tolerance_minutes' => $day[6],
                'check_out_start' => $day[7],
                'end_at' => $day[8],
                'check_out_end' => $day[9],
                'early_leave_tolerance_minutes' => $day[10],
                'alpa_cutoff' => null,
                'effective_date' => null,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ], $days)
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Jadwal default tidak dihapus saat rollback agar konfigurasi yang
        // sudah diubah operator tidak hilang.
    }
};
