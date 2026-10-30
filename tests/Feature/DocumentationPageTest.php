<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentationPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_documentation_page(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get(route('documentation.index'));

        $response->assertOk();
        $response->assertSee('Panduan Penggunaan');
        $response->assertSee(route('documentation.deployment.windows'), false);
        $response->assertSee(route('documentation.deployment.linux'), false);
    }

    public function test_operator_and_homeroom_teacher_cannot_view_documentation_page(): void
    {
        $operator = User::factory()->operator()->create();
        $wali = User::factory()->waliKelas()->create();

        $this->actingAs($operator)->get(route('documentation.index'))->assertStatus(403);
        $this->actingAs($wali)->get(route('documentation.index'))->assertStatus(403);
    }

    public function test_admin_can_preview_deployment_guide_rendered_from_markdown(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get(route('documentation.deployment'));

        $response->assertOk();
        $response->assertSee('Panduan Deployment');
        $response->assertSee('Pilih Jalur Instalasi');
        $response->assertSee('Deployment di Windows');
        $response->assertSee('Deployment di Linux');
        $response->assertSee('Belum Lengkap');
        $response->assertSee('Troubleshooting');
        $response->assertDontSee('## Bagian A');
        $response->assertSee(route('documentation.deployment.windows'), false);
        $response->assertSee(route('documentation.deployment.linux'), false);
    }

    public function test_admin_can_preview_windows_deployment_guide(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get(route('documentation.deployment.windows'));

        $response->assertOk();
        $response->assertSee('Deployment Windows');
        $response->assertSee('Yang Anda butuhkan');
        $response->assertSee('start-setup.ps1');
        $response->assertSee('SmartPresensiSupervisor');
        $response->assertSee('Membangun Paket Rilis Windows');
        $response->assertDontSee('deploy/linux/install.sh');
    }

    public function test_admin_can_preview_linux_deployment_guide(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get(route('documentation.deployment.linux'));

        $response->assertOk();
        $response->assertSee('Deployment Linux');
        $response->assertSee('deploy/linux/install.sh');
        $response->assertSee('systemctl');
        $response->assertSee('zkconnector.py');
        $response->assertSee('timedatectl');
        $response->assertDontSee('start-setup.ps1');
    }

    public function test_deployment_guides_are_admin_only(): void
    {
        $operator = User::factory()->operator()->create();
        $wali = User::factory()->waliKelas()->create();

        foreach (['documentation.deployment', 'documentation.deployment.windows', 'documentation.deployment.linux'] as $routeName) {
            $this->actingAs($operator)->get(route($routeName))->assertStatus(403);
            $this->actingAs($wali)->get(route($routeName))->assertStatus(403);
        }
    }
}
