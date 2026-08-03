<x-layouts.app title="Sign in">
    <div class="mx-auto max-w-sm pt-8">
        <h1 class="text-xl font-semibold">Sign in</h1>
        <p class="mt-1 text-sm text-neutral-500">Catalog administration.</p>

        <form method="POST" action="{{ route('login.store') }}" class="mt-6 space-y-4">
            @csrf

            <div>
                <label for="email" class="block text-sm font-medium">Email</label>
                <input
                    id="email"
                    name="email"
                    type="email"
                    inputmode="email"
                    autocomplete="username"
                    required
                    autofocus
                    value="{{ old('email') }}"
                    class="mt-1 w-full rounded-lg border border-neutral-300 bg-white px-3 py-2 dark:border-neutral-700 dark:bg-neutral-900"
                >
            </div>

            <div>
                <label for="password" class="block text-sm font-medium">Password</label>
                <input
                    id="password"
                    name="password"
                    type="password"
                    autocomplete="current-password"
                    required
                    class="mt-1 w-full rounded-lg border border-neutral-300 bg-white px-3 py-2 dark:border-neutral-700 dark:bg-neutral-900"
                >
            </div>

            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="remember" value="1" class="rounded border-neutral-300">
                Stay signed in
            </label>

            @error('email')
                <p class="text-sm text-red-600">{{ $message }}</p>
            @enderror

            <button
                type="submit"
                class="w-full rounded-lg bg-neutral-900 px-4 py-2.5 font-medium text-white dark:bg-white dark:text-neutral-900"
            >
                Sign in
            </button>
        </form>
    </div>
</x-layouts.app>
