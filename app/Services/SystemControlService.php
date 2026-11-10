<?php

namespace App\Services;

use App\Services\SystemControl\LinuxSystemdStatusProvider;
use App\Services\SystemControl\SystemStatusProvider;
use App\Services\SystemControl\WindowsSupervisorStatusProvider;

/**
 * Orkestrator Pusat Kontrol Sistem.
 *
 * Driver dipilih otomatis berdasarkan sistem operasi: supervisor Windows pada
 * Windows, unit systemd pada Linux. Keduanya memakai bentuk data status yang
 * sama agar tampilan tidak bergantung pada platform.
 */
class SystemControlService
{
    private ?SystemStatusProvider $provider = null;

    public function __construct(?SystemStatusProvider $provider = null)
    {
        $this->provider = $provider;
    }

    public function platform(): string
    {
        return $this->provider()->platform();
    }

    public function platformLabel(): string
    {
        return $this->provider()->platformLabel();
    }

    public function supportsRestart(string $service): bool
    {
        return $this->provider()->supportsRestart($service);
    }

    /**
     * @return array<string, mixed>
     */
    public function getSystemStatus(): array
    {
        return $this->provider()->getStatus();
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function restartConnector(): array
    {
        return $this->provider()->restart('connector');
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function restartQueueWorker(): array
    {
        return $this->provider()->restart('worker');
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function restartAll(): array
    {
        return $this->provider()->restart('all');
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function restartSupervisor(): array
    {
        return $this->provider()->restart('supervisor');
    }

    /**
     * @return array<int, string>
     */
    public function getLogs(string $service): array
    {
        return $this->provider()->getLogs($service);
    }

    private function provider(): SystemStatusProvider
    {
        return $this->provider ??= $this->resolveProvider();
    }

    private function resolveProvider(): SystemStatusProvider
    {
        $driver = (string) config('system_control.driver', 'auto');

        if ($driver === 'auto') {
            $driver = PHP_OS_FAMILY === 'Windows' ? 'windows' : 'linux';
        }

        return $driver === 'linux'
            ? new LinuxSystemdStatusProvider
            : new WindowsSupervisorStatusProvider;
    }
}
