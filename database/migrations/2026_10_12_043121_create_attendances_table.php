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
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->date('school_date')->index(); // Tanggal absensi (Asia/Jakarta)
            $table->dateTime('check_in_at')->nullable();
            $table->dateTime('check_out_at')->nullable();
            $table->string('status', 30)->default('hadir')->index(); // hadir, terlambat, pulang_cepat, izin, sakit, alpa, perlu_tinjauan
            $table->string('policy_version', 20)->default('v1.0');
            $table->foreignId('check_in_log_id')->nullable()->constrained('attendance_logs')->nullOnDelete();
            $table->foreignId('check_out_log_id')->nullable()->constrained('attendance_logs')->nullOnDelete();

            // Kolom koreksi manual oleh operator (FR-10)
            $table->boolean('is_manual_override')->default(false);
            $table->text('override_reason')->nullable();
            $table->foreignId('operator_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // Sesuai PRD Section 9: unique index (student_id, school_date)
            $table->unique(['student_id', 'school_date']);
            $table->index(['school_date', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendances');
    }
};
