<?php

namespace App\Observers;

use App\Jobs\ValidateGuardianPhoneJob;
use App\Models\Guardian;
use App\Services\WhatsApp\FonnteNumberValidationService;

class GuardianObserver
{
    public function saving(Guardian $guardian): void
    {
        if (! $guardian->isDirty('wa_number')) {
            return;
        }

        // Wali boleh tanpa nomor WhatsApp. Biarkan kosong apa adanya daripada
        // memalsukan nomor placeholder yang akan gagal saat validasi.
        if (blank($guardian->wa_number)) {
            $guardian->wa_number = null;

            if ($guardian->exists) {
                $guardian->wa_status = 'pending';
                $guardian->wa_validated_at = null;
                $guardian->wa_validation_error = null;
            }

            return;
        }

        $service = app(FonnteNumberValidationService::class);
        $guardian->wa_number = $service->normalize($guardian->wa_number);

        // Jika sedang update dan nomor berubah, reset status validasi
        if ($guardian->exists) {
            $guardian->wa_status = 'pending';
            $guardian->wa_validated_at = null;
            $guardian->wa_validation_error = null;
        }
    }

    public function created(Guardian $guardian): void
    {
        if (blank($guardian->wa_number)) {
            return;
        }

        if (config('whatsapp.validation.auto_validate_on_save', true)) {
            ValidateGuardianPhoneJob::dispatch($guardian->id, 'auto_create');
        }
    }

    public function updated(Guardian $guardian): void
    {
        if (blank($guardian->wa_number)) {
            return;
        }

        if (config('whatsapp.validation.auto_validate_on_save', true) && $guardian->wasChanged('wa_number')) {
            ValidateGuardianPhoneJob::dispatch($guardian->id, 'auto_update');
        }
    }
}
