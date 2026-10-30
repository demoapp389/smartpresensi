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
        Schema::create('student_biometric_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->unsignedTinyInteger('finger_index')->default(0); // 0-9
            $table->longText('template_data');
            $table->unsignedInteger('template_size')->default(0);
            $table->foreignId('source_device_id')->nullable()->constrained('fingerprint_devices')->nullOnDelete();
            $table->timestamps();

            $table->unique(['student_id', 'finger_index']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('student_biometric_templates');
    }
};
