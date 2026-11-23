<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Status sidik jari per pengguna mesin.
     *
     * `has_template` dibiarkan nullable karena status hanya boleh diisi setelah
     * mesin benar-benar diperiksa. Nilai null berarti "belum diverifikasi",
     * bukan "tidak punya sidik jari".
     */
    public function up(): void
    {
        Schema::table('device_machine_users', function (Blueprint $table) {
            $table->boolean('has_template')->nullable()->after('machine_name');
            $table->timestamp('template_checked_at')->nullable()->after('has_template');
            $table->string('template_check_source', 20)->nullable()->after('template_checked_at');

            $table->index(['device_id', 'has_template'], 'device_machine_users_template_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('device_machine_users', function (Blueprint $table) {
            $table->dropIndex('device_machine_users_template_index');
            $table->dropColumn(['has_template', 'template_checked_at', 'template_check_source']);
        });
    }
};
