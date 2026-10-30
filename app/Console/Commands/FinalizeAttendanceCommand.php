<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class FinalizeAttendanceCommand extends Command
{
    protected $signature = 'attendance:finalize';

    protected $description = 'Command finalisasi lama (dinonaktifkan; gunakan attendance:sync-pending)';

    public function handle(): int
    {
        $this->warn('Command attendance:finalize sudah dinonaktifkan.');
        $this->line('Gunakan attendance:sync-pending untuk materialisasi Perlu Tinjauan dan Auto-Alpa H+1.');

        return self::SUCCESS;
    }
}
