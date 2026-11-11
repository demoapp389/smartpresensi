<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class DatabaseBackupCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = storage_path('framework/testing/db-backup');

        File::deleteDirectory($this->directory);
        File::ensureDirectoryExists($this->directory, 0755);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    public function test_it_writes_sql_dump_with_matching_checksum(): void
    {
        $this->artisan('db:backup', [
            '--path' => $this->directory,
            '--keep-days' => 0,
        ])->assertExitCode(0);

        $dumps = File::glob($this->directory.DIRECTORY_SEPARATOR.'backup_*.sql');

        $this->assertCount(1, $dumps);

        $path = $dumps[0];

        $this->assertFileExists($path.'.sha256');
        $this->assertGreaterThan(0, File::size($path));
        $this->assertStringContainsString('CREATE TABLE', File::get($path));

        $checksum = hash_file('sha256', $path);
        $storedChecksum = explode(' ', trim(File::get($path.'.sha256')))[0];

        $this->assertSame($checksum, $storedChecksum);
    }

    public function test_it_prunes_backups_older_than_retention(): void
    {
        $old = $this->directory.DIRECTORY_SEPARATOR.'backup_lama.sql';
        File::put($old, '-- dump lama'.PHP_EOL);
        File::put($old.'.sha256', 'deadbeef  backup_lama.sql'.PHP_EOL);
        touch($old, now()->subDays(20)->getTimestamp());
        touch($old.'.sha256', now()->subDays(20)->getTimestamp());

        $this->artisan('db:backup', [
            '--path' => $this->directory,
            '--keep-days' => 14,
        ])->assertExitCode(0);

        $this->assertFileDoesNotExist($old);
        $this->assertFileDoesNotExist($old.'.sha256');
        $this->assertCount(1, File::glob($this->directory.DIRECTORY_SEPARATOR.'backup_*.sql'));
    }
}
