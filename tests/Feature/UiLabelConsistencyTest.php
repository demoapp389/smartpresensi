<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UiLabelConsistencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_renders_a_single_breadcrumb(): void
    {
        $admin = User::factory()->admin()->create();

        $html = $this->actingAs($admin)
            ->get(route('attendances.index'))
            ->assertOk()
            ->getContent();

        $this->assertSame(1, substr_count($html, 'aria-label="Breadcrumb"'));
        $this->assertStringContainsString('Data Kehadiran', $html);

        $mainPosition = strpos($html, '<main');
        $breadcrumbPosition = strpos($html, 'aria-label="Breadcrumb"');

        $this->assertNotFalse($mainPosition);
        $this->assertNotFalse($breadcrumbPosition);
        $this->assertGreaterThan($mainPosition, $breadcrumbPosition);
    }

    public function test_server_paginated_table_keeps_single_per_page_dropdown(): void
    {
        $admin = User::factory()->admin()->create();

        $html = $this->actingAs($admin)
            ->get(route('audit.index'))
            ->assertOk()
            ->getContent();

        $this->assertSame(1, substr_count($html, 'name="per_page"'));
        $this->assertStringContainsString('Menampilkan', $html);
        $this->assertStringContainsString('aria-label="Jumlah baris per halaman"', $html);
    }

    public function test_datatable_page_keeps_page_length_dropdown(): void
    {
        $admin = User::factory()->admin()->create();

        $html = $this->actingAs($admin)
            ->get(route('devices.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-length-change="true"', $html);
        $this->assertSame(1, substr_count($html, 'aria-label="Breadcrumb"'));
    }

    public function test_raw_log_status_filter_has_no_duplicate_options(): void
    {
        $admin = User::factory()->admin()->create();

        $html = $this->actingAs($admin)
            ->get(route('logs.raw'))
            ->assertOk()
            ->getContent();

        $this->assertSame(1, substr_count($html, 'value="unprocessed"'));
        $this->assertSame(1, substr_count($html, 'value="failed"'));
        $this->assertStringContainsString('>Belum Diproses</option>', $html);
        $this->assertStringContainsString('>Gagal Permanen</option>', $html);
    }
}
