<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * DB-03: lindungi jejak audit absensi.
     *
     * Sebelumnya `attendance_logs.device_id` memakai `cascadeOnDelete`, sehingga
     * menghapus satu perangkat ikut menghapus seluruh log absensinya. Log absensi
     * adalah bukti kehadiran, jadi relasinya diubah menjadi `nullOnDelete`:
     * perangkat dihapus, log tetap tersimpan dengan `device_id` NULL.
     *
     * Migrasi ini menyasar basis data yang sudah terlanjur dibuat dengan relasi
     * cascade (mis. MySQL produksi). Untuk instalasi SQLite/SQLite in-memory,
     * migrasi asal sudah menghasilkan `nullOnDelete` sehingga dilewati.
     */
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('attendance_logs', function (Blueprint $table): void {
            $table->dropForeign(['device_id']);
        });

        Schema::table('attendance_logs', function (Blueprint $table): void {
            $table->unsignedBigInteger('device_id')->nullable()->change();
        });

        Schema::table('attendance_logs', function (Blueprint $table): void {
            $table->foreign('device_id')->references('id')->on('fingerprint_devices')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('attendance_logs', function (Blueprint $table): void {
            $table->dropForeign(['device_id']);
        });

        Schema::table('attendance_logs', function (Blueprint $table): void {
            $table->unsignedBigInteger('device_id')->nullable(false)->change();
        });

        Schema::table('attendance_logs', function (Blueprint $table): void {
            $table->foreign('device_id')->references('id')->on('fingerprint_devices')->cascadeOnDelete();
        });
    }
};
