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
        Schema::create('guardian_phone_validation_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('guardian_id')->constrained('guardians')->cascadeOnDelete();
            $table->string('phone', 25);
            $table->string('status', 20)->index(); // valid, invalid, unknown
            $table->json('raw_response')->nullable();
            $table->text('error_message')->nullable();
            $table->string('validated_by', 50)->default('system'); // system, manual, periodic, import
            $table->timestamps();

            $table->index(['guardian_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('guardian_phone_validation_logs');
    }
};
