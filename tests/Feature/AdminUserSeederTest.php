<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminUserSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_never_creates_an_admin_with_an_empty_password(): void
    {
        // A bare `ADMIN_PASSWORD=` line in .env resolves to an empty string
        // rather than null, which once produced an admin account that an empty
        // password authenticated against.
        config(['catalog.admin.password' => '']);

        $this->seed(AdminUserSeeder::class);

        $admin = User::where('is_admin', true)->firstOrFail();

        $this->assertFalse(Hash::check('', $admin->password));
    }

    public function test_it_uses_a_configured_password_when_one_is_set(): void
    {
        config(['catalog.admin.password' => 'a-deliberately-chosen-password']);

        $this->seed(AdminUserSeeder::class);

        $admin = User::where('is_admin', true)->firstOrFail();

        $this->assertTrue(Hash::check('a-deliberately-chosen-password', $admin->password));
    }

    public function test_it_leaves_an_existing_admin_untouched(): void
    {
        $existing = User::factory()->admin()->create([
            'email' => config('catalog.admin.email'),
            'password' => 'original-password',
        ]);

        config(['catalog.admin.password' => 'a-different-password']);

        $this->seed(AdminUserSeeder::class);

        // Re-running the seeder must not silently reset the admin's credentials.
        $this->assertTrue(Hash::check('original-password', $existing->fresh()->password));
        $this->assertSame(1, User::where('is_admin', true)->count());
    }

    public function test_the_seeded_admin_can_actually_sign_in(): void
    {
        config(['catalog.admin.password' => 'a-deliberately-chosen-password']);

        $this->seed(AdminUserSeeder::class);

        $this->post(route('login.store'), [
            'email' => config('catalog.admin.email'),
            'password' => 'a-deliberately-chosen-password',
        ])->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticated();
    }
}
