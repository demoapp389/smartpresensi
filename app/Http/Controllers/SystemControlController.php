<?php

namespace App\Http\Controllers;

use App\Concerns\LogsAuditTrail;
use App\Jobs\SyncAllDevicesAttendance;
use App\Models\FingerprintDevice;
use App\Services\SystemControlService;
use App\Support\TaskProgressReporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class SystemControlController extends Controller
{
    use LogsAuditTrail;

    /**
     * Tampilkan Halaman Pusat Kontrol Sistem.
     */
    public function index(SystemControlService $controlService): View
    {
        $status = $controlService->getSystemStatus();
        $logsConnector = $controlService->getLogs('connector');
        $logsWorker = $controlService->getLogs('worker');

        return view('system.control', compact('status', 'logsConnector', 'logsWorker'));
    }

    /**
     * Minta restart service Connector Solution X601 melalui supervisor aman.
     */
    public function restartConnector(Request $request, SystemControlService $controlService): RedirectResponse
    {
        $result = $controlService->restartConnector();

        $this->logAudit('restart_connector_service', 'SystemProcess', null, null, $result);

        if ($result['success']) {
            return back()->with('success', $result['message']);
        }

        return back()->with('error', $result['message']);
    }

    /**
     * Minta restart service Queue Worker Laravel melalui supervisor aman.
     */
    public function restartWorker(Request $request, SystemControlService $controlService): RedirectResponse
    {
        $result = $controlService->restartQueueWorker();

        $this->logAudit('restart_queue_worker_service', 'SystemProcess', null, null, $result);

        if ($result['success']) {
            return back()->with('success', $result['message']);
        }

        return back()->with('error', $result['message']);
    }

    /**
     * Minta restart seluruh layanan latar belakang (Connector & Worker).
     */
    public function restartAll(Request $request, SystemControlService $controlService): RedirectResponse
    {
        $result = $controlService->restartAll();

        $this->logAudit('restart_all_background_services', 'SystemProcess', null, null, $result);

        if ($result['success']) {
            return back()->with('success', $result['message']);
        }

        return back()->with('error', $result['message']);
    }

    /**
     * Minta restart Windows Service supervisor melalui helper terpisah.
     */
    public function restartSupervisor(Request $request, SystemControlService $controlService): RedirectResponse
    {
        $result = $controlService->restartSupervisor();

        $this->logAudit('restart_supervisor_service', 'SystemProcess', null, null, $result);

        if ($result['success']) {
            return back()->with('success', $result['message']);
        }

        return back()->with('error', $result['message']);
    }

    /**
     * Jalankan sinkronisasi absensi massal ke seluruh terminal mesin aktif.
     */
    public function syncAllDevices(): RedirectResponse|JsonResponse
    {
        if (! FingerprintDevice::where('active', true)->exists()) {
            return back()->with('error', 'Tidak ada mesin fingerprint aktif yang terdaftar.');
        }

        $reporter = TaskProgressReporter::start(
            'devices.sync-all',
            'Sinkronisasi Semua Mesin',
            0,
            Auth::id()
        );

        SyncAllDevicesAttendance::dispatch(Auth::id(), $reporter->uuid());

        if (request()->expectsJson()) {
            return response()->json([
                'uuid' => $reporter->uuid(),
                'progress_token' => $reporter->signature(),
                'total' => 0,
                'message' => 'Sinkronisasi semua mesin sedang berjalan di latar belakang.',
            ], 202);
        }

        return back()->with('success', 'Sinkronisasi semua mesin sedang berjalan di latar belakang. Progres dapat dipantau.');
    }

    /**
     * Endpoint API JSON untuk AJAX refresh log di dashboard.
     */
    public function fetchLogs(Request $request, SystemControlService $controlService): JsonResponse
    {
        $service = $request->input('service', 'connector');
        $logs = $controlService->getLogs($service);

        return response()->json([
            'service' => $service,
            'logs' => $logs,
            'timestamp' => now()->format('H:i:s'),
        ]);
    }
}
