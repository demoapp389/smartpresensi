<?php

namespace App\Services;

use App\Models\FingerprintDevice;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SystemControlService
{
    protected string $supervisorUrl;

    protected string $supervisorToken;

    protected string $heartbeatPath;

    public function __construct()
    {
        // Dibaca lewat config() agar tetap benar saat `php artisan config:cache`.
        $this->supervisorUrl = (string) config('services.supervisor.url', 'http://127.0.0.1:4372');
        // Tanpa default pabrikan: token harus datang dari .env (diisi saat instalasi).
        $this->supervisorToken = (string) config('services.supervisor.token', '');
        $this->heartbeatPath = storage_path('app/heartbeats/service_status.json');
    }

    /**
     * Dapatkan status komprehensif seluruh komponen sistem:
     * - Supervisor Windows Service
     * - C# Fingerprint Connector
     * - Laravel Queue Worker
     * - Mesin-mesin Fingerprint Solution X601 (LAN)
     * - Antrean Pesan WhatsApp
     */
    public function getSystemStatus(): array
    {
        $supervisorData = null;
        $isLiveApi = false;

        // 1. Coba hubungi Supervisor HTTP API (Port 4372)
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

        // 2. Fallback baca file heartbeat jika HTTP belum terjangkau
        $heartbeatFileTime = null;
        if (! $supervisorData && File::exists($this->heartbeatPath)) {
            try {
                $content = File::get($this->heartbeatPath);
                $decoded = json_decode($content, true);
                if (is_array($decoded)) {
                    $supervisorData = $decoded;
                    if (! empty($decoded['last_heartbeat'])) {
                        $heartbeatFileTime = Carbon::parse($decoded['last_heartbeat']);
                    }
                }
            } catch (\Throwable) {
            }
        }

        // Evaluasi Heartbeat
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

        // Status Tiap Service
        $supervisorRunning = $isLiveApi || ($isHeartbeatFresh && ($supervisorData['supervisor']['status'] ?? '') === 'running');
        $connectorRunning = $supervisorRunning && (($supervisorData['connector']['status'] ?? '') === 'running');
        $workerRunning = $supervisorRunning && (($supervisorData['worker']['status'] ?? '') === 'running');
        $schedulerStatus = (string) ($supervisorData['scheduler']['status'] ?? '');
        $schedulerEnabled = $schedulerStatus !== 'disabled';
        $schedulerRunning = $supervisorRunning && $schedulerStatus === 'running';

        // Metrik Antrean Jobs
        $pendingJobs = DB::table('jobs')->count();
        $failedJobs = DB::table('failed_jobs')->count();

        // Status Seluruh Perangkat Mesin Fingerprint X601
        $devices = FingerprintDevice::where('active', true)->get();
        $onlineDevicesCount = $devices->filter(fn (FingerprintDevice $device) => $device->displayStatus() === 'active')->count();

        // Kesimpulan Kesehatan Sistem
        $overallStatus = 'healthy';
        if (! $supervisorRunning || ! $connectorRunning || ! $workerRunning || ($schedulerEnabled && ! $schedulerRunning)) {
            $overallStatus = 'degraded';
        }
        if (! $supervisorRunning && $onlineDevicesCount === 0) {
            $overallStatus = 'offline';
        }

        return [
            'overall_status' => $overallStatus, // healthy, degraded, offline
            'is_live_api' => $isLiveApi,
            'heartbeat' => [
                'fresh' => $isHeartbeatFresh,
                'last_time' => $lastHeartbeatStr,
                'age_seconds' => $heartbeatAgeSec,
            ],
            'supervisor' => [
                'running' => $supervisorRunning,
                'pid' => $supervisorData['supervisor']['pid'] ?? null,
                'uptime_seconds' => $supervisorData['supervisor']['uptime_seconds'] ?? null,
            ],
            'connector' => [
                'running' => $connectorRunning,
                'pid' => $supervisorData['connector']['pid'] ?? null,
                'restarts' => $supervisorData['connector']['restart_count'] ?? ($supervisorData['connector']['restarts'] ?? 0),
                'started_at' => $supervisorData['connector']['started_at'] ?? null,
            ],
            'worker' => [
                'running' => $workerRunning,
                'pid' => $supervisorData['worker']['pid'] ?? null,
                'restarts' => $supervisorData['worker']['restart_count'] ?? ($supervisorData['worker']['restarts'] ?? 0),
                'started_at' => $supervisorData['worker']['started_at'] ?? null,
                'pending_jobs' => $pendingJobs,
                'failed_jobs' => $failedJobs,
            ],
            'scheduler' => [
                'enabled' => $schedulerEnabled,
                'running' => $schedulerRunning,
                'pid' => $supervisorData['scheduler']['pid'] ?? null,
                'restarts' => $supervisorData['scheduler']['restart_count'] ?? ($supervisorData['scheduler']['restarts'] ?? 0),
                'started_at' => $supervisorData['scheduler']['started_at'] ?? null,
            ],
            'devices' => [
                'total' => $devices->count(),
                'online' => $onlineDevicesCount,
                'list' => $devices,
            ],
        ];
    }

    /**
     * Minta restart service Connector C# secara aman tanpa hak administrator.
     */
    public function restartConnector(): array
    {
        return $this->sendAction('/api/restart/connector');
    }

    /**
     * Minta restart service Queue Worker Laravel secara aman tanpa hak administrator.
     */
    public function restartQueueWorker(): array
    {
        return $this->sendAction('/api/restart/worker');
    }

    /**
     * Minta restart seluruh background service.
     */
    public function restartAll(): array
    {
        return $this->sendAction('/api/restart/all');
    }

    /**
     * Minta restart Windows Service supervisor beserta seluruh proses anaknya.
     */
    public function restartSupervisor(): array
    {
        return $this->sendAction('/api/restart/supervisor');
    }

    /**
     * Ambil rekaman log aktivitas terbaru dari service tertentu (connector / worker / system).
     */
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

        // Fallback baca file log lokal jika service API tidak merespons
        $filePath = match ($service) {
            'connector' => storage_path('logs/connector_service.log'),
            'worker' => storage_path('logs/queue_worker_service.log'),
            default => storage_path('logs/laravel.log'),
        };

        if (File::exists($filePath)) {
            return $this->tailLines($filePath, 50);
        }

        return ["Belum ada log tercatat untuk service {$service}."];
    }

    /**
     * Baca sejumlah baris terakhir dari file log tanpa memuat seluruh isi ke memori.
     *
     * @return array<int, string>
     */
    protected function tailLines(string $path, int $count): array
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return [];
        }

        $buffer = '';
        $lines = [];
        $chunkSize = 8192;

        fseek($handle, 0, SEEK_END);
        $pos = ftell($handle);

        while ($pos > 0 && count($lines) <= $count) {
            $read = (int) min($chunkSize, $pos);
            $pos -= $read;
            fseek($handle, $pos);
            $buffer = fread($handle, $read).$buffer;
            $lines = preg_split("/\r\n|\n|\r/", $buffer) ?: [];
        }

        fclose($handle);

        $lines = array_values(array_filter($lines, fn (string $line) => $line !== ''));

        return array_slice($lines, -$count);
    }

    protected function sendAction(string $endpoint): array
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
