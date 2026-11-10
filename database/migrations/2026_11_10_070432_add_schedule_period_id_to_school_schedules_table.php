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
        Schema::table('school_schedules', function (Blueprint $table) {
            $table->foreignId('period_id')
                ->nullable()
                ->after('day_of_week')
                ->constrained('schedule_periods')
                ->cascadeOnDelete();

            $table->index(['period_id', 'day_of_week']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('school_schedules', function (Blueprint $table) {
            $table->dropForeign(['period_id']);
            $table->dropIndex(['period_id', 'day_of_week']);
            $table->dropColumn('period_id');
        });
    }
};
