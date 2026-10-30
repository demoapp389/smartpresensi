<?php

namespace App\Jobs;

use App\Models\Guardian;
use App\Models\GuardianPhoneValidationLog;
use App\Models\TaskProgress;
use App\Services\WhatsApp\FonnteNumberValidationService;
use App\Support\TaskProgressReporter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Validasi nomor WhatsApp wali secara massal.
 *
 * Menggantikan pola "lempar 50 job terpisah" yang tidak memberi umpan balik apa pun
 * kepada operator. Satu task ini melapor progres per nomor dan merangkum hasilnya.
 */
class BulkValidateGuardianPhones implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    public int $tries = 1;

    /**
     * @param  list<int>  $guardianIds
     */
    public function __construct(
        public array $guardianIds,
        public string $actor,
        public string $progressUuid,
    ) {
        // Antrean terpisah supaya validasi massal tidak memblokir notifikasi WhatsApp.
        $this->onQueue('long-running');
    }

    public function handle(FonnteNumberValidationService $validationService): void
    {
        $reporter = TaskProgressReporter::forTask(
            TaskProgress::where('uuid', $this->progressUuid)->firstOrFail()
        );

        $guardians = Guardian::whereIn('id', $this->guardianIds)->get();

        if ($guardians->isEmpty()) {
            $reporter->fail('Tidak ada nomor wali yang perlu divalidasi pada kriteria ini.');

            return;
        }

        $reporter->setTotal($guardians->count(), 'Memvalidasi '.$guardians->count().' nomor wali...');

        // Nomor yang sama hanya perlu satu panggilan API, lalu hasilnya dibagi rata.
        $phoneMap = [];
        foreach ($guardians as $guardian) {
            $phoneMap[$guardian->wa_number] = $guardian;
        }

        $results = [];

        try {
            $results = $validationService->validateBatch(array_keys($phoneMap));
        } catch (\Throwable $e) {
            Log::error('[BulkValidateGuardianPhones] Batch validation gagal', [
                'error' => $e->getMessage(),
            ]);

            $reporter->fail('Validasi gagal: '.$e->getMessage());

            return;
        }

        $valid = 0;
        $invalid = 0;
        $unknown = 0;
        $now = now();

        // PERF-05: kumpulkan lalu simpan massal, bukan satu query per wali.
        $guardianRows = [];
        $logRows = [];
        $releasable = [];
        $cancellable = [];

        try {
            foreach ($guardians as $guardian) {
                $result = $results[$guardian->wa_number] ?? [
                    'status' => 'unknown',
                    'normalized' => $guardian->wa_number,
                    'raw' => null,
                    'error' => 'Tidak ada respon dari pemrosesan batch.',
                ];

                $guardianRows[] = [
                    'id' => $guardian->id,
                    // Kolom wajib untuk cabang INSERT; tidak ikut di-update.
                    'student_id' => $guardian->student_id,
                    'name' => $guardian->name,
                    'wa_number' => $result['normalized'],
                    'wa_status' => $result['status'],
                    'wa_validated_at' => $now,
                    'wa_validation_error' => $result['error'],
                ];

                $logRows[] = [
                    'guardian_id' => $guardian->id,
                    'phone' => $result['normalized'],
                    'status' => $result['status'],
                    'raw_response' => $result['raw'] !== null
                        ? json_encode($result['raw'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                        : null,
                    'error_message' => $result['error'],
                    'validated_by' => 'bulk_'.$this->actor,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                if ($result['status'] === 'valid') {
                    $valid++;
                    $releasable[] = $guardian;
                } elseif ($result['status'] === 'invalid') {
                    $invalid++;
                    $cancellable[] = $guardian;
                } else {
                    $unknown++;
                }

                $reporter->advance(1, "Memvalidasi {$guardian->name}...");
            }

            if ($guardianRows !== []) {
                Guardian::upsert(
                    $guardianRows,
                    ['id'],
                    ['wa_number', 'wa_status', 'wa_validated_at', 'wa_validation_error']
                );
            }

            if ($logRows !== []) {
                GuardianPhoneValidationLog::insert($logRows);
            }

            foreach ($releasable as $guardian) {
                $validationService->releaseHeldNotifications($guardian);
            }

            foreach ($cancellable as $guardian) {
                $validationService->cancelHeldNotifications($guardian);
            }

            $reporter->finish(
                ['valid' => $valid, 'invalid' => $invalid, 'unknown' => $unknown],
                "Validasi selesai: {$valid} valid, {$invalid} invalid, {$unknown} unknown."
            );
        } catch (\Throwable $e) {
            Log::error('[BulkValidateGuardianPhones] Penyimpanan hasil gagal', [
                'error' => $e->getMessage(),
            ]);

            $reporter->fail('Validasi gagal: '.$e->getMessage());
        }
    }
}
