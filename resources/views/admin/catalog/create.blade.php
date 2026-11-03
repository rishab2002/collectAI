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

    {{-- Find the model: search the reference products and start from one. --}}
    <div class="mt-6 rounded-xl border border-neutral-200 bg-white p-4 dark:border-neutral-800 dark:bg-neutral-900">
        <h2 class="font-medium">Find the model</h2>
        <p class="mt-1 text-sm text-neutral-500">
            Search by name or item number (HWPriceGuide, Mini GT, Inno64, Para64, Horizon Diecast) to fill the form in.
            A car added this way is recognised by the scanner straight away.
        </p>
        <form method="GET" action="{{ route('admin.castings.create') }}" class="mt-3 flex gap-2">
            <label for="find" class="sr-only">Name or item number</label>
            <input id="find" type="search" name="find" value="{{ $find }}" placeholder="e.g. Skyline R34, MGT00123, IN64-RS200-SL"
                   class="min-w-0 flex-1 rounded-lg border border-neutral-300 bg-white px-3 py-2 dark:border-neutral-700 dark:bg-neutral-950">
            <button type="submit" class="rounded-lg border border-neutral-300 px-4 py-2 dark:border-neutral-700">Search</button>
        </form>

        @if ($find !== '' && $found->isEmpty())
            <p class="mt-3 text-sm text-neutral-500">Nothing found — fill the form in by hand.</p>
        @endif

        @if ($found->isNotEmpty())
            <ul class="mt-3 divide-y divide-neutral-200 text-sm dark:divide-neutral-800">
                @foreach ($found as ['item' => $item, 'casting' => $existing])
                    <li class="flex flex-wrap items-center justify-between gap-2 py-2">
                        <span class="min-w-0">
                            <span class="block font-medium">{{ $item->name }}</span>
                            <span class="block text-xs text-neutral-500">
                                {{ collect([$item->brand, $item->line, $item->series, $item->year, $item->item_code])->filter()->implode(' · ') }}
                                · {{ \App\Models\ReferenceItem::SOURCES[$item->source] ?? $item->source }}
                            </span>
                        </span>
                        @if ($existing)
                            <a href="{{ route('admin.castings.show', ['casting' => $existing, 'ref' => $item->id]) }}#add-variation" class="shrink-0 underline">Already here as {{ $existing->name }} — add this version</a>
                        @else
                            <a href="{{ route('admin.castings.create', ['ref' => $item->id]) }}" class="shrink-0 rounded border border-neutral-300 px-2 py-1 dark:border-neutral-700">Use this</a>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif

        @if ($reference)
            <p class="mt-3 rounded-lg bg-green-50 px-3 py-2 text-sm text-green-800 dark:bg-green-950 dark:text-green-200">
                Filled in from <strong>{{ $reference->name }}</strong> ({{ \App\Models\ReferenceItem::SOURCES[$reference->source] ?? $reference->source }}). Check every field before saving.
            </p>
        @endif
    </div>

    <form method="POST" action="{{ route('admin.castings.store') }}" enctype="multipart/form-data" class="mt-6 space-y-6">
        @csrf

        <div class="rounded-xl border border-neutral-200 bg-white p-4 dark:border-neutral-800 dark:bg-neutral-900">
            <h2 class="font-medium">Casting</h2>
            <p class="mt-1 text-sm text-neutral-500">
                One physical tool. Its name as the manufacturer uses it — “'93 Mazda RX-7”, “Bone Shaker”.
            </p>

            <div class="mt-4 space-y-4">
                <x-catalog.field name="casting_name" label="Casting name" :value="$prefill['casting_name'] ?? null" required />

                <label class="flex items-start gap-3">
                    <input type="hidden" name="is_fantasy" value="0">
                    <input type="checkbox" name="is_fantasy" value="1" @checked(old('is_fantasy', $prefill['is_fantasy'] ?? false)) class="mt-1 size-4">
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
                <x-catalog.field name="make" label="Make" list="make-options" :value="$prefill['make'] ?? null" placeholder="Mazda" />
                <x-catalog.field name="model" label="Model" :value="$prefill['model'] ?? null" placeholder="RX-7" hint="The nameplate alone — “Corvette”, not “Corvette Grand Sport”." />
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

        <x-catalog.variation-fields :brands="$brands" :lines="$lines" :series-options="$seriesOptions" :defaults="$prefill" />

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
