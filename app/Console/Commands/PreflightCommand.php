<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class PreflightCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:preflight';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Periksa kesiapan konfigurasi produksi sebelum go-live (APP_DEBUG, APP_KEY, DB, izin tulis storage)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $failures = [];

        $this->line('Menjalankan preflight produksi...');

        // 1. APP_KEY
        if (blank(config('app.key'))) {
            $failures[] = 'APP_KEY belum diisi. Jalankan: php artisan key:generate';
        } else {
            $this->info('   [OK] APP_KEY terisi.');
        }

        // 2. APP_DEBUG wajib mati saat produksi.
        if (config('app.env') === 'production' && config('app.debug')) {
            $failures[] = 'APP_DEBUG=true saat APP_ENV=production. Matikan APP_DEBUG untuk mencegah kebocoran data sensitif.';
        } else {
            $this->info('   [OK] Kombinasi APP_ENV/APP_DEBUG aman.');
        }

        // 3. Koneksi database.
        try {
            DB::connection()->getPdo();
            $this->info('   [OK] Koneksi database berhasil.');
        } catch (\Throwable $e) {
            $failures[] = 'Koneksi database gagal: '.$e->getMessage();
        }

        // 4. Storage dapat ditulis.
        $probe = storage_path('app/preflight_probe.txt');

        try {
            File::put($probe, (string) now()->timestamp);

            if (File::exists($probe)) {
                File::delete($probe);
                $this->info('   [OK] Storage dapat ditulis.');
            } else {
                $failures[] = 'Storage tidak dapat ditulis.';
            }
        } catch (\Throwable $e) {
            $failures[] = 'Storage tidak dapat ditulis: '.$e->getMessage();
        }

        // 5. Peringatan hardening (tidak menggagalkan deploy, tetapi perlu ditinjau).
        if (! config('session.encrypt')) {
            $this->warn('   [WARN] SESSION_ENCRYPT=false. Aktifkan enkripsi sesi di produksi.');
        }

        if (str_starts_with((string) config('app.url'), 'https://') && ! config('session.secure')) {
            $this->warn('   [WARN] APP_URL memakai HTTPS tetapi SESSION_SECURE_COOKIE belum aktif.');
        }

        if (config('mail.default') === 'log') {
            $this->warn('   [WARN] MAIL_MAILER=log: tautan reset kata sandi akan tersimpan di berkas log.');
        }

        if (in_array(config('whatsapp.default'), ['fonnte', 'meta'], true)) {
            $webhookReady = config('whatsapp.webhook_secret') !== ''
                || config('whatsapp.meta.app_secret') !== '';

            if (! $webhookReady) {
                $this->warn('   [WARN] WA_WEBHOOK_SECRET/WA_META_APP_SECRET kosong: webhook WhatsApp akan menolak semua event.');
            }
        }

        if ($failures !== []) {
            $this->newLine();
            $this->error('Preflight GAGAL:');

            foreach ($failures as $failure) {
                $this->error('   - '.$failure);
            }

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Preflight SUKSES: konfigurasi siap produksi.');

        return self::SUCCESS;
    }
}
