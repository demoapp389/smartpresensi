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
        Schema::create('fingerprint_devices', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100); // contoh: Solution X601 - Gerbang Depan
            $table->string('ip', 45); // IP LAN contoh: 192.168.1.201
            $table->integer('port')->default(4370);
            $table->string('comm_key', 50)->default('0'); // Communication password/key X601
            $table->string('timezone', 50)->default('Asia/Jakarta');
            $table->string('status', 30)->default('active'); // active, offline, maintenance
            $table->string('secret_token', 64)->nullable()->unique(); // Token untuk autentikasi C# connector
            $table->timestamp('last_seen_at')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fingerprint_devices');
    }
};
