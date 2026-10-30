<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_admin_with_provided_credentials(): void
    {
        $this->artisan('sp:create-admin', [
            '--name' => 'Kepala Sekolah',
            '--email' => 'kepsek@sekolah.sch.id',
            '--password' => 'rahasia123',
        ])->assertExitCode(0);

        $user = User::where('email', 'kepsek@sekolah.sch.id')->first();

        $this->assertNotNull($user);
        $this->assertSame('Kepala Sekolah', $user->name);
        $this->assertSame('admin', $user->role);
        $this->assertTrue($user->is_active);
        $this->assertTrue(Hash::check('rahasia123', $user->password));
    }

    public function test_uses_default_name_and_updates_existing_account(): void
    {
        User::factory()->create([
            'email' => 'admin@sekolah.sch.id',
            'password' => 'oldpassword',
        ]);

        $this->artisan('sp:create-admin', [
            '--email' => 'admin@sekolah.sch.id',
            '--password' => 'newpassword1',
        ])->assertExitCode(0);

        $user = User::where('email', 'admin@sekolah.sch.id')->first();

        $this->assertSame('Administrator Sekolah', $user->name);
        $this->assertSame('admin', $user->role);
        $this->assertTrue(Hash::check('newpassword1', $user->password));
        $this->assertSame(1, User::count());
    }

    public function test_rejects_invalid_email_and_short_password(): void
    {
        $this->artisan('sp:create-admin', [
            '--email' => 'not-an-email',
            '--password' => 'short',
        ])->assertExitCode(1);

        $this->assertSame(0, User::count());
    }
}
