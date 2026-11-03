{{--
    A full document rather than the bare <meta>/<title> fragment this used to
    be. Two reasons, both about being installed on a phone: without a doctype
    the browser renders in quirks mode, and <link rel="manifest"> has to be in a
    real <head> for the install prompt to appear at all.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{{ $title ?? 'Diecast Catalog' }}</title>

    <link rel="manifest" href="/manifest.json">
    <link rel="icon" href="/icons/favicon-32.png" sizes="32x32">
    {{-- iOS ignores the manifest's icons and takes this one. --}}
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">

    {{-- Tailwind's dark variant follows prefers-color-scheme, so the browser
         chrome has to as well or the toolbar fights the page it's framing. --}}
    <meta name="theme-color" content="#fafafa" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#0a0a0a" media="(prefers-color-scheme: dark)">

    <meta name="mobile-web-app-capable" content="yes">
    {{-- Still required by iOS, which does not read the line above. --}}
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="collectAI">
    {{-- Not black-translucent: that pulls content under the status bar, and the
         header below has no safe-area inset to survive it. --}}
    <meta name="apple-mobile-web-app-status-bar-style" content="default">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    {{-- Installed on an iPhone there is no browser chrome under the page, so
         the home indicator sits on top of whatever the last element is. --}}
    <div class="min-h-dvh bg-neutral-50 pb-[env(safe-area-inset-bottom)] text-neutral-900 dark:bg-neutral-950 dark:text-neutral-100">
        {{-- Shown to everyone now that there is a public catalog. The name goes
             home; admins also get their way into the admin area. --}}
        <header class="border-b border-neutral-200 dark:border-neutral-800">
            <div class="mx-auto flex max-w-3xl items-center justify-between gap-4 px-4 py-3">
                <a href="{{ route('home') }}" class="font-semibold">collectAI</a>

                @auth
                    <div class="flex items-center gap-4">
                        @if (auth()->user()->is_admin)
                            <a href="{{ route('admin.dashboard') }}" class="text-sm text-neutral-500 hover:text-neutral-900 dark:hover:text-neutral-100">Admin</a>
                        @endif

                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="text-sm text-neutral-500 hover:text-neutral-900 dark:hover:text-neutral-100">
                                Sign out
                            </button>
                        </form>
                    </div>
                @endauth
            </div>
        </header>

        <main class="mx-auto max-w-3xl px-4 py-6">
            {{ $slot }}
        </main>
    </div>
</body>
</html>
