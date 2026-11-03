<x-layouts.app title="Create an account">
    <div class="mx-auto max-w-sm pt-8">
        <h1 class="text-xl font-semibold">Create an account</h1>
        <p class="mt-1 text-sm text-neutral-500">Save the cars you own and the ones you want.</p>

        <form method="POST" action="{{ route('register.store') }}" class="mt-6 space-y-4">
            @csrf

            <x-catalog.field name="name" label="Your name" autocomplete="name" required />
            <x-catalog.field name="email" label="Email" type="email" inputmode="email" autocomplete="email" required />
            <x-catalog.field name="password" label="Password" type="password" autocomplete="new-password" required
                hint="At least 12 characters, with letters and numbers." />
            <x-catalog.field name="password_confirmation" label="Password again" type="password" autocomplete="new-password" required />

            {{-- Hidden from people, filled in by form-spraying bots. --}}
            <div aria-hidden="true" class="absolute -left-[9999px]">
                <label for="website">Leave this empty</label>
                <input id="website" name="website" type="text" tabindex="-1" autocomplete="off">
            </div>

            <button type="submit" class="w-full rounded-lg bg-neutral-900 px-4 py-2.5 font-medium text-white dark:bg-white dark:text-neutral-900">
                Create account
            </button>
            <p class="text-center text-xs text-neutral-500">By creating an account you agree to how we handle your data, set out in the <a href="{{ route('privacy') }}" class="underline">privacy policy</a>.</p>
        </form>

        <p class="mt-6 text-sm text-neutral-500">
            Already have one? <a href="{{ route('login') }}" class="underline">Sign in</a>.
        </p>
    </div>
</x-layouts.app>
