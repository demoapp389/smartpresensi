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
        Schema::table('notification_logs', function (Blueprint $table) {
            // Mempercepat pengecekan dedupe per nomor WhatsApp pada event & absensi yang sama (hemat kuota).
            $table->index(['attendance_id', 'event_type', 'recipient_phone'], 'notification_logs_phone_dedupe_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notification_logs', function (Blueprint $table) {
            $table->dropIndex('notification_logs_phone_dedupe_index');
        });
    }
};
