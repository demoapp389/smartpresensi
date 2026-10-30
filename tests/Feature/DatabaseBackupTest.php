<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatabaseBackupTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->operator = User::factory()->operator()->create();
    }

    public function test_guest_cannot_download_database_backup(): void
    {
        $response = $this->get(route('system.backup.database'));

        $response->assertRedirect(route('login'));
    }

    public function test_operator_cannot_download_database_backup_and_gets_403(): void
    {
        $response = $this->actingAs($this->operator)->get(route('system.backup.database'));

        $response->assertStatus(403);
    }

    public function test_admin_can_download_sql_backup_stream(): void
    {
        $response = $this->actingAs($this->admin)->get(route('system.backup.database'));

        $response->assertStatus(200);
        $response->assertHeader('content-type', 'application/sql; charset=utf-8');

        $contentDisposition = $response->headers->get('content-disposition');
        $this->assertNotNull($contentDisposition);
        $this->assertStringContainsString('attachment;', $contentDisposition);
        $this->assertStringContainsString('.sql', $contentDisposition);

        // Verifikasi stream memuat konten SQL
        $content = $response->streamedContent();
        $this->assertStringContainsString('Smart Presensi Siswa - Database Backup Dump', $content);
        $this->assertStringContainsString('users', $content);
        $this->assertStringContainsString('INSERT INTO', $content);
        $this->assertStringContainsString($this->admin->email, $content);
    }

    public function test_backup_masks_sensitive_columns_and_excludes_session_tables(): void
    {
        $response = $this->actingAs($this->admin)->get(route('system.backup.database'));
        $response->assertStatus(200);

        $content = $response->streamedContent();

        // Password hash & token perangkat tidak boleh ikut ter-dump (nilainya diganti NULL).
        $this->assertStringNotContainsString($this->admin->password, $content);

        // Tabel sesi/token dikecualikan dari dump (tidak ada CREATE TABLE-nya).
        $this->assertStringNotContainsString('CREATE TABLE "sessions"', $content);
        $this->assertStringNotContainsString('CREATE TABLE "password_reset_tokens"', $content);
        $this->assertStringNotContainsString('CREATE TABLE "personal_access_tokens"', $content);
    }

    public function test_admin_backup_action_is_recorded_in_audit_log(): void
    {
        $response = $this->actingAs($this->admin)->get(route('system.backup.database'));
        $response->streamedContent(); // Trigger callback execution

        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $this->admin->id,
            'action' => 'backup_database',
            'entity_type' => 'Database',
        ]);

        $log = AuditLog::where('action', 'backup_database')->first();
        $this->assertNotNull($log);
        $this->assertArrayHasKey('filename', $log->after);
        $this->assertArrayHasKey('tables_count', $log->after);
    }

    public function test_dashboard_does_not_display_backup_button(): void
    {
        // Admin tidak melihat tombol backup di dashboard
        $responseAdmin = $this->actingAs($this->admin)->get(route('dashboard'));
        $responseAdmin->assertStatus(200);
        $responseAdmin->assertDontSee('Backup Database (.sql)');

        // Operator tidak melihat tombol backup di UI
        $responseOperator = $this->actingAs($this->operator)->get(route('dashboard'));
        $responseOperator->assertStatus(200);
        $responseOperator->assertDontSee('Backup Database (.sql)');
    }
}
