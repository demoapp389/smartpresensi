<?php

namespace Tests\Feature;

use App\Models\DeviceSyncLog;
use App\Models\FingerprintDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class PruneConnectorResourcesCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = storage_path('framework/testing/prune-connector');

        File::deleteDirectory($this->root);
        File::makeDirectory($this->root.'/logs', 0755, true);
        File::makeDirectory($this->root.'/connector/state', 0755, true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    public function test_it_rotates_oversized_logs_and_trims_offline_buffer(): void
    {
        $logPath = $this->root.'/logs/connector_service.log';
        File::put($logPath, implode(PHP_EOL, array_map(fn ($i) => 'log-line-'.$i, range(1, 300))).PHP_EOL);

        $bufferPath = $this->root.'/connector/offline_buffer.txt';
        File::put($bufferPath, implode(PHP_EOL, array_map(fn ($i) => 'buffer-'.$i, range(1, 300))).PHP_EOL);

        $this->artisan('fingerprint:prune-resources', [
            '--logs-dir' => $this->root.'/logs',
            '--connector-dir' => $this->root.'/connector',
            '--keep-lines' => 10,
            '--buffer-keep-lines' => 5,
            '--log-max-bytes' => 1,
            '--buffer-max-bytes' => 1,
        ])->assertExitCode(0);

        $logLines = file($logPath, FILE_IGNORE_NEW_LINES);
        $this->assertCount(10, $logLines);
        $this->assertSame('log-line-291', $logLines[0]);

        $bufferLines = file($bufferPath, FILE_IGNORE_NEW_LINES);
        $this->assertCount(5, $bufferLines);
        $this->assertSame('buffer-296', $bufferLines[0]);
    }

    public function test_it_leaves_small_logs_and_recent_state_untouched(): void
    {
        $logPath = $this->root.'/logs/connector_service.log';
        File::put($logPath, 'only-line'.PHP_EOL);

        $recent = $this->root.'/connector/state/current.json';
        File::put($recent, '{}');

        $old = $this->root.'/connector/state/stale.json';
        File::put($old, '{}');
        touch($old, now()->subDays(40)->getTimestamp());

        $this->artisan('fingerprint:prune-resources', [
            '--logs-dir' => $this->root.'/logs',
            '--connector-dir' => $this->root.'/connector',
            '--log-max-bytes' => 1024,
            '--state-keep-days' => 14,
        ])->assertExitCode(0);

        $this->assertSame('only-line'.PHP_EOL, File::get($logPath));
        $this->assertFileExists($recent);
        $this->assertFileDoesNotExist($old);
    }

    public function test_it_rotates_laravel_logs_and_deletes_stale_daily_logs(): void
    {
        $laravel = $this->root.'/logs/laravel.log';
        File::put($laravel, implode(PHP_EOL, array_map(fn ($i) => 'laravel-'.$i, range(1, 50))).PHP_EOL);

        $staleDaily = $this->root.'/logs/laravel-2026-01-01.log';
        File::put($staleDaily, 'stale'.PHP_EOL);
        touch($staleDaily, now()->subDays(30)->getTimestamp());

        $recentDaily = $this->root.'/logs/laravel-2026-11-11.log';
        File::put($recentDaily, 'recent'.PHP_EOL);

        $this->artisan('fingerprint:prune-resources', [
            '--logs-dir' => $this->root.'/logs',
            '--connector-dir' => $this->root.'/connector',
            '--keep-lines' => 10,
            '--log-max-bytes' => 1,
            '--laravel-log-keep-days' => 14,
        ])->assertExitCode(0);

        $lines = file($laravel, FILE_IGNORE_NEW_LINES);
        $this->assertCount(10, $lines);
        $this->assertSame('laravel-41', $lines[0]);

        $this->assertFileDoesNotExist($staleDaily);
        $this->assertFileExists($recentDaily);
    }

    public function test_it_prunes_buffers_and_state_inside_per_device_directories(): void
    {
        $deviceDir = $this->root.'/connector/state/gerbang';
        File::makeDirectory($deviceDir, 0755, true);

        $bufferPath = $deviceDir.'/offline_buffer.txt';
        File::put($bufferPath, implode(PHP_EOL, array_map(fn ($i) => 'buffer-'.$i, range(1, 300))).PHP_EOL);

        $staleCheckpoint = $deviceDir.'/checkpoint.txt';
        File::put($staleCheckpoint, '2026-01-01 00:00:00');
        touch($staleCheckpoint, now()->subDays(40)->getTimestamp());

        $this->artisan('fingerprint:prune-resources', [
            '--logs-dir' => $this->root.'/logs',
            '--connector-dir' => $this->root.'/connector',
            '--buffer-keep-lines' => 5,
            '--buffer-max-bytes' => 1,
            '--state-keep-days' => 14,
        ])->assertExitCode(0);

        $bufferLines = file($bufferPath, FILE_IGNORE_NEW_LINES);
        $this->assertCount(5, $bufferLines);
        $this->assertSame('buffer-296', $bufferLines[0]);

        $this->assertFileDoesNotExist($staleCheckpoint);
        $this->assertFileExists($bufferPath);
    }

    public function test_it_archives_discarded_buffer_lines_and_prunes_old_archives(): void
    {
        $bufferPath = $this->root.'/connector/offline_buffer.txt';
        File::put($bufferPath, implode(PHP_EOL, array_map(fn ($i) => 'buffer-'.$i, range(1, 300))).PHP_EOL);

        $staleArchive = $this->root.'/connector/offline_buffer.txt.discarded-20260101000000';
        File::put($staleArchive, 'arsip-lama'.PHP_EOL);
        touch($staleArchive, now()->subDays(30)->getTimestamp());

        $this->artisan('fingerprint:prune-resources', [
            '--logs-dir' => $this->root.'/logs',
            '--connector-dir' => $this->root.'/connector',
            '--buffer-keep-lines' => 5,
            '--buffer-max-bytes' => 1,
            '--buffer-archive-keep-days' => 14,
        ])->assertExitCode(0);

        $archives = File::glob($bufferPath.'.discarded-*');

        $this->assertCount(1, $archives);
        $this->assertFileDoesNotExist($staleArchive);

        $archivedLines = file($archives[0], FILE_IGNORE_NEW_LINES);
        $this->assertCount(295, $archivedLines);
        $this->assertSame('buffer-1', $archivedLines[0]);
        $this->assertSame('buffer-295', $archivedLines[294]);

        $bufferLines = file($bufferPath, FILE_IGNORE_NEW_LINES);
        $this->assertCount(5, $bufferLines);
        $this->assertSame('buffer-296', $bufferLines[0]);
    }

    public function test_it_prunes_sync_logs_older_than_retention(): void
    {
        $device = FingerprintDevice::create([
            'name' => 'X601',
            'ip' => '192.168.1.210',
            'port' => 4370,
            'comm_key' => '0',
            'timezone' => 'Asia/Jakarta',
            'status' => 'active',
            'secret_token' => 'tok_prune',
            'active' => true,
        ]);

        $old = DeviceSyncLog::create([
            'device_id' => $device->id,
            'sync_type' => 'sync_attendances',
            'status' => 'success',
            'records_count' => 1,
        ]);

        $recent = DeviceSyncLog::create([
            'device_id' => $device->id,
            'sync_type' => 'sync_attendances',
            'status' => 'success',
            'records_count' => 1,
        ]);

        DB::table('device_sync_logs')->where('id', $old->id)->update(['created_at' => now()->subDays(91)]);
        DB::table('device_sync_logs')->where('id', $recent->id)->update(['created_at' => now()->subDays(89)]);

        $this->artisan('fingerprint:prune-resources', [
            '--logs-dir' => $this->root.'/logs',
            '--connector-dir' => $this->root.'/connector',
            '--sync-log-days' => 90,
        ])->assertExitCode(0);

        $this->assertDatabaseMissing('device_sync_logs', ['id' => $old->id]);
        $this->assertDatabaseHas('device_sync_logs', ['id' => $recent->id]);
    }
}
