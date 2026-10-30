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
        Schema::table('fingerprint_devices', function (Blueprint $table) {
            // Jalur ingest utama per mesin: 'connector' (daemon TCP 4370) atau 'soap' (Web SDK port 80).
            // Nullable agar mesin lama tetap kompatibel dan bisa diputuskan otomatis oleh scheduler.
            $table->string('ingest_driver', 20)->nullable()->after('active')->index();
            // Kapabilitas mesin hasil probe (mis. live_event, attendance_read, fingerprint), disimpan sebagai JSON.
            $table->json('capabilities')->nullable()->after('ingest_driver');
            // Waktu terakhir kapabilitas mesin diprobe (Tombol "Tes Koneksi" / command probe).
            $table->timestamp('last_probe_at')->nullable()->after('capabilities');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('fingerprint_devices', function (Blueprint $table) {
            $table->dropIndex(['ingest_driver']);
            $table->dropColumn(['ingest_driver', 'capabilities', 'last_probe_at']);
        });
    }
};
