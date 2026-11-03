<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Browser-side defences sent with every page.
 *
 * The Content Security Policy is the important one: it tells the browser to run
 * only scripts this site serves, so an injected `<script>` — the payload of a
 * cross-site-scripting bug — does nothing. It can be this strict because no
 * view uses an inline script, style attribute or event handler; keep it that
 * way (SecurityHeadersTest guards the views).
 *
 * Images may also come from the photo bucket's public address (`R2_URL`, i.e.
 * img.collectai.club), which is a different origin from the app.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = [
            'Content-Security-Policy' => $this->policy(),
            // Never sniff a photo into a script.
            'X-Content-Type-Options' => 'nosniff',
            // No one may frame the site — no clickjacking the Approve or Delete
            // buttons. frame-ancestors in the CSP says the same to newer browsers.
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            // Nothing here needs these. (The capture form's camera is the phone's
            // own camera app via <input capture>, which this doesn't govern.)
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
            'Cross-Origin-Opener-Policy' => 'same-origin',
        ];

        // HTTPS-only from here on, for a year. Only in production, where the
        // site really is HTTPS end to end through Cloudflare — sending it from a
        // local http:// box would make browsers refuse localhost.
        if (app()->isProduction()) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }

        foreach ($headers as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }

    private function policy(): string
    {
        $images = ["'self'", 'data:', 'blob:'];

        if ($photos = $this->origin(config('filesystems.disks.r2.url'))) {
            $images[] = $photos;
        }

        $scripts = ["'self'"];
        $styles = ["'self'"];
        $connect = ["'self'"];

        // `npm run dev` serves scripts and styles from Vite's own server and
        // pushes updates over a websocket; allow that in local development only.
        if (app()->isLocal() && is_file(public_path('hot'))) {
            $vite = $this->origin(trim((string) file_get_contents(public_path('hot'))));

            if ($vite !== null) {
                $scripts[] = $vite;
                $styles[] = $vite;
                $connect[] = $vite;
                $connect[] = preg_replace('#^http#', 'ws', $vite);
            }
        }

        $directives = [
            'default-src' => ["'self'"],
            'script-src' => $scripts,
            'style-src' => $styles,
            'img-src' => $images,
            'font-src' => ["'self'", 'data:'],
            'connect-src' => $connect,
            'manifest-src' => ["'self'"],
            'worker-src' => ["'self'"],
            'object-src' => ["'none'"],
            'base-uri' => ["'self'"],
            'form-action' => ["'self'"],
            'frame-ancestors' => ["'none'"],
        ];

        $policy = collect($directives)->map(fn (array $sources, string $name): string => $name.' '.implode(' ', array_unique($sources)))->implode('; ');

        return app()->isProduction() ? $policy.'; upgrade-insecure-requests' : $policy;
    }

    private function origin(?string $url): ?string
    {
        if (blank($url)) {
            return null;
        }

        $parts = parse_url($url);

        if (! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }
}
