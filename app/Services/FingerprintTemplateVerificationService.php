<?php

namespace App\Services;

use App\Models\DeviceMachineUser;
use App\Models\FingerprintDevice;
use App\Support\TaskProgressReporter;
use Illuminate\Support\Facades\Log;

/**
 * Verifikasi status sidik jari pengguna mesin.
 *
 * Sumber kebenaran tetap mesin fisik: PIN yang terdaftar belum tentu punya
 * template sidik jari. Operasi ini hanya membaca mesin (tidak menarik template
 * ke server) lalu menyimpan hasilnya pada {@see DeviceMachineUser}.
 */
class FingerprintTemplateVerificationService
{
    /**
     * Jumlah kegagalan berurutan sebelum proses dihentikan.
     *
     * Satu PIN gagal bisa berarti mesin mati di tengah proses. Menghentikan
     * loop mencegah ratusan timeout yang membuat job menggantung lama.
     */
    private const MAX_CONSECUTIVE_FAILURES = 3;

    public function __construct(
        protected FingerprintConnectorService $connectorService,
        protected SoapFingerprintService $soapService,
    ) {}

    /**
     * Verifikasi seluruh pengguna mesin yang sudah tersinkronisasi.
     *
     * @return array{success: bool, checked: int, has_template: int, missing: int, failed: int, message: string}
     */
    public function verifyDevice(FingerprintDevice $device, ?TaskProgressReporter $reporter = null): array
    {
        $driver = $device->ingestDriver();

        if ($driver === 'connector' && ! $this->connectorService->supportsTemplateVerification()) {
            return $this->failure(
                'Connector yang berjalan belum mendukung verifikasi sidik jari. '
                .'Perbarui connector (Python di Linux atau C# di Windows) ke versi terbaru, '
                .'lalu pastikan layanan connectornya sudah dijalankan ulang.'
            );
        }

        $users = DeviceMachineUser::where('device_id', $device->id)
            ->orderBy('fingerprint_user_id')
            ->get();

        if ($users->isEmpty()) {
            return $this->failure(
                "Belum ada pengguna mesin pada {$device->name}. Jalankan Sinkronisasi Siswa terlebih dahulu."
            );
        }

        $reporter?->setTotal($users->count(), "Memeriksa {$users->count()} PIN pada {$device->name}...");

        $checked = 0;
        $hasTemplate = 0;
        $missing = 0;
        $failed = 0;
        $consecutiveFailures = 0;

        foreach ($users as $user) {
            $result = $driver === 'soap'
                ? $this->soapService->hasUserTemplate($device, $user->fingerprint_user_id)
                : $this->connectorService->checkUserTemplate($device, $user->fingerprint_user_id);

            if (! ($result['success'] ?? false)) {
                $failed++;
                $consecutiveFailures++;

                if (($result['code'] ?? 0) === -201) {
                    $device->markOffline();
                }

                $reporter?->advance(1, "PIN {$user->fingerprint_user_id}: gagal diperiksa.");

                if ($consecutiveFailures >= self::MAX_CONSECUTIVE_FAILURES) {
                    Log::warning('[FingerprintTemplateVerification] Verifikasi dihentikan', [
                        'device_id' => $device->id,
                        'checked' => $checked,
                        'failed' => $failed,
                        'message' => $result['message'] ?? null,
                    ]);

                    break;
                }

                continue;
            }

            $consecutiveFailures = 0;
            $checked++;

            if ($result['has_template']) {
                $hasTemplate++;
            } else {
                $missing++;
            }

            $user->update([
                'has_template' => $result['has_template'],
                'template_checked_at' => now(),
                'template_check_source' => $driver === 'soap' ? 'soap' : 'connector',
            ]);

            $reporter?->advance(
                1,
                "PIN {$user->fingerprint_user_id}: ".($result['has_template'] ? 'ada sidik jari.' : 'belum ada sidik jari.')
            );
        }

        if ($checked === 0) {
            return $this->failure(
                'Verifikasi sidik jari gagal: mesin tidak merespons. Coba lagi saat mesin terhubung.',
                $failed
            );
        }

        $message = "Verifikasi selesai: {$hasTemplate} PIN punya sidik jari, {$missing} PIN belum punya sidik jari.";

        if ($failed > 0) {
            $message .= " {$failed} PIN gagal diperiksa.";
        }

        return [
            'success' => true,
            'checked' => $checked,
            'has_template' => $hasTemplate,
            'missing' => $missing,
            'failed' => $failed,
            'message' => $message,
        ];
    }

    /**
     * @return array{success: bool, checked: int, has_template: int, missing: int, failed: int, message: string}
     */
    private function failure(string $message, int $failed = 0): array
    {
        return [
            'success' => false,
            'checked' => 0,
            'has_template' => 0,
            'missing' => 0,
            'failed' => $failed,
            'message' => $message,
        ];
    }
}
