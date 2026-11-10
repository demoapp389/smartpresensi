<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_can_open_forgot_password_form(): void
    {
        $this->get(route('password.request'))
            ->assertOk()
            ->assertSee('Lupa Kata Sandi');
    }

    public function test_active_user_receives_reset_link_notification(): void
    {
        Notification::fake();

        $user = User::factory()->admin()->create(['is_active' => true]);

        $this->post(route('password.email'), ['email' => $user->email])
            ->assertRedirect()
            ->assertSessionHas('status')
            ->assertSessionHasNoErrors();

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_unknown_email_gets_neutral_response_without_notification(): void
    {
        Notification::fake();

        $this->post(route('password.email'), ['email' => 'tidak-ada@sekolah.sch.id'])
            ->assertRedirect()
            ->assertSessionHas('status')
            ->assertSessionHasNoErrors();

        Notification::assertNothingSent();
    }

    public function test_inactive_user_does_not_receive_reset_link(): void
    {
        Notification::fake();

        $user = User::factory()->admin()->create(['is_active' => false]);

        $this->post(route('password.email'), ['email' => $user->email])
            ->assertRedirect()
            ->assertSessionHas('status')
            ->assertSessionHasNoErrors();

        Notification::assertNotSentTo($user, ResetPassword::class);
    }

    public function test_user_can_reset_password_with_valid_token(): void
    {
        $user = User::factory()->admin()->create([
            'is_active' => true,
            'password' => 'password-lama',
        ]);
        $token = Password::createToken($user);

        $this->post(route('password.store'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'password-baru-123',
            'password_confirmation' => 'password-baru-123',
        ])->assertRedirect(route('login'));

        $this->assertTrue(Hash::check('password-baru-123', $user->fresh()->password));
        $this->assertFalse(Hash::check('password-lama', $user->fresh()->password));
    }

    public function test_invalid_token_does_not_change_password(): void
    {
        $user = User::factory()->admin()->create([
            'is_active' => true,
            'password' => 'password-lama',
        ]);

        $this->post(route('password.store'), [
            'token' => 'token-palsu',
            'email' => $user->email,
            'password' => 'password-baru-123',
            'password_confirmation' => 'password-baru-123',
        ])->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('password-lama', $user->fresh()->password));
    }

    public function test_inactive_user_cannot_reset_password(): void
    {
        $user = User::factory()->admin()->create([
            'is_active' => false,
            'password' => 'password-lama',
        ]);
        $token = Password::createToken($user);

        $this->post(route('password.store'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'password-baru-123',
            'password_confirmation' => 'password-baru-123',
        ])->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('password-lama', $user->fresh()->password));
    }
}
