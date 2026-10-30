<?php

namespace App\Services\WhatsApp;

use App\Jobs\SendWhatsAppNotificationJob;
use App\Models\Guardian;
use App\Models\GuardianPhoneValidationLog;
use App\Models\NotificationLog;
use App\Support\WhatsAppHttp;
use Illuminate\Support\Facades\Log;

class FonnteNumberValidationService
{
    protected string $token;

    protected string $validateUrl;

    protected int $batchDelayMs;

    protected int $chunkSize;

    public function __construct()
    {
        $this->token = config('whatsapp.fonnte.token', '');
        $this->validateUrl = config('whatsapp.fonnte.validate_url', 'https://api.fonnte.com/validate');
        $this->batchDelayMs = (int) config('whatsapp.validation.batch_delay_ms', 500);
        $this->chunkSize = (int) config('whatsapp.validation.chunk_size', 50);
    }

    /**
     * Normalisasi nomor telepon ke format E.164 Indonesia (contoh: 628123456789).
     */
    public function normalize(string $phone): string
    {
        $clean = preg_replace('/[^0-9]/', '', $phone);

        if (str_starts_with($clean, '0')) {
            $clean = '62'.substr($clean, 1);
        } elseif (str_starts_with($clean, '8')) {
            $clean = '62'.$clean;
        }

        return $clean;
    }

    /**
     * Periksa apakah nomor memenuhi format dasar nomor seluler Indonesia:
     * - Diawali 628
     * - Panjang total antara 10 hingga 15 digit.
     */
    public function isValidIndonesianMobileFormat(string $normalized): bool
    {
        return (bool) preg_match('/^628[0-9]{7,12}$/', $normalized);
    }

    /**
     * Validasi satu nomor telepon via Fonnte API /validate atau format check.
     *
     * @return array{
     *     status: 'valid'|'invalid'|'unknown',
     *     phone: string,
     *     normalized: string,
     *     raw: ?array,
     *     error: ?string
     * }
     */
    public function validateSingle(string $phone): array
    {
        $normalized = $this->normalize($phone);

        // Penghematan kuota: jika format nomor salah, langsung tandai invalid tanpa panggil API
        if (! $this->isValidIndonesianMobileFormat($normalized)) {
            return [
                'status' => 'invalid',
                'phone' => $phone,
                'normalized' => $normalized,
                'raw' => null,
                'error' => 'Format nomor tidak valid untuk seluler Indonesia (harus diawali 08/628 dan 10-15 digit).',
            ];
        }

        if (empty($this->token)) {
            if (config('whatsapp.default') === 'log' || config('whatsapp.default') === 'mock') {
                return [
                    'status' => 'valid',
                    'phone' => $phone,
                    'normalized' => $normalized,
                    'raw' => ['mock' => true],
                    'error' => null,
                ];
            }

            Log::warning('[FonnteNumberValidationService] Token Fonnte belum dikonfigurasi (WA_FONNTE_TOKEN).');

            return [
                'status' => 'unknown',
                'phone' => $phone,
                'normalized' => $normalized,
                'raw' => null,
                'error' => 'Token Fonnte belum dikonfigurasi di .env.',
            ];
        }

        try {
            $response = WhatsAppHttp::request()->withHeaders([
                'Authorization' => $this->token,
            ])->timeout(15)->post($this->validateUrl, [
                'target' => $normalized,
                'countryCode' => '62',
            ]);

            if (! $response->successful()) {
                Log::error('[FonnteValidate HTTP Error]', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return [
                    'status' => 'unknown',
                    'phone' => $phone,
                    'normalized' => $normalized,
                    'raw' => $response->json(),
                    'error' => "HTTP {$response->status()}: ".$response->body(),
                ];
            }

            $data = $response->json() ?? [];

            // Evaluasi respon API Fonnte
            return $this->parseFonnteResponseForNumber($normalized, $phone, $data);
        } catch (\Throwable $e) {
            Log::error('[FonnteValidate Exception]', ['message' => $e->getMessage()]);

            return [
                'status' => 'unknown',
                'phone' => $phone,
                'normalized' => $normalized,
                'raw' => null,
                'error' => 'Koneksi ke API Fonnte gagal: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Validasi nomor secara massal (batch berurutan tanpa concurrent API requests).
     *
     * @param  array<string>  $phones
     * @return array<string, array{status: string, normalized: string, raw: ?array, error: ?string}>
     */
    public function validateBatch(array $phones): array
    {
        $results = [];
        $validItems = [];

        // 1. Pre-filter format untuk menghemat kuota
        foreach ($phones as $phone) {
            $normalized = $this->normalize($phone);
            if (! $this->isValidIndonesianMobileFormat($normalized)) {
                $results[$phone] = [
                    'status' => 'invalid',
                    'normalized' => $normalized,
                    'raw' => null,
                    'error' => 'Format nomor tidak valid untuk seluler Indonesia.',
                ];
            } else {
                $validItems[] = [
                    'original' => $phone,
                    'normalized' => $normalized,
                ];
            }
        }

        if (empty($validItems)) {
            return $results;
        }

        if (empty($this->token)) {
            $isMock = config('whatsapp.default') === 'log' || config('whatsapp.default') === 'mock';
            foreach ($validItems as $item) {
                $results[$item['original']] = [
                    'status' => $isMock ? 'valid' : 'unknown',
                    'normalized' => $item['normalized'],
                    'raw' => $isMock ? ['mock' => true] : null,
                    'error' => $isMock ? null : 'Token Fonnte belum dikonfigurasi di .env.',
                ];
            }

            return $results;
        }

        // 2. Pecah per chunk dan kirim secara sekuensial
        $chunks = array_chunk($validItems, $this->chunkSize);

        foreach ($chunks as $index => $chunkItems) {
            if ($index > 0 && $this->batchDelayMs > 0) {
                usleep($this->batchDelayMs * 1000);
            }

            $targetList = array_map(fn ($item) => (string) $item['normalized'], $chunkItems);
            $targetString = implode(',', $targetList);

            try {
                $response = WhatsAppHttp::request()->withHeaders([
                    'Authorization' => $this->token,
                ])->timeout(20)->post($this->validateUrl, [
                    'target' => $targetString,
                    'countryCode' => '62',
                ]);

                if (! $response->successful()) {
                    foreach ($chunkItems as $item) {
                        $results[$item['original']] = [
                            'status' => 'unknown',
                            'normalized' => $item['normalized'],
                            'raw' => $response->json(),
                            'error' => "HTTP {$response->status()}: ".$response->body(),
                        ];
                    }

                    continue;
                }

                $data = $response->json() ?? [];

                // Parsing array registered dan not_registered (pastikan string)
                $registered = array_map(fn ($n) => (string) $this->normalize((string) $n), $data['registered'] ?? []);
                $notRegistered = array_map(fn ($n) => (string) $this->normalize((string) $n), $data['not_registered'] ?? []);

                foreach ($chunkItems as $item) {
                    $norm = (string) $item['normalized'];
                    $orig = $item['original'];

                    if (in_array($norm, $registered, true)) {
                        $results[$orig] = [
                            'status' => 'valid',
                            'normalized' => $norm,
                            'raw' => $data,
                            'error' => null,
                        ];
                    } elseif (in_array($norm, $notRegistered, true)) {
                        $results[$orig] = [
                            'status' => 'invalid',
                            'normalized' => $norm,
                            'raw' => $data,
                            'error' => 'Nomor tidak terdaftar di aplikasi WhatsApp (bukan akun WhatsApp aktif).',
                        ];
                    } else {
                        // Jika status false dari API
                        $isSuccess = $data['status'] ?? false;
                        $results[$orig] = [
                            'status' => $isSuccess ? 'invalid' : 'unknown',
                            'normalized' => $norm,
                            'raw' => $data,
                            'error' => $data['reason'] ?? ($isSuccess ? 'Nomor tidak terdaftar.' : 'Respons API tidak diketahui.'),
                        ];
                    }
                }
            } catch (\Throwable $e) {
                Log::error('[FonnteValidate Batch Exception]', ['message' => $e->getMessage()]);
                foreach ($chunkItems as $item) {
                    $results[$item['original']] = [
                        'status' => 'unknown',
                        'normalized' => $item['normalized'],
                        'raw' => null,
                        'error' => 'Gagal menghubungi Fonnte API: '.$e->getMessage(),
                    ];
                }
            }
        }

        return $results;
    }

    /**
     * Validasi nomor untuk sebuah model Guardian dan catat log validasi.
     */
    public function validateGuardian(Guardian $guardian, string $validatedBy = 'system'): array
    {
        $result = $this->validateSingle($guardian->wa_number);

        // Update guardian
        $guardian->update([
            'wa_number' => $result['normalized'],
            'wa_status' => $result['status'],
            'wa_validated_at' => now(),
            'wa_validation_error' => $result['error'],
        ]);

        // Catat log validasi
        GuardianPhoneValidationLog::create([
            'guardian_id' => $guardian->id,
            'phone' => $result['normalized'],
            'status' => $result['status'],
            'raw_response' => $result['raw'],
            'error_message' => $result['error'],
            'validated_by' => $validatedBy,
        ]);

        // Tangani notifikasi yang tertahan (held)
        if ($result['status'] === 'valid') {
            $this->releaseHeldNotifications($guardian);
        } elseif ($result['status'] === 'invalid') {
            $this->cancelHeldNotifications($guardian);
        }

        return $result;
    }

    /**
     * Rilis notifikasi yang sebelumnya berstatus 'held' ke antrean pengiriman jika belum kedaluwarsa.
     */
    public function releaseHeldNotifications(Guardian $guardian): int
    {
        $expirationHours = (int) config('whatsapp.validation.expiration_hours', 24);
        $cutoff = now()->subHours($expirationHours);

        $heldLogs = NotificationLog::where('guardian_id', $guardian->id)
            ->where('status', 'held')
            ->get();

        $releasedCount = 0;

        foreach ($heldLogs as $log) {
            if ($log->created_at < $cutoff) {
                $log->update([
                    'status' => 'cancelled',
                    'error_message' => 'Pemberitahuan absensi kedaluwarsa sebelum nomor WhatsApp berhasil divalidasi.',
                ]);
            } else {
                $log->update([
                    'status' => 'queued',
                    'recipient_phone' => $guardian->wa_number,
                    'queued_at' => now(),
                    'error_message' => null,
                ]);

                SendWhatsAppNotificationJob::dispatch($log->id);
                $releasedCount++;
            }
        }

        return $releasedCount;
    }

    /**
     * Batalkan notifikasi yang tertahan jika nomor terbukti invalid.
     */
    public function cancelHeldNotifications(Guardian $guardian): int
    {
        return NotificationLog::where('guardian_id', $guardian->id)
            ->where('status', 'held')
            ->update([
                'status' => 'cancelled',
                'error_message' => 'Nomor WhatsApp wali tidak terdaftar (invalid). Pengiriman dibatalkan tanpa mengurangi kuota.',
            ]);
    }

    /**
     * Parsing respon tunggal Fonnte
     */
    protected function parseFonnteResponseForNumber(string $normalized, string $originalPhone, array $data): array
    {
        $registered = array_map([$this, 'normalize'], $data['registered'] ?? []);
        $notRegistered = array_map([$this, 'normalize'], $data['not_registered'] ?? []);

        if (in_array($normalized, $registered, true)) {
            return [
                'status' => 'valid',
                'phone' => $originalPhone,
                'normalized' => $normalized,
                'raw' => $data,
                'error' => null,
            ];
        }

        if (in_array($normalized, $notRegistered, true)) {
            return [
                'status' => 'invalid',
                'phone' => $originalPhone,
                'normalized' => $normalized,
                'raw' => $data,
                'error' => 'Nomor tidak terdaftar di aplikasi WhatsApp (bukan akun WhatsApp aktif).',
            ];
        }

        $apiStatus = $data['status'] ?? false;
        if ($apiStatus === false) {
            return [
                'status' => 'unknown',
                'phone' => $originalPhone,
                'normalized' => $normalized,
                'raw' => $data,
                'error' => $data['reason'] ?? 'Gagal memvalidasi nomor melalui Fonnte API.',
            ];
        }

        return [
            'status' => 'invalid',
            'phone' => $originalPhone,
            'normalized' => $normalized,
            'raw' => $data,
            'error' => 'Nomor tidak terdaftar di WhatsApp.',
        ];
    }
}
