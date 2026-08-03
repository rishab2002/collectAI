<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates everything behind the capture and review app.
 *
 * Returns 404 rather than 403 for a signed-in non-admin: a public collector
 * poking at /admin shouldn't be told the route exists. Guests are handled
 * upstream by the `auth` middleware, which redirects them to the login page.
 */
class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->is_admin, 404);

        return $next($request);
    }
}
