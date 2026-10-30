<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Prevent the same physical fingerprint machine from being registered more than once.
     *
     * Without this constraint, two device rows can share one IP address. The heartbeat
     * probes each row independently, so both would be reported online for a single
     * physical machine, and attendance logs could be attributed to the wrong device.
     */
    public function up(): void
    {
        Schema::table('fingerprint_devices', function (Blueprint $table): void {
            $table->unique('ip', 'fingerprint_devices_ip_unique');
        });
    }

    /**
     * Run the migrations.
     */
    public function down(): void
    {
        Schema::table('fingerprint_devices', function (Blueprint $table): void {
            $table->dropUnique('fingerprint_devices_ip_unique');
        });
    }
};
