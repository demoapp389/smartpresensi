<?php

namespace Tests\Feature;

use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $operator;

    protected SchoolClass $classA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create([
            'name' => 'Admin Sekolah',
            'email' => 'admin@sekolah.sch.id',
        ]);

        $this->operator = User::factory()->operator()->create([
            'name' => 'Operator Sekolah',
            'email' => 'operator@sekolah.sch.id',
        ]);

        $this->classA = SchoolClass::create([
            'name' => 'VII-A',
            'academic_year' => '2026/2027',
        ]);
    }

    public function test_admin_can_view_user_management_page(): void
    {
        $response = $this->actingAs($this->admin)->get(route('users.index'));

        $response->assertStatus(200);
        $response->assertSee('Manajemen Pengguna Sistem');
        $response->assertSee('Admin Sekolah');
        $response->assertSee('Operator Sekolah');
    }

    public function test_user_management_page_renders_client_side_datatables_without_server_pagination(): void
    {
        $response = $this->actingAs($this->admin)->get(route('users.index'));

        $response->assertOk();

        // Data dikirim penuh ke DataTables sisi klien, bukan paginasi server.
        $response->assertViewHas('users', fn ($users) => $users instanceof Collection);

        $html = $response->getContent();
        $this->assertStringContainsString('data-datatable', $html);
        $this->assertStringNotContainsString('Halaman 1 dari', $html);
    }

    public function test_admin_can_create_homeroom_teacher_with_assigned_class(): void
    {
        $response = $this->actingAs($this->admin)->post(route('users.store'), [
            'name' => 'Budi Santoso, S.Pd',
            'email' => 'budi.wali@sekolah.sch.id',
            'password' => 'password123',
            'role' => 'wali_kelas',
            'class_id' => $this->classA->id,
            'is_active' => true,
        ]);

        $response->assertRedirect(route('users.index'));
        $this->assertDatabaseHas('users', [
            'email' => 'budi.wali@sekolah.sch.id',
            'role' => 'wali_kelas',
            'class_id' => $this->classA->id,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $this->admin->id,
            'action' => 'create_user',
            'entity_type' => 'User',
        ]);
    }

    public function test_validation_fails_if_homeroom_teacher_has_no_class(): void
    {
        $response = $this->actingAs($this->admin)->post(route('users.store'), [
            'name' => 'Guru Tanpa Kelas',
            'email' => 'tanpa.kelas@sekolah.sch.id',
            'password' => 'password123',
            'role' => 'wali_kelas',
            'class_id' => null,
        ]);

        $response->assertSessionHasErrors(['class_id']);
        $this->assertDatabaseMissing('users', [
            'email' => 'tanpa.kelas@sekolah.sch.id',
        ]);
    }

    public function test_admin_can_update_user_role_and_class(): void
    {
        $user = User::factory()->operator()->create([
            'name' => 'User Lama',
            'email' => 'lama@sekolah.sch.id',
        ]);

        $response = $this->actingAs($this->admin)->put(route('users.update', $user), [
            'name' => 'User Baru Diperbarui',
            'email' => 'lama@sekolah.sch.id',
            'role' => 'wali_kelas',
            'class_id' => $this->classA->id,
            'is_active' => true,
        ]);

        $response->assertRedirect(route('users.index'));
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'User Baru Diperbarui',
            'role' => 'wali_kelas',
            'class_id' => $this->classA->id,
        ]);
    }

    public function test_admin_cannot_delete_own_account(): void
    {
        $response = $this->actingAs($this->admin)->delete(route('users.destroy', $this->admin));

        $response->assertRedirect(route('users.index'));
        $response->assertSessionHas('error');
        $this->assertDatabaseHas('users', [
            'id' => $this->admin->id,
        ]);
    }

    public function test_admin_can_delete_another_user(): void
    {
        $user = User::factory()->operator()->create([
            'name' => 'Akan Dihapus',
            'email' => 'hapus@sekolah.sch.id',
        ]);

        $response = $this->actingAs($this->admin)->delete(route('users.destroy', $user));

        $response->assertRedirect(route('users.index'));
        $this->assertDatabaseMissing('users', [
            'id' => $user->id,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $this->admin->id,
            'action' => 'delete_user',
        ]);
    }

    public function test_operator_and_homeroom_teacher_cannot_access_user_management(): void
    {
        $wali = User::factory()->waliKelas($this->classA)->create();

        // Operator ditolak
        $this->actingAs($this->operator)->get(route('users.index'))->assertStatus(403);
        $this->actingAs($this->operator)->post(route('users.store'), [])->assertStatus(403);

        // Wali Kelas ditolak
        $this->actingAs($wali)->get(route('users.index'))->assertStatus(403);
        $this->actingAs($wali)->post(route('users.store'), [])->assertStatus(403);
    }
}
