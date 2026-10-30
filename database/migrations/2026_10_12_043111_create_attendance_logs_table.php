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
        Schema::create('attendance_logs', function (Blueprint $table) {
            $table->id();
            // DB-03: log absensi adalah jejak audit; menghapus perangkat TIDAK boleh
            // ikut menghapus log. device_id dibuat nullable + nullOnDelete agar log tetap ada.
            $table->foreignId('device_id')->nullable()->constrained('fingerprint_devices')->nullOnDelete();
            $table->string('machine_user_id', 50)->index();
            $table->dateTime('occurred_at')->index(); // Waktu scan pada mesin
            $table->dateTime('received_at'); // Waktu log diterima API server
            $table->string('source_key', 128); // Kunci unik log untuk deduplikasi idempotent
            $table->string('status', 30)->default('unprocessed'); // unprocessed, processed, unmapped, ignored
            $table->json('raw_payload')->nullable(); // Data mentah dari SDK
            $table->timestamps();

            // Wajib PRD Section 9: unique index (device_id, source_key)
            $table->unique(['device_id', 'source_key']);
            $table->index(['device_id', 'occurred_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance_logs');
    }
};
