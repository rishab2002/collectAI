<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->get('/admin')->assertRedirect(route('login'));
    }

    public function test_a_signed_in_non_admin_cannot_see_that_the_admin_area_exists(): void
    {
        // 404 rather than 403: a public collector poking at /admin shouldn't be
        // told there's something there to get access to.
        $this->actingAs(User::factory()->create())
            ->get('/admin')
            ->assertNotFound();
    }

    public function test_an_admin_reaches_the_review_queue(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get('/admin')
            ->assertOk()
            ->assertSee('Review queue');
    }

    public function test_an_admin_can_sign_in(): void
    {
        $admin = User::factory()->admin()->create(['password' => 'correct-horse-battery']);

        $this->post(route('login.store'), [
            'email' => $admin->email,
            'password' => 'correct-horse-battery',
        ])->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticatedAs($admin);
    }

    public function test_a_wrong_password_does_not_sign_anyone_in(): void
    {
        $admin = User::factory()->admin()->create(['password' => 'correct-horse-battery']);

        $this->post(route('login.store'), [
            'email' => $admin->email,
            'password' => 'wrong',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_login_is_throttled_after_repeated_failures(): void
    {
        $admin = User::factory()->admin()->create();

        foreach (range(1, 5) as $ignored) {
            $this->post(route('login.store'), [
                'email' => $admin->email,
                'password' => 'wrong',
            ]);
        }

        $response = $this->post(route('login.store'), [
            'email' => $admin->email,
            'password' => 'wrong',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertStringContainsString(
            'Too many login attempts',
            session('errors')->first('email'),
        );

        RateLimiter::clear(mb_strtolower($admin->email).'|127.0.0.1');
    }

    public function test_registration_can_never_create_an_admin(): void
    {
        // Collector accounts exist since 2026-11-03. What this test always
        // guarded still holds: the only privileged account comes from
        // AdminUserSeeder, and no public form can make another — even when the
        // field is smuggled into the request.
        $this->post(route('register.store'), [
            'name' => 'Mallory',
            'email' => 'mallory@example.test',
            'password' => 'correct horse battery 42',
            'password_confirmation' => 'correct horse battery 42',
            'is_admin' => '1',
        ])->assertRedirect(route('collection.index'));

        $user = User::where('email', 'mallory@example.test')->sole();
        $this->assertFalse($user->is_admin);
        $this->get(route('admin.dashboard'))->assertNotFound();
    }

    public function test_signing_out_ends_the_session(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->post(route('logout'))
            ->assertRedirect(route('home'));

        $this->assertGuest();
    }
}
