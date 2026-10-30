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
        Schema::create('notification_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_id')->nullable()->constrained('attendances')->cascadeOnDelete();
            $table->foreignId('guardian_id')->constrained('guardians')->cascadeOnDelete();
            $table->string('event_type', 30); // check_in, check_out, late, absent
            $table->string('idempotency_key', 128)->unique(); // BR-06 No double send
            $table->string('template_name', 100);
            $table->string('recipient_phone', 25);
            $table->json('payload')->nullable();
            $table->string('status', 30)->default('queued')->index(); // queued, sent, delivered, read, failed
            $table->unsignedInteger('attempts')->default(0);
            $table->text('error_message')->nullable();
            $table->string('provider_message_id', 128)->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notification_logs');
    }
};
