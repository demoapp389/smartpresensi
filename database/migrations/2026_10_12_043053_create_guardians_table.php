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
        Schema::create('guardians', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('relationship', 50)->default('Wali'); // Ayah, Ibu, Wali
            $table->string('wa_number', 25); // Format E.164 e.g. 6281234567890
            $table->timestamp('consent_at')->nullable(); // Persetujuan penerimaan pesan WA (FR-07)
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index('active');
            $table->index('wa_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('guardians');
    }
};
