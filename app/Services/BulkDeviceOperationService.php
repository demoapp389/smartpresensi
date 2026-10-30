<?php

namespace App\Services;

use App\Concerns\LogsAuditTrail;
use App\Jobs\BulkDeviceOperation;
use App\Models\DeviceUser;
use App\Models\FingerprintDevice;
use App\Support\TaskProgressReporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Pengantrean operasi massal terhadap mesin (kirim nama, backup sidik jari, kloning).
 *
 * Dipisahkan dari controller agar orkestrasi job + progress bar + audit aksi
 * berisiko berada di satu tempat dan dapat diuji tanpa HTTP.
 */
class BulkDeviceOperationService
{
    use LogsAuditTrail;

    /**
     * Apakah mesin sudah punya data siswa terpetakan.
     */
    public function hasMappings(FingerprintDevice $device): bool
    {
        return DeviceUser::where('device_id', $device->id)->exists();
    }

    /**
     * Antrekan operasi massal ke worker queue dan kembalikan UUID untuk dipantau progress bar.
     */
    public function dispatch(
        string $operation,
        FingerprintDevice $device,
        ?int $targetDeviceId,
        string $label
    ): RedirectResponse|JsonResponse {
        if ($response = $this->offlineResponse($device)) {
            return $response;
        }

        if ($operation === BulkDeviceOperation::OP_CLONE && $targetDeviceId !== null) {
            $target = FingerprintDevice::find($targetDeviceId);

            if ($target && ($response = $this->offlineResponse($target, 'Mesin target'))) {
                return $response;
            }
        }

        $reporter = TaskProgressReporter::start(
            'devices.'.$operation,
            $label,
            0,
            Auth::id()
        );

        BulkDeviceOperation::dispatch(
            $operation,
            $device->id,
            $targetDeviceId,
            Auth::id(),
            $reporter->uuid()
        );

        $this->logRiskyAction($device, 'dispatch_'.$operation, [
            'target_device_id' => $targetDeviceId,
            'task_uuid' => $reporter->uuid(),
        ]);

        if (request()->expectsJson()) {
            return response()->json([
                'uuid' => $reporter->uuid(),
                'progress_token' => $reporter->signature(),
                'total' => 0,
                'message' => $label.' sedang berjalan di latar belakang.',
            ], 202);
        }

        return redirect()->route('devices.show', $device)
            ->with('success', $label.' sedang berjalan di latar belakang. Progres dapat dipantau.');
    }

    private function offlineResponse(
        FingerprintDevice $device,
        string $subject = 'Mesin'
    ): RedirectResponse|JsonResponse|null {
        $message = $device->offlineActionMessage($subject);

        if ($message === null) {
            return null;
        }

        if (request()->expectsJson()) {
            return response()->json(['message' => $message], 422);
        }

        return redirect()->route('devices.show', $device)->with('error', $message);
    }

    /**
     * Catat aksi berisiko terhadap mesin ke audit log.
     *
     * @param  array<string, mixed>  $context
     */
    protected function logRiskyAction(FingerprintDevice $device, string $action, array $context = []): void
    {
        $this->logAudit($action, 'FingerprintDevice', $device->id, null, ['device' => $device->name] + $context);
    }
}
