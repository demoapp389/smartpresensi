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
        Schema::create('device_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained('fingerprint_devices')->cascadeOnDelete();
            $table->string('sync_type', 30); // test_connection, sync_students, sync_attendances
            $table->string('status', 20); // success, failed
            $table->unsignedInteger('records_count')->default(0);
            $table->text('message')->nullable();
            $table->json('details')->nullable();
            $table->foreignId('operator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['device_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('device_sync_logs');
    }
};
