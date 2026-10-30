<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Allow a guardian to have no WhatsApp number.
     *
     * Import previously fabricated the placeholder number `628000000000` when the
     * spreadsheet column was empty, which produced a bogus number that later failed
     * validation and still consumed the daily message quota. Storing an empty value
     * keeps the database honest about what was actually provided.
     */
    public function up(): void
    {
        Schema::table('guardians', function (Blueprint $table): void {
            $table->string('wa_number', 25)->nullable()->change();
        });
    }

    /**
     * Run the migrations.
     */
    public function down(): void
    {
        Schema::table('guardians', function (Blueprint $table): void {
            $table->string('wa_number', 25)->nullable(false)->change();
        });
    }
};
