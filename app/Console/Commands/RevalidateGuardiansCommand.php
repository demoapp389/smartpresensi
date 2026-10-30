<?php

namespace App\Console\Commands;

use App\Jobs\ValidateGuardianPhoneJob;
use App\Models\Guardian;
use Illuminate\Console\Command;

class RevalidateGuardiansCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'whatsapp:revalidate-guardians 
                            {--force : Paksa validasi meskipun fitur revalidation nonaktif di config} 
                            {--limit=100 : Batas jumlah wali yang divalidasi}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Validasi ulang nomor WhatsApp wali siswa secara berkala untuk menjaga akurasi kuota Fonnte';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $isEnabled = config('whatsapp.revalidation.enabled', false);
        $force = $this->option('force');

        if (! $isEnabled && ! $force) {
            $this->warn('Fitur validasi ulang berkala dinonaktifkan (WA_REVALIDATION_ENABLED=false). Gunakan --force untuk tetap menjalankan.');

            return self::SUCCESS;
        }

        $intervalDays = (int) config('whatsapp.revalidation.interval_days', 30);
        $limit = (int) $this->option('limit');
        $thresholdDate = now()->subDays($intervalDays);

        $guardians = Guardian::where('active', true)
            ->where(function ($query) use ($thresholdDate) {
                $query->whereNull('wa_validated_at')
                    ->orWhere('wa_validated_at', '<', $thresholdDate);
            })
            ->limit($limit)
            ->get();

        if ($guardians->isEmpty()) {
            $this->info('Tidak ada nomor wali yang memerlukan validasi ulang saat ini.');

            return self::SUCCESS;
        }

        $this->info("Ditemukan {$guardians->count()} wali untuk divalidasi ulang. Menjadwalkan job validasi...");

        $bar = $this->output->createProgressBar($guardians->count());
        $bar->start();

        foreach ($guardians as $guardian) {
            ValidateGuardianPhoneJob::dispatch($guardian->id, 'periodic_revalidation');
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info('Semua job validasi ulang berhasil dimasukkan ke queue.');

        return self::SUCCESS;
    }
}
