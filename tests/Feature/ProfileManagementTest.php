<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProfileManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_view_profile_page(): void
    {
        $response = $this->get(route('profile.edit'));
        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_user_can_view_profile_page(): void
    {
        $user = User::factory()->create([
            'name' => 'Budi Santoso',
            'email' => 'budi@sekolah.sch.id',
            'role' => 'operator',
        ]);

        $response = $this->actingAs($user)->get(route('profile.edit'));

        $response->assertStatus(200);
        $response->assertSee('Profil Pengguna');
        $response->assertSee('Budi Santoso');
        $response->assertSee('budi@sekolah.sch.id');
        $response->assertSee('Operator Absensi');
    }

    public function test_topbar_contains_profile_link_and_logout_button(): void
    {
        $user = User::factory()->create(['name' => 'Ahmad Dahlan']);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertStatus(200);
        $response->assertSee(route('profile.edit'));
        $response->assertSee(route('logout'));
        $response->assertSee('Profil Saya');
    }

    public function test_user_can_update_name_and_email(): void
    {
        $user = User::factory()->create([
            'name' => 'Nama Lama',
            'email' => 'lama@sekolah.sch.id',
        ]);

        $response = $this->actingAs($user)->put(route('profile.update'), [
            'name' => 'Nama Baru',
            'email' => 'baru@sekolah.sch.id',
        ]);

        $response->assertRedirect(route('profile.edit'));
        $response->assertSessionHas('success');

        $user->refresh();
        $this->assertSame('Nama Baru', $user->name);
        $this->assertSame('baru@sekolah.sch.id', $user->email);

        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $user->id,
            'action' => 'update_profile',
            'entity_type' => 'User',
            'entity_id' => $user->id,
        ]);
    }

    public function test_user_cannot_update_email_to_already_used_email(): void
    {
        User::factory()->create(['email' => 'other@sekolah.sch.id']);
        $user = User::factory()->create(['email' => 'my@sekolah.sch.id']);

        $response = $this->actingAs($user)->put(route('profile.update'), [
            'name' => 'My Name',
            'email' => 'other@sekolah.sch.id',
        ]);

        $response->assertSessionHasErrors(['email']);
        $user->refresh();
        $this->assertSame('my@sekolah.sch.id', $user->email);
    }

    public function test_user_can_update_password_with_correct_current_password(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('oldpassword123'),
        ]);

        $response = $this->actingAs($user)->put(route('profile.update'), [
            'name' => $user->name,
            'email' => $user->email,
            'current_password' => 'oldpassword123',
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ]);

        $response->assertRedirect(route('profile.edit'));
        $response->assertSessionHas('success');

        $user->refresh();
        $this->assertTrue(Hash::check('newpassword123', $user->password));
    }

    public function test_user_cannot_update_password_with_incorrect_current_password(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('correctpassword'),
        ]);

        $response = $this->actingAs($user)->put(route('profile.update'), [
            'name' => $user->name,
            'email' => $user->email,
            'current_password' => 'wrongpassword',
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ]);

        $response->assertSessionHasErrors(['current_password']);
        $user->refresh();
        $this->assertTrue(Hash::check('correctpassword', $user->password));
    }
}
