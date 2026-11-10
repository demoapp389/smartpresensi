<?php

namespace App\Services\SystemControl;

use Illuminate\Contracts\Process\ProcessResult as ProcessResultContract;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * Status layanan Linux melalui unit systemd dan cron scheduler.
 *
 * Pembacaan status memakai `systemctl` tanpa hak root. Aksi restart memakai
 * helper root terbatas yang dipasang oleh installer Linux.
 */
class LinuxSystemdStatusProvider extends SystemStatusProvider
{
    /**
     * @var array{worker: string, worker_long: string, connector: string, scheduler: string}
     */
    private array $units;

    private string $systemctlBinary;

    private string $sudoBinary;

    private string $restartHelper;

    private string $schedulerCronFile;

    /**
     * @var array<string, string>
     */
    private array $logPaths;

    public function __construct()
    {
        $this->units = [
            'worker' => (string) config('system_control.units.worker', 'smartpresensi-worker'),
            'worker_long' => (string) config('system_control.units.worker_long', 'smartpresensi-worker-long'),
            'connector' => (string) config('system_control.units.connector', 'solutionx601-connector'),
            'scheduler' => (string) config('system_control.units.scheduler', 'smartpresensi-scheduler'),
        ];
        $this->systemctlBinary = (string) config('system_control.systemctl_binary', 'systemctl');
        $this->sudoBinary = (string) config('system_control.sudo_binary', '/usr/bin/sudo');
        $this->restartHelper = (string) config('system_control.restart_helper', '/usr/local/sbin/smartpresensi-service');
        $this->schedulerCronFile = (string) config('system_control.scheduler_cron_file', '/etc/cron.d/smartpresensi-scheduler');
        $this->logPaths = (array) config('system_control.logs', []);
    }

    public function platform(): string
    {
        return 'linux';
    }

    public function platformLabel(): string
    {
        return 'Linux (systemd)';
    }

    public function supportsRestart(string $service): bool
    {
        return in_array($service, ['connector', 'worker', 'scheduler', 'all'], true);
    }

    public function getStatus(): array
    {
        $connector = $this->serviceState($this->units['connector']);
        $worker = $this->serviceState($this->units['worker']);
        $workerLong = $this->serviceState($this->units['worker_long']);
        $scheduler = $this->schedulerState();

        $queue = $this->queueMetrics();
        $devices = $this->deviceStatus();

        $systemctlUnavailable = $connector['status'] === 'unavailable'
            && $worker['status'] === 'unavailable'
            && $scheduler['status'] === 'unavailable';

        $connectorRunning = $connector['running'];
        $workerLongInstalled = $workerLong['status'] !== 'not_installed';
        $workerRunning = $worker['running']
            && (! $workerLongInstalled || $workerLong['running']);
        $workerStatus = $worker['status'];

        if ($workerLongInstalled && $worker['status'] !== 'unavailable' && ! $workerLong['running']) {
            $workerStatus = $workerLong['status'] === 'starting' ? 'starting' : 'stopped';
        }

        $schedulerEnabled = $scheduler['enabled'];
        $schedulerRunning = $scheduler['running'];

        $unavailableReason = $systemctlUnavailable
            ? 'systemctl tidak tersedia atau tidak dapat dijalankan oleh pengguna web.'
            : null;

        if ($systemctlUnavailable) {
            $overallStatus = 'degraded';
        } elseif ($workerRunning && $connectorRunning && (! $schedulerEnabled || $schedulerRunning)) {
            $overallStatus = 'healthy';
        } elseif (! $workerRunning && ! $connectorRunning && $devices['online'] === 0) {
            $overallStatus = 'offline';
        } else {
            $overallStatus = 'degraded';
        }

        $modeLabel = match (true) {
            $systemctlUnavailable => 'Tidak tersedia',
            $scheduler['mode'] === 'cron' => 'Systemd + Cron scheduler',
            default => 'Systemd (real-time)',
        };

        return [
            'platform' => $this->platform(),
            'platform_label' => $this->platformLabel(),
            'mode_label' => $modeLabel,
            'unavailable_reason' => $unavailableReason,
            'restart_supported' => [
                'connector' => true,
                'worker' => true,
                'scheduler' => true,
                'all' => true,
                'supervisor' => false,
            ],
            'overall_status' => $overallStatus,
            'is_live_api' => false,
            'heartbeat' => [
                'fresh' => ! $systemctlUnavailable,
                'last_time' => $systemctlUnavailable
                    ? 'Tidak dapat diperiksa'
                    : now()->format('H:i:s').' (systemd)',
                'age_seconds' => $systemctlUnavailable ? 9999 : 0,
            ],
            'supervisor' => [
                'running' => ! $systemctlUnavailable,
                'status' => $systemctlUnavailable ? 'unavailable' : 'running',
                'pid' => null,
                'uptime_seconds' => null,
            ],
            'connector' => [
                'label' => 'Python Fingerprint Connector',
                'description' => 'Membaca scan mesin fingerprint via LAN TCP/IP (connector Python)',
                'running' => $connectorRunning,
                'status' => $connector['status'],
                'pid' => $connector['pid'],
                'restarts' => $connector['restarts'],
                'started_at' => $connector['started_at'],
            ],
            'worker' => [
                'label' => 'Laravel Queue Worker',
                'description' => 'Memproses antrean notifikasi WhatsApp dan validasi nomor wali',
                'running' => $workerRunning,
                'status' => $workerStatus,
                'pid' => $worker['pid'],
                'restarts' => $worker['restarts'],
                'started_at' => $worker['started_at'],
                'pending_jobs' => $queue['pending_jobs'],
                'failed_jobs' => $queue['failed_jobs'],
                'long_running' => [
                    'installed' => $workerLongInstalled,
                    'running' => $workerLong['running'],
                    'status' => $workerLong['status'],
                    'pid' => $workerLong['pid'],
                ],
            ],
            'scheduler' => [
                'enabled' => $schedulerEnabled,
                'running' => $schedulerRunning,
                'status' => $scheduler['status'],
                'mode' => $scheduler['mode'],
                'pid' => $scheduler['pid'],
                'restarts' => $scheduler['restarts'],
                'started_at' => $scheduler['started_at'],
            ],
            'devices' => $devices,
        ];
    }

    public function restart(string $service): array
    {
        if (! $this->supportsRestart($service)) {
            return [
                'success' => false,
                'message' => 'Restart supervisor hanya tersedia pada pemasangan Windows. Gunakan systemctl pada server Linux.',
            ];
        }

        $manualCommand = $this->manualRestartCommand($service);

        if (! File::exists($this->restartHelper)) {
            return [
                'success' => false,
                'message' => "Helper restart belum terpasang di {$this->restartHelper}. Jalankan manual: {$manualCommand}",
            ];
        }

        try {
            $result = Process::timeout(20)->run([
                $this->sudoBinary,
                '-n',
                $this->restartHelper,
                'restart',
                $service,
            ]);

            if ($result->successful()) {
                return [
                    'success' => true,
                    'message' => "Layanan {$service} berhasil di-restart melalui systemd.",
                ];
            }

            $error = trim($result->errorOutput());

            return [
                'success' => false,
                'message' => 'Restart gagal. Jalankan manual: '.$manualCommand
                    .($error !== '' ? ' Pesan: '.mb_substr($error, 0, 300) : ''),
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => 'Tidak dapat menjalankan helper restart. Jalankan manual: '.$manualCommand
                    .' Pesan: '.$e->getMessage(),
            ];
        }
    }

    public function getLogs(string $service): array
    {
        $path = $this->logPaths[$service] ?? $this->logPaths['system'] ?? storage_path('logs/laravel.log');
        $lines = $this->tailLines($path);

        if ($lines === []) {
            return [
                "Belum ada log untuk service {$service}.",
                "Pastikan unit systemd menulis ke {$path} atau periksa dengan: journalctl -u {$this->unitFor($service)} -n 50 --no-pager",
            ];
        }

        return $lines;
    }

    /**
     * @return array{status: string, running: bool, pid: int|null, restarts: int, started_at: string|null}
     */
    private function serviceState(string $unit): array
    {
        $active = $this->runSystemctl(['is-active', $unit]);

        if ($active === null || $active->exitCode() === 127) {
            return $this->unavailableState();
        }

        $show = $this->runSystemctl([
            'show',
            '-p', 'LoadState',
            '-p', 'MainPID',
            '-p', 'ActiveEnterTimestamp',
            '-p', 'NRestarts',
            $unit,
        ]);

        if ($show === null || $show->exitCode() === 127) {
            return $this->unavailableState();
        }

        $properties = $this->parseProperties($show->output());
        $loadState = $properties['LoadState'] ?? '';

        if ($loadState === 'not-found') {
            return [
                'status' => 'not_installed',
                'running' => false,
                'pid' => null,
                'restarts' => 0,
                'started_at' => null,
            ];
        }

        $state = trim($active->output());

        if ($state === '') {
            $state = trim($active->errorOutput());
        }

        $status = match ($state) {
            'active' => 'running',
            'activating', 'reloading', 'deactivating' => 'starting',
            'inactive', 'failed' => 'stopped',
            default => 'unavailable',
        };

        $pid = (int) ($properties['MainPID'] ?? 0);

        return [
            'status' => $status,
            'running' => $status === 'running',
            'pid' => $pid > 0 ? $pid : null,
            'restarts' => (int) ($properties['NRestarts'] ?? 0),
            'started_at' => $this->formatTimestamp($properties['ActiveEnterTimestamp'] ?? null),
        ];
    }

    /**
     * @return array{enabled: bool, running: bool, status: string, mode: string, pid: int|null, restarts: int, started_at: string|null}
     */
    private function schedulerState(): array
    {
        $unitState = $this->serviceState($this->units['scheduler']);

        if (! in_array($unitState['status'], ['unavailable', 'not_installed'], true)) {
            return [
                'enabled' => true,
                'running' => $unitState['running'],
                'status' => $unitState['status'],
                'mode' => 'systemd',
                'pid' => $unitState['pid'],
                'restarts' => $unitState['restarts'],
                'started_at' => $unitState['started_at'],
            ];
        }

        if (File::exists($this->schedulerCronFile)) {
            return [
                'enabled' => true,
                'running' => true,
                'status' => 'scheduled',
                'mode' => 'cron',
                'pid' => null,
                'restarts' => 0,
                'started_at' => null,
            ];
        }

        return [
            'enabled' => false,
            'running' => false,
            'status' => $unitState['status'] === 'unavailable' ? 'unavailable' : 'disabled',
            'mode' => 'none',
            'pid' => null,
            'restarts' => 0,
            'started_at' => null,
        ];
    }

    private function runSystemctl(array $arguments): ?ProcessResultContract
    {
        try {
            return Process::timeout(3)->run(array_merge([$this->systemctlBinary], $arguments));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array{status: string, running: bool, pid: int|null, restarts: int, started_at: string|null}
     */
    private function unavailableState(): array
    {
        return [
            'status' => 'unavailable',
            'running' => false,
            'pid' => null,
            'restarts' => 0,
            'started_at' => null,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function parseProperties(string $output): array
    {
        $properties = [];

        foreach (preg_split('/\r\n|\n|\r/', trim($output)) ?: [] as $line) {
            if (! str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $properties[trim($key)] = trim($value);
        }

        return $properties;
    }

    private function formatTimestamp(?string $value): ?string
    {
        if ($value === null || trim($value) === '' || str_starts_with($value, 'n/a')) {
            return null;
        }

        return $value;
    }

    private function manualRestartCommand(string $service): string
    {
        return match ($service) {
            'worker' => 'sudo systemctl restart '.$this->units['worker'].' '.$this->units['worker_long'],
            'connector' => 'sudo systemctl restart '.$this->units['connector'],
            'scheduler' => 'sudo systemctl restart '.$this->units['scheduler'],
            'all' => 'sudo systemctl restart '.$this->units['worker'].' '.$this->units['worker_long'].' '.$this->units['connector'].' '.$this->units['scheduler'],
            default => 'sudo systemctl restart '.$this->units['worker'],
        };
    }

    private function unitFor(string $service): string
    {
        return $this->units[$service] ?? $this->units['worker'];
    }
}
