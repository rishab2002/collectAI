<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The installable-app shell.
 *
 * These assert against files in public/ rather than through HTTP, because the
 * webserver serves them directly and they never reach Laravel's router — a test
 * that hit a route would be testing something that doesn't exist in production.
 */
class PwaTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_manifest_describes_an_installable_app(): void
    {
        $manifest = $this->manifest();

        $this->assertNotNull($manifest, 'public/manifest.json is not valid JSON.');

        // The four Chrome actually gates the install prompt on.
        $this->assertNotEmpty($manifest['name']);
        $this->assertNotEmpty($manifest['short_name']);
        $this->assertSame('standalone', $manifest['display']);
        $this->assertNotEmpty($manifest['icons']);

        // The app opens on the camera form: the whole reason it's installed on a
        // phone is that you can't photograph a car from a laptop.
        $this->assertSame('/admin/capture', $manifest['start_url']);

        // start_url can move without the app being treated as a new install
        // only if id is pinned.
        $this->assertSame('/', $manifest['id']);
    }

    public function test_the_manifest_ships_both_a_plain_and_a_maskable_icon(): void
    {
        $purposes = collect($this->manifest()['icons'])->pluck('purpose');

        // Android crops a non-maskable icon to fit its mask, which is how you
        // get a car with its wheels sliced off on the home screen.
        $this->assertTrue($purposes->contains('any'));
        $this->assertTrue($purposes->contains('maskable'));

        $sizes = collect($this->manifest()['icons'])->pluck('sizes');
        $this->assertTrue($sizes->contains('192x192'));
        $this->assertTrue($sizes->contains('512x512'));
    }

    public function test_every_file_the_manifest_and_service_worker_name_exists(): void
    {
        $referenced = collect($this->manifest()['icons'])
            ->pluck('src')
            ->merge(collect($this->manifest()['shortcuts'] ?? [])->pluck('icons.*.src')->flatten())
            ->push('/apple-touch-icon.png', '/icons/favicon-32.png', '/offline.html')
            ->unique();

        foreach ($referenced as $path) {
            $this->assertFileExists(
                public_path(ltrim($path, '/')),
                "{$path} is referenced but not present in public/.",
            );
        }
    }

    public function test_the_service_worker_sits_at_the_root_so_its_scope_covers_the_app(): void
    {
        // A worker served from a subdirectory can only control that
        // subdirectory, which would leave /admin uncovered.
        $this->assertFileExists(public_path('sw.js'));
    }

    public function test_the_service_worker_leaves_non_get_requests_alone(): void
    {
        $worker = file_get_contents(public_path('sw.js'));

        // Guarded here as well as in review, because the cost of regressing it
        // is a duplicated capture upload or an approval applied twice, and
        // neither shows up as a failure anywhere else.
        $this->assertStringContainsString("request.method !== 'GET'", $worker);
    }

    public function test_pages_link_the_manifest_and_render_in_standards_mode(): void
    {
        $response = $this->actingAs(User::factory()->admin()->create())->get('/admin');

        $response->assertOk()
            ->assertSee('<!DOCTYPE html>', escape: false)
            ->assertSee('<link rel="manifest" href="/manifest.json">', escape: false)
            ->assertSee('<link rel="apple-touch-icon" href="/apple-touch-icon.png">', escape: false)
            ->assertSee('apple-mobile-web-app-capable');
    }

    /**
     * @return array<string, mixed>
     */
    private function manifest(): array
    {
        return json_decode(file_get_contents(public_path('manifest.json')), true);
    }
}
