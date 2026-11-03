<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_page_carries_the_browser_defences(): void
    {
        foreach ([route('home'), route('login'), route('register')] as $url) {
            $response = $this->get($url)->assertOk();

            $csp = $response->headers->get('Content-Security-Policy');
            $this->assertStringContainsString("script-src 'self'", $csp, $url);
            $this->assertStringContainsString("frame-ancestors 'none'", $csp);
            $this->assertStringContainsString("object-src 'none'", $csp);
            $this->assertStringContainsString("form-action 'self'", $csp);
            $this->assertStringNotContainsString('unsafe-inline', $csp);
            $this->assertStringNotContainsString('unsafe-eval', $csp);

            $response->assertHeader('X-Content-Type-Options', 'nosniff');
            $response->assertHeader('X-Frame-Options', 'DENY');
            $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
            // The capture camera must stay usable on our own pages.
            $this->assertStringContainsString('camera=(self)', $response->headers->get('Permissions-Policy'));
        }
    }

    public function test_photos_from_the_bucket_are_allowed_and_nothing_else_is(): void
    {
        config(['filesystems.disks.r2.url' => 'https://img.collectai.club/some/prefix']);

        $csp = $this->get(route('home'))->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("img-src 'self' data: blob: https://img.collectai.club", $csp);
    }

    public function test_hsts_is_sent_only_in_production(): void
    {
        $this->get(route('home'))->assertHeaderMissing('Strict-Transport-Security');

        $this->app['env'] = 'production';

        $response = $this->get(route('home'));
        $response->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        $this->assertStringContainsString('upgrade-insecure-requests', $response->headers->get('Content-Security-Policy'));
    }

    /**
     * The policy can forbid inline code only because no view has any. This
     * keeps it that way: an inline script, style attribute or on* handler in a
     * view would be silently blocked in the browser, so fail here instead.
     */
    public function test_no_view_relies_on_inline_script_or_style(): void
    {
        $offenders = [];

        foreach ((new Finder)->files()->in(resource_path('views'))->name('*.blade.php') as $file) {
            $contents = $file->getContents();

            if (preg_match('/<script(?![^>]*\bsrc=)[^>]*>|\sstyle="|\son[a-z]+="/i', $contents, $match) === 1) {
                $offenders[] = $file->getRelativePathname().': '.trim($match[0]);
            }
        }

        $this->assertSame([], $offenders);
    }
}
