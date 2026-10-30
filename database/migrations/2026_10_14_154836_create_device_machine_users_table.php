<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pengguna yang terdaftar pada mesin fingerprint, terpisah dari pemetaan ke siswa.
     *
     * Tabel ini adalah hasil sinkronisasi dari mesin (PIN + nama). Menjadikannya
     * tabel sendiri membuat mungkin untuk menampilkan pengguna mesin yang belum
     * dipetakan ke siswa mana pun, tanpa memaksa pembuatan data siswa otomatis.
     *
     * Identitas pengguna mesin adalah kombinasi device_id + fingerprint_user_id,
     * sehingga PIN yang sama pada dua mesin berbeda tetap dianggap dua pengguna
     * yang berbeda dan tidak saling tertukar.
     */
    public function up(): void
    {
        Schema::create('device_machine_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained('fingerprint_devices')->cascadeOnDelete();

            // Enrollment No / User ID pada mesin Solution X601.
            $table->string('fingerprint_user_id', 50);

            // Nama yang tersimpan di mesin (bukan identifier tepercaya).
            $table->string('machine_name', 150)->nullable();

            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['device_id', 'fingerprint_user_id']);

            // Pencarian silang: cari PIN yang sama di mesin lain.
            $table->index('fingerprint_user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('device_machine_users');
    }
};
