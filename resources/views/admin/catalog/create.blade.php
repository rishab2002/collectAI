<x-layouts.app title="Add a car">
    <div class="flex items-baseline justify-between gap-4">
        <h1 class="text-xl font-semibold">Add a car</h1>
        <a href="{{ route('admin.catalog.index') }}" class="text-sm text-neutral-500 hover:text-neutral-900 dark:hover:text-neutral-100">Catalog</a>
    </div>

    <p class="mt-2 text-sm text-neutral-500">
        For a casting the catalog doesn't have yet. If the casting is already there —
        a new colour, a new year — <a href="{{ route('admin.catalog.index') }}" class="underline">find it</a>
        and add the variation from its page, so the releases share one tool.
        What you enter here goes live straight away.
    </p>

    <x-catalog.flash />

    <form method="POST" action="{{ route('admin.castings.store') }}" enctype="multipart/form-data" class="mt-6 space-y-6">
        @csrf

        <div class="rounded-xl border border-neutral-200 bg-white p-4 dark:border-neutral-800 dark:bg-neutral-900">
            <h2 class="font-medium">Casting</h2>
            <p class="mt-1 text-sm text-neutral-500">
                One physical tool. Its name as the manufacturer uses it — “'93 Mazda RX-7”, “Bone Shaker”.
            </p>

            <div class="mt-4 space-y-4">
                <x-catalog.field name="casting_name" label="Casting name" required />

                <label class="flex items-start gap-3">
                    <input type="hidden" name="is_fantasy" value="0">
                    <input type="checkbox" name="is_fantasy" value="1" @checked(old('is_fantasy')) class="mt-1 size-4">
                    <span>
                        <span class="block text-sm font-medium">Fantasy casting</span>
                        <span class="block text-sm text-neutral-500">No real car behind it. Leave make and model empty.</span>
                    </span>
                </label>
            </div>
        </div>

        <div class="rounded-xl border border-neutral-200 bg-white p-4 dark:border-neutral-800 dark:bg-neutral-900">
            <h2 class="font-medium">Real car</h2>
            <p class="mt-1 text-sm text-neutral-500">
                The real-world vehicle, split into make and model even if the packaging prints one name.
                Generation is optional — fill it in when the casting is one specific generation.
            </p>

            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <x-catalog.field name="make" label="Make" list="make-options" placeholder="Mazda" />
                <x-catalog.field name="model" label="Model" placeholder="RX-7" hint="The nameplate alone — “Corvette”, not “Corvette Grand Sport”." />
                <x-catalog.field name="generation_start_year" label="Generation from" type="number" min="1880" max="2100" placeholder="1992" />
                <x-catalog.field name="generation_end_year" label="Generation to" type="number" min="1880" max="2100" placeholder="2002" hint="Blank for a current generation." />
                <x-catalog.field name="chassis_code" label="Chassis code" placeholder="FD3S" class="sm:col-span-2" />
            </div>

            <datalist id="make-options">
                @foreach ($makes as $make)
                    <option value="{{ $make }}"></option>
                @endforeach
            </datalist>
        </div>

        <x-catalog.variation-fields :brands="$brands" />

        <div class="rounded-xl border border-neutral-200 bg-white p-4 dark:border-neutral-800 dark:bg-neutral-900">
            <h2 class="font-medium">Photos</h2>
            <p class="mt-1 mb-4 text-sm text-neutral-500">Optional — you can add them from the car's page later.</p>
            <x-catalog.photo-slots />
        </div>

        <button type="submit" class="w-full rounded-lg bg-neutral-900 px-4 py-3 font-medium text-white dark:bg-white dark:text-neutral-900">
            Add to catalog
        </button>
    </form>
</x-layouts.app>
