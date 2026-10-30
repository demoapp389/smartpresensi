<?php

namespace App\Jobs;

use App\Models\Guardian;
use App\Services\WhatsApp\FonnteNumberValidationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job validasi nomor WhatsApp orang tua secara asinkron.
 * Terpisah dari queue pengiriman WhatsApp agar tidak memblokir notifikasi absensi.
 */
class ValidateGuardianPhoneJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public array $backoff = [30, 120];

    public int $guardianId;

    public string $validatedBy;

    public function __construct(int $guardianId, string $validatedBy = 'system')
    {
        $this->guardianId = $guardianId;
        $this->validatedBy = $validatedBy;
        $this->onQueue('validations');
    }

    public function handle(FonnteNumberValidationService $service): void
    {
        $guardian = Guardian::find($this->guardianId);

        if (! $guardian) {
            Log::warning("[ValidateGuardianPhoneJob] Guardian ID {$this->guardianId} tidak ditemukan.");

            return;
        }

        $result = $service->validateGuardian($guardian, $this->validatedBy);

        Log::info("[ValidateGuardianPhoneJob] Guardian ID {$guardian->id} ({$guardian->name}) divalidasi dengan status: {$result['status']}");
    }
}
