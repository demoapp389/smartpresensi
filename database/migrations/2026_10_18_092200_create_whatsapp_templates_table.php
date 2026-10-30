<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('whatsapp_templates', function (Blueprint $table) {
            $table->id();
            $table->string('event_type', 50)->unique(); // check_in, late, check_out, broadcast
            $table->string('name', 100);
            $table->text('content');
            $table->string('description', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Seed default templates
        $defaultTemplates = [
            [
                'event_type' => 'check_in',
                'name' => 'Presensi Masuk Tepat Waktu',
                'content' => "Assalamu'alaikum / Selamat Pagi Bapak/Ibu Wali dari :nama_siswa (Kelas :kelas).\n\nMenginformasikan bahwa siswa telah HADIR dan melakukan scan absensi masuk di :nama_sekolah pada tanggal :tanggal pukul :waktu.\n\nTerima kasih.",
                'description' => 'Terkirim saat siswa scan sidik jari di rentang waktu jam masuk tepat waktu.',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'event_type' => 'late',
                'name' => 'Presensi Masuk Terlambat',
                'content' => "Assalamu'alaikum / Selamat Pagi Bapak/Ibu Wali dari :nama_siswa (Kelas :kelas).\n\nMenginformasikan bahwa siswa hadir TERLAMBAT di :nama_sekolah pada tanggal :tanggal pukul :waktu.\n\nTerima kasih atas perhatiannya.",
                'description' => 'Terkirim saat siswa scan sidik jari melewati batas toleransi keterlambatan.',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'event_type' => 'check_out',
                'name' => 'Presensi Pulang Sekolah',
                'content' => "Assalamu'alaikum / Selamat Siang Bapak/Ibu Wali dari :nama_siswa (Kelas :kelas).\n\nMenginformasikan bahwa siswa telah SELESAI kegiatan sekolah dan melakukan scan absensi pulang di :nama_sekolah pada tanggal :tanggal pukul :waktu.\n\nTerima kasih.",
                'description' => 'Terkirim saat siswa scan sidik jari pada jam kepulangan sekolah.',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'event_type' => 'broadcast',
                'name' => 'Siaran Pengumuman Sekolah',
                'content' => "Assalamu'alaikum / Salam Sejahtera Bapak/Ibu Wali dari :nama_siswa (Kelas :kelas).\n\nPEMBERITAHUAN DARI :nama_sekolah:\n:pesan\n\nDemikian informasi ini disampaikan. Terima kasih atas perhatian dan kerja samanya.",
                'description' => 'Format standar untuk pengumuman massal sekolah yang dikirim ke wali murid.',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ];

        DB::table('whatsapp_templates')->insert($defaultTemplates);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('whatsapp_templates');
    }
};
