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
        Schema::create('device_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained('fingerprint_devices')->cascadeOnDelete();
            $table->string('machine_user_id', 50); // Enrollment No / User ID di mesin X601
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->timestamps();

            // Sesuai PRD Section 9: unique index (device_id, machine_user_id)
            $table->unique(['device_id', 'machine_user_id']);
            $table->unique(['device_id', 'student_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('device_users');
    }
};
