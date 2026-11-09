<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('school_schedules', function (Blueprint $table) {
            $table->time('check_in_end')->nullable()->after('check_in_start');
            $table->unsignedSmallInteger('late_tolerance_minutes')->nullable()->after('late_after');
            $table->unsignedSmallInteger('early_leave_tolerance_minutes')->nullable()->after('end_at');
            $table->time('check_out_end')->nullable()->after('check_out_start');
        });

        // Pertahankan perilaku lama: akhir scan masuk = awal scan pulang,
        // akhir scan pulang = jam pulang resmi, dan toleransi terlambat
        // diturunkan dari selisih late_after - start_at.
        DB::table('school_schedules')
            ->orderBy('id')
            ->chunkById(100, function ($schedules): void {
                foreach ($schedules as $schedule) {
                    DB::table('school_schedules')
                        ->where('id', $schedule->id)
                        ->update([
                            'check_in_end' => $schedule->check_out_start,
                            'check_out_end' => $schedule->end_at,
                            'late_tolerance_minutes' => $this->lateToleranceMinutes($schedule),
                            'early_leave_tolerance_minutes' => 0,
                        ]);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('school_schedules', function (Blueprint $table) {
            $table->dropColumn([
                'check_in_end',
                'late_tolerance_minutes',
                'early_leave_tolerance_minutes',
                'check_out_end',
            ]);
        });
    }

    private function lateToleranceMinutes(object $schedule): int
    {
        if (! $schedule->start_at || ! $schedule->late_after) {
            return 0;
        }

        $startAt = Carbon::parse('1970-01-01 '.$schedule->start_at);
        $lateAfter = Carbon::parse('1970-01-01 '.$schedule->late_after);

        return max(0, (int) floor(abs($lateAfter->diffInMinutes($startAt))));
    }
};
