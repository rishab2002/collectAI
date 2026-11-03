{{--
    The release and the variation: who sold it, under what series, which year,
    and what makes this unit distinct. The variation columns are exactly
    Variation::DISTINGUISHING_ATTRIBUTES — leave a column blank rather than
    guessing, because every one of them is part of what makes two cars "the same
    car".
--}}
@props(['brands' => [], 'lines' => [], 'seriesOptions' => [], 'defaults' => []])

@if (! empty($defaults['reference_item_id']))
    <input type="hidden" name="reference_item_id" value="{{ old('reference_item_id', $defaults['reference_item_id']) }}">
@endif

<div class="rounded-xl border border-neutral-200 bg-white p-4 dark:border-neutral-800 dark:bg-neutral-900">
    <h2 class="font-medium">Release</h2>
    <p class="mt-1 text-sm text-neutral-500">
        The brand that sold it, the series it was sold under, and the year. A car
        already listed in that series and year gets a new variation, not a second release.
    </p>

    <div class="mt-4 grid gap-4 sm:grid-cols-2">
        <x-catalog.field name="brand" label="Brand" list="brand-options" :value="$defaults['brand'] ?? null" placeholder="Hot Wheels" required />
        <x-catalog.field name="line" label="Main line" list="line-options" :value="$defaults['line'] ?? null" placeholder="Car Culture" hint="Only used when the series is new — it's filed under this line." />
        <x-catalog.field name="series" label="Series" list="series-options" :value="$defaults['series'] ?? null" placeholder="HW Night Burnerz" required />
        <x-catalog.field name="year" label="Year" type="number" min="1900" max="2100" :value="$defaults['year'] ?? null" :placeholder="(string) now()->year" required />
        <x-catalog.field name="collector_number" label="Collector number" placeholder="032" hint="The car's number in the year's run — the left half of “032/250”." />
    </div>

    <datalist id="brand-options">
        @foreach ($brands as $brand)
            <option value="{{ $brand }}"></option>
        @endforeach
    </datalist>
    <datalist id="line-options">
        @foreach ($lines as $line)
            <option value="{{ $line }}"></option>
        @endforeach
    </datalist>
    {{-- Series of the chosen brand only; every brand's would be tens of thousands. --}}
    <datalist id="series-options">
        @foreach ($seriesOptions as $option)
            <option value="{{ $option }}"></option>
        @endforeach
    </datalist>
</div>

<div class="rounded-xl border border-neutral-200 bg-white p-4 dark:border-neutral-800 dark:bg-neutral-900">
    <h2 class="font-medium">Variation</h2>
    <p class="mt-1 text-sm text-neutral-500">What makes this unit distinct. Leave blank anything you can't see.</p>

    <div class="mt-4 grid gap-4 sm:grid-cols-2">
        <x-catalog.field name="color" label="Colour" :value="$defaults['color'] ?? null" placeholder="Metalflake Red" />
        <x-catalog.field name="finish" label="Finish" placeholder="Spectraflame, ZAMAC, chrome…" />
        <x-catalog.field name="rim_type" label="Wheel type" placeholder="PR5, MC5, RR…" />
        <x-catalog.field name="toy_number" label="Toy number" :value="$defaults['toy_number'] ?? null" placeholder="T9819" hint="Printed on the card back — the best match key a card has." />
        <x-catalog.field name="interior_color" label="Interior colour" />
        <x-catalog.field name="window_tint" label="Window tint" />
        <x-catalog.field name="base_color" label="Base" placeholder="Black / Plastic" />
        <x-catalog.field name="country" label="Country" placeholder="Malaysia" />
    </div>

    <div class="mt-4">
        <label for="tampo" class="block text-sm font-medium">Tampo</label>
        <textarea id="tampo" name="tampo" rows="2" class="mt-1 w-full rounded-lg border border-neutral-300 bg-white px-3 py-2 dark:border-neutral-700 dark:bg-neutral-950">{{ old('tampo') }}</textarea>
        @error('tampo') <p class="mt-1 text-sm text-red-700 dark:text-red-300">{{ $message }}</p> @enderror
    </div>

    <div class="mt-4">
        <label for="notes" class="block text-sm font-medium">Notes</label>
        <textarea id="notes" name="notes" rows="2" placeholder="Treasure Hunt, store exclusive, error car…" class="mt-1 w-full rounded-lg border border-neutral-300 bg-white px-3 py-2 dark:border-neutral-700 dark:bg-neutral-950">{{ old('notes') }}</textarea>
        @error('notes') <p class="mt-1 text-sm text-red-700 dark:text-red-300">{{ $message }}</p> @enderror
    </div>
</div>
