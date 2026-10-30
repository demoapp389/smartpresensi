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
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->unsignedTinyInteger('processing_attempts')->default(0)->after('processing_note');
            $table->timestamp('last_attempted_at')->nullable()->after('processing_attempts');
            $table->timestamp('next_retry_at')->nullable()->after('last_attempted_at');
            $table->index(['status', 'next_retry_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->dropIndex(['status', 'next_retry_at']);
            $table->dropColumn(['processing_attempts', 'last_attempted_at', 'next_retry_at']);
        });
    }
};
