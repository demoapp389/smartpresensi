<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class AuditLogPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_page_renders_client_side_datatables_without_server_pagination(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get(route('audit.index'));

        $response->assertOk();

        // Data dikirim penuh ke DataTables sisi klien, bukan paginasi server.
        $response->assertViewHas('logs', fn ($logs) => $logs instanceof Collection);

        $html = $response->getContent();
        $this->assertStringContainsString('data-datatable', $html);
        $this->assertStringNotContainsString('Halaman 1 dari', $html);
    }
}
