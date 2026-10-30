<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('school_schedules', function (Blueprint $table) {
            $table->id();
            $table->string('day_pattern', 50)->default('regular'); // regular, jumat, khusus, libur
            $table->string('day_name', 20); // Senin, Selasa, dst.
            $table->unsignedTinyInteger('day_of_week'); // 1 = Senin, 7 = Minggu
            $table->time('check_in_start')->default('06:00:00'); // Awal scan masuk diterima
            $table->time('start_at')->default('07:00:00'); // Jam masuk resmi
            $table->time('late_after')->default('07:15:00'); // Batas toleransi terlambat
            $table->time('check_out_start')->default('14:00:00'); // Awal jendela pulang
            $table->time('end_at')->default('16:00:00'); // Akhir jendela pulang
            $table->date('effective_date')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['day_of_week', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('school_schedules');
    }
};
