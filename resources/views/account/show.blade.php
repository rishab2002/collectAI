<x-layouts.app title="Account">
    <h1 class="text-2xl font-semibold">Account</h1>
    <p class="mt-1 text-neutral-500">{{ auth()->user()->name }} · {{ auth()->user()->email }}</p>

    <x-catalog.flash />

    <form method="POST" action="{{ route('account.password') }}" class="mt-6 space-y-4 rounded-xl border border-neutral-200 bg-white p-4 dark:border-neutral-800 dark:bg-neutral-900">
        @csrf @method('PUT')
        <h2 class="font-medium">Change password</h2>
        @foreach ([['current_password', 'Current password', 'current-password'], ['password', 'New password', 'new-password'], ['password_confirmation', 'New password again', 'new-password']] as [$name, $label, $auto])
            <label class="block">
                <span class="block text-sm font-medium">{{ $label }}</span>
                <input type="password" name="{{ $name }}" autocomplete="{{ $auto }}" required class="mt-1 w-full rounded-lg border border-neutral-300 bg-white px-3 py-2 dark:border-neutral-700 dark:bg-neutral-950">
                @error($name, 'password')<span class="mt-1 block text-sm text-red-700 dark:text-red-300">{{ $message }}</span>@enderror
            </label>
        @endforeach
        <button type="submit" class="rounded-lg bg-neutral-900 px-4 py-2 font-medium text-white dark:bg-white dark:text-neutral-900">Change password</button>
    </form>

    @unless (auth()->user()->is_admin)
        <form method="POST" action="{{ route('account.destroy') }}" class="mt-6 space-y-4 rounded-xl border border-red-200 bg-white p-4 dark:border-red-900 dark:bg-neutral-900">
            @csrf @method('DELETE')
            <h2 class="font-medium text-red-800 dark:text-red-300">Delete account</h2>
            <p class="text-sm text-neutral-500">Deletes your account, collection and wishlist for good.</p>
            <label class="block">
                <span class="block text-sm font-medium">Password</span>
                <input type="password" name="password" autocomplete="current-password" required class="mt-1 w-full rounded-lg border border-neutral-300 bg-white px-3 py-2 dark:border-neutral-700 dark:bg-neutral-950">
                @error('password', 'delete')<span class="mt-1 block text-sm text-red-700 dark:text-red-300">{{ $message }}</span>@enderror
            </label>
            <button type="submit" class="rounded-lg border border-red-300 px-4 py-2 font-medium text-red-800 dark:border-red-800 dark:text-red-300">Delete my account</button>
        </form>
    @endunless
</x-layouts.app>
