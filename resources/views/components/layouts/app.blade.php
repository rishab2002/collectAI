<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>{{ $title ?? 'Diecast Catalog' }}</title>

@vite(['resources/css/app.css', 'resources/js/app.js'])

<div class="min-h-dvh bg-neutral-50 text-neutral-900 dark:bg-neutral-950 dark:text-neutral-100">
    @auth
        <header class="border-b border-neutral-200 dark:border-neutral-800">
            <div class="mx-auto flex max-w-3xl items-center justify-between px-4 py-3">
                <a href="{{ route('admin.dashboard') }}" class="font-semibold">Diecast Catalog</a>

                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="text-sm text-neutral-500 hover:text-neutral-900 dark:hover:text-neutral-100">
                        Sign out
                    </button>
                </form>
            </div>
        </header>
    @endauth

    <main class="mx-auto max-w-3xl px-4 py-6">
        {{ $slot }}
    </main>
</div>
