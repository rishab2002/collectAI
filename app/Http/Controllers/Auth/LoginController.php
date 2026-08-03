<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Session login for the catalog admin.
 *
 * There is deliberately no registration route and no password reset: the only
 * account that can reach anything here is created by AdminUserSeeder, and a
 * public reset flow on a single-admin app is attack surface with no user.
 *
 * Sessions rather than API tokens because the capture app is served from this
 * same origin. That still holds if it's later wrapped with Capacitor pointed at
 * the deployed URL; a Capacitor build that bundles assets under its own scheme
 * would need tokens instead, and that's the point to add them.
 */
class LoginController extends Controller
{
    public function show(): View
    {
        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $this->ensureIsNotRateLimited($request);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            RateLimiter::hit($this->throttleKey($request));

            // Deliberately vague, and identical whether the email exists or not,
            // so the response can't be used to enumerate accounts.
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        RateLimiter::clear($this->throttleKey($request));

        // Rotates the session ID so a fixation attempt that planted a known
        // session before login doesn't survive it.
        $request->session()->regenerate();

        return redirect()->intended(route('admin.dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    /**
     * Throttled on email + IP together: per-IP alone would let one attacker
     * spray many accounts, and per-email alone would let anyone lock the admin
     * out of their own catalog.
     */
    private function ensureIsNotRateLimited(Request $request): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey($request), maxAttempts: 5)) {
            return;
        }

        throw ValidationException::withMessages([
            'email' => __('auth.throttle', [
                'seconds' => $seconds = RateLimiter::availableIn($this->throttleKey($request)),
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    private function throttleKey(Request $request): string
    {
        return str(mb_strtolower($request->string('email')).'|'.$request->ip())->transliterate();
    }
}
