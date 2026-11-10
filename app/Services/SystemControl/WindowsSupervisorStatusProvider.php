<?php

namespace App\Services\SystemControl;

use Carbon\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/**
 * Status layanan melalui supervisor Windows (HTTP API port 4372 atau file heartbeat).
 */
class WindowsSupervisorStatusProvider extends SystemStatusProvider
{
    private string $supervisorUrl;

    private string $supervisorToken;

    private string $heartbeatPath;

    public function __construct()
    {
        $this->supervisorUrl = (string) config('services.supervisor.url', 'http://127.0.0.1:4372');
        $this->supervisorToken = (string) config('services.supervisor.token', '');
        $this->heartbeatPath = storage_path('app/heartbeats/service_status.json');
    }

    public function platform(): string
    {
        return 'windows';
    }

    public function platformLabel(): string
    {
        return 'Windows (Supervisor)';
    }

    public function supportsRestart(string $service): bool
    {
        return in_array($service, ['connector', 'worker', 'scheduler', 'all', 'supervisor'], true);
    }

    public function getStatus(): array
    {
        $supervisorData = null;
        $isLiveApi = false;

        try {
            $response = Http::withHeaders([
                'X-Supervisor-Token' => $this->supervisorToken,
            ])->timeout(1)->connectTimeout(1)->get(rtrim($this->supervisorUrl, '/').'/api/status');

            if ($response->successful()) {
                $supervisorData = $response->json();
                $isLiveApi = true;
            }
        } catch (\Throwable) {
            // API belum aktif / tidak merespons, fallback ke heartbeat file
        }

        $heartbeatFileTime = null;
        if (! $supervisorData && File::exists($this->heartbeatPath)) {
            try {
                $decoded = json_decode(File::get($this->heartbeatPath), true);

                if (is_array($decoded)) {
                    $supervisorData = $decoded;
                    if (! empty($decoded['last_heartbeat'])) {
                        $heartbeatFileTime = Carbon::parse($decoded['last_heartbeat']);
                    }
                }
            } catch (\Throwable) {
            }
        }

        $now = now();
        $isHeartbeatFresh = false;
        $lastHeartbeatStr = 'Tidak terdeteksi';
        $heartbeatAgeSec = 9999;

        if ($isLiveApi) {
            $isHeartbeatFresh = true;
            $lastHeartbeatStr = $now->format('H:i:s').' (Real-Time)';
            $heartbeatAgeSec = 0;
        } elseif ($heartbeatFileTime) {
            $heartbeatAgeSec = $now->diffInSeconds($heartbeatFileTime);
            $lastHeartbeatStr = $heartbeatFileTime->format('d/m/Y H:i:s')." ({$heartbeatAgeSec} detik lalu)";
            $isHeartbeatFresh = $heartbeatAgeSec <= 30;
        }

        $supervisorRunning = $isLiveApi
            || ($isHeartbeatFresh && ($supervisorData['supervisor']['status'] ?? '') === 'running');
        $connectorRunning = $supervisorRunning && (($supervisorData['connector']['status'] ?? '') === 'running');
        $workerRunning = $supervisorRunning && (($supervisorData['worker']['status'] ?? '') === 'running');
        $schedulerStatus = (string) ($supervisorData['scheduler']['status'] ?? '');
        $schedulerEnabled = $schedulerStatus !== 'disabled';
        $schedulerRunning = $supervisorRunning && $schedulerStatus === 'running';

        $queue = $this->queueMetrics();
        $devices = $this->deviceStatus();

        $overallStatus = 'healthy';
        if (! $supervisorRunning || ! $connectorRunning || ! $workerRunning || ($schedulerEnabled && ! $schedulerRunning)) {
            $overallStatus = 'degraded';
        }
        if (! $supervisorRunning && $devices['online'] === 0) {
            $overallStatus = 'offline';
        }

        return [
            'platform' => $this->platform(),
            'platform_label' => $this->platformLabel(),
            'mode_label' => $isLiveApi ? 'Real-Time API (Port 4372)' : 'File Heartbeat Observer',
            'unavailable_reason' => null,
            'restart_supported' => [
                'connector' => true,
                'worker' => true,
                'scheduler' => true,
                'all' => true,
                'supervisor' => true,
            ],
            'overall_status' => $overallStatus,
            'is_live_api' => $isLiveApi,
            'heartbeat' => [
                'fresh' => $isHeartbeatFresh,
                'last_time' => $lastHeartbeatStr,
                'age_seconds' => $heartbeatAgeSec,
            ],
            'supervisor' => [
                'running' => $supervisorRunning,
                'status' => $supervisorRunning ? 'running' : 'stopped',
                'pid' => $supervisorData['supervisor']['pid'] ?? null,
                'uptime_seconds' => $supervisorData['supervisor']['uptime_seconds'] ?? null,
            ],
            'connector' => [
                'label' => 'C# Fingerprint Connector',
                'description' => 'Membaca scan mesin fingerprint via LAN TCP/IP & ZKEM SDK',
                'running' => $connectorRunning,
                'status' => $connectorRunning ? 'running' : 'stopped',
                'pid' => $supervisorData['connector']['pid'] ?? null,
                'restarts' => $supervisorData['connector']['restart_count'] ?? ($supervisorData['connector']['restarts'] ?? 0),
                'started_at' => $supervisorData['connector']['started_at'] ?? null,
            ],
            'worker' => [
                'label' => 'Laravel Queue Worker',
                'description' => 'Memproses antrean kirim notifikasi WhatsApp ke wali siswa',
                'running' => $workerRunning,
                'status' => $workerRunning ? 'running' : 'stopped',
                'pid' => $supervisorData['worker']['pid'] ?? null,
                'restarts' => $supervisorData['worker']['restart_count'] ?? ($supervisorData['worker']['restarts'] ?? 0),
                'started_at' => $supervisorData['worker']['started_at'] ?? null,
                'pending_jobs' => $queue['pending_jobs'],
                'failed_jobs' => $queue['failed_jobs'],
            ],
            'scheduler' => [
                'enabled' => $schedulerEnabled,
                'running' => $schedulerRunning,
                'status' => ! $schedulerEnabled ? 'disabled' : ($schedulerRunning ? 'running' : 'stopped'),
                'mode' => 'supervisor',
                'pid' => $supervisorData['scheduler']['pid'] ?? null,
                'restarts' => $supervisorData['scheduler']['restart_count'] ?? ($supervisorData['scheduler']['restarts'] ?? 0),
                'started_at' => $supervisorData['scheduler']['started_at'] ?? null,
            ],
            'devices' => $devices,
        ];
    }

    public function restart(string $service): array
    {
        $endpoint = match ($service) {
            'connector' => '/api/restart/connector',
            'worker' => '/api/restart/worker',
            'scheduler' => '/api/restart/all',
            'all' => '/api/restart/all',
            'supervisor' => '/api/restart/supervisor',
            default => null,
        };

        if ($endpoint === null) {
            return [
                'success' => false,
                'message' => "Layanan {$service} tidak dikenal.",
            ];
        }

        return $this->sendAction($endpoint);
    }

    public function getLogs(string $service): array
    {
        if ($service === 'connector' || $service === 'worker') {
            try {
                $res = Http::withHeaders([
                    'X-Supervisor-Token' => $this->supervisorToken,
                ])->timeout(1)->connectTimeout(1)->get(rtrim($this->supervisorUrl, '/')."/api/logs/{$service}");

                if ($res->successful()) {
                    return $res->json('logs') ?? [];
                }
            } catch (\Throwable) {
            }
        }

        $filePath = match ($service) {
            'connector' => storage_path('logs/connector_service.log'),
            'worker' => storage_path('logs/queue_worker_service.log'),
            default => storage_path('logs/laravel.log'),
        };

        return $this->readFirstAvailableLog([$filePath], $service);
    }

    /**
     * @return array{success: bool, message: string}
     */
    private function sendAction(string $endpoint): array
    {
        try {
            $res = Http::withHeaders([
                'X-Supervisor-Token' => $this->supervisorToken,
            ])->timeout(8)->post(rtrim($this->supervisorUrl, '/').$endpoint);

            if ($res->successful()) {
                return [
                    'success' => true,
                    'message' => $res->json('message') ?? 'Aksi berhasil dieksekusi.',
                ];
            }

            return [
                'success' => false,
                'message' => $res->json('error') ?? "Gagal mengeksekusi aksi (HTTP {$res->status()})",
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => "Tidak dapat menghubungi Supervisor Service di {$this->supervisorUrl}. Pastikan Windows Service atau run_supervisor_manual.bat aktif. Pesan: ".$e->getMessage(),
            ];
        }
    }
}
