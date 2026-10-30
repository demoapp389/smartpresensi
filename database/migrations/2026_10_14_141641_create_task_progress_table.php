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
        Schema::create('task_progress', function (Blueprint $table) {
            $table->id();

            // Token acak yang tidak ditebak, dipakai browser untuk polling progres
            // lewat endpoint stateless (tanpa sesi) agar tidak terblokir lock file sesi.
            $table->uuid('uuid')->unique();

            $table->string('type', 60);              // Contoh: students.import, devices.bulk-clone
            $table->string('label', 191);            // Label ramah pengguna di progress bar
            $table->string('status', 20)->default('pending'); // pending, running, completed, failed
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('processed')->default(0);
            $table->string('message', 191)->nullable();
            $table->json('results')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            $table->timestamps();

            // Mendukung pembersihan data progres lama secara berkala.
            $table->index(['status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('task_progress');
    }
};
