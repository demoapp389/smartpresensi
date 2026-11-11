<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Tests\TestCase;

class AuditLogPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_page_uses_server_side_pagination(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get(route('audit.index'));

        $response->assertOk();
        $response->assertViewHas('logs', fn ($logs) => $logs instanceof LengthAwarePaginator);

        $html = $response->getContent();
        $this->assertStringNotContainsString('data-datatable', $html);
        $this->assertStringContainsString('Menampilkan', $html);
    }

    public function test_audit_page_hides_system_actions_by_default(): void
    {
        $admin = User::factory()->admin()->create();

        AuditLog::create([
            'actor_id' => null,
            'action' => 'auto_alpa_batch',
            'entity_type' => 'Attendance',
            'entity_id' => 1,
            'created_at' => now(),
        ]);

        AuditLog::create([
            'actor_id' => $admin->id,
            'action' => 'update_student',
            'entity_type' => 'Student',
            'entity_id' => 2,
            'created_at' => now(),
        ]);

        $default = $this->actingAs($admin)->get(route('audit.index'));
        $default->assertOk();
        $default->assertViewHas('logs', fn (LengthAwarePaginator $logs): bool => $logs->total() === 1
            && $logs->first()?->action === 'update_student');

        $withSystem = $this->actingAs($admin)->get(route('audit.index', ['show_system' => 1]));
        $withSystem->assertOk();
        $withSystem->assertViewHas('logs', fn (LengthAwarePaginator $logs): bool => $logs->total() === 2);
    }

    public function test_audit_page_filters_by_action_and_date_range(): void
    {
        $admin = User::factory()->admin()->create();

        AuditLog::create([
            'actor_id' => $admin->id,
            'action' => 'update_student',
            'entity_type' => 'Student',
            'entity_id' => 1,
            'created_at' => '2026-11-01 08:00:00',
        ]);

        AuditLog::create([
            'actor_id' => $admin->id,
            'action' => 'delete_student',
            'entity_type' => 'Student',
            'entity_id' => 2,
            'created_at' => '2026-11-05 08:00:00',
        ]);

        $response = $this->actingAs($admin)->get(route('audit.index', [
            'action' => 'update_student',
            'date_from' => '2026-11-01',
            'date_to' => '2026-11-02',
        ]));

        $response->assertOk();
        $response->assertViewHas('logs', function (LengthAwarePaginator $logs): bool {
            return $logs->total() === 1
                && $logs->first()?->action === 'update_student';
        });
    }
}
