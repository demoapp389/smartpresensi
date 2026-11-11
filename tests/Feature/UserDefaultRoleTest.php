<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UserDefaultRoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_model_defaults_to_least_privileged_role(): void
    {
        $this->assertSame('operator', (new User)->role);
    }

    public function test_database_default_is_operator_when_role_is_omitted(): void
    {
        DB::table('users')->insert([
            'name' => 'Akun Tanpa Role',
            'email' => 'tanpa-role@example.test',
            'password' => 'secret',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $role = DB::table('users')->where('email', 'tanpa-role@example.test')->value('role');

        $this->assertSame('operator', $role);
    }

    public function test_factory_still_creates_admin_for_test_convenience(): void
    {
        $this->assertSame('admin', User::factory()->create()->role);
    }
}
