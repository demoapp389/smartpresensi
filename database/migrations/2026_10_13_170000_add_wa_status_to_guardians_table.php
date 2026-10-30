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
        Schema::table('guardians', function (Blueprint $table) {
            $table->string('wa_status', 20)->default('pending')->after('wa_number')->index();
            $table->timestamp('wa_validated_at')->nullable()->after('wa_status');
            $table->text('wa_validation_error')->nullable()->after('wa_validated_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('guardians', function (Blueprint $table) {
            $table->dropIndex(['wa_status']);
            $table->dropColumn(['wa_status', 'wa_validated_at', 'wa_validation_error']);
        });
    }
};
