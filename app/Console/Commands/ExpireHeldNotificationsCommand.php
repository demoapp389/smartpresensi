<?php

namespace App\Console\Commands;

use App\Models\NotificationLog;
use Illuminate\Console\Command;

class ExpireHeldNotificationsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'whatsapp:expire-held-notifications
                            {--hours= : Usia kedaluwarsa dalam jam (default dari config whatsapp.validation.expiration_hours)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Batalkan notifikasi WhatsApp berstatus held yang sudah kedaluwarsa agar tidak menggantung selamanya';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $hours = (int) ($this->option('hours') ?: config('whatsapp.validation.expiration_hours', 24));
        $cutoff = now()->subHours($hours);

        $expired = NotificationLog::where('status', 'held')
            ->where('created_at', '<', $cutoff)
            ->update([
                'status' => 'cancelled',
                'error_message' => "Pemberitahuan absensi kedaluwarsa ({$hours} jam) sebelum nomor WhatsApp berhasil divalidasi.",
            ]);

        if ($expired === 0) {
            $this->info('Tidak ada notifikasi held yang kedaluwarsa.');

            return self::SUCCESS;
        }

        $this->info("{$expired} notifikasi held kedaluwarsa dibatalkan (ambang {$hours} jam).");

        return self::SUCCESS;
    }
}
