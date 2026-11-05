{{--
    Adding a car the catalog hasn't got, without leaving the scan.

    The search above can only offer what is already published, so the cars the
    recognizer most often gets wrong — the ones nobody has entered — are
    exactly the ones that cannot be named. Naming them is the point: a named
    crop is what teaches the recognizer.

    Admin only, because what is typed here goes live, which is the same reason
    Add a car is admin only. Prefilled by CardSuggestion from what the card
    said; every field is editable and the server validates it exactly as the
    Add a car page does.
--}}
{{-- Only one detection's panel is open at a time, so plain field names are
     unambiguous and old() lines up with them after a failed submit. --}}
@php($was = fn (string $field, ?string $fallback = null) => old($field, $fallback))
@php($list = "v{$detection->id}")

<details class="mt-3 rounded-lg border border-neutral-200 dark:border-neutral-800" @if ($errors->any()) open @endif>
    <summary class="cursor-pointer px-3 py-2 text-sm font-medium">Not in the catalog? Add it</summary>

    <form method="POST" action="{{ route('scan.catalog', [$detection->scan_id, $detection->id]) }}" class="space-y-3 border-t border-neutral-200 p-3 dark:border-neutral-800">
        @csrf

        @if ($errors->any())
            <ul class="list-disc space-y-1 rounded-lg bg-red-50 px-4 py-2 text-sm text-red-800 dark:bg-red-950 dark:text-red-200">
                @foreach ($errors->all() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        @endif

        <p class="text-xs text-neutral-500">
            Filled in from what the card says — check it. Each name offers what the catalog already has;
            type anything else and it is created. This publishes a new casting, release and version,
            adds the car to your collection, and (with sharing on) keeps this picture as a labelled example.
        </p>

        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <label class="block text-sm">
                <span class="block font-medium">Casting name</span>
                <span class="block text-xs text-neutral-500">As the card prints it: Porsche 911 Carrera T</span>
                <input type="text" name="casting_name" required value="{{ $was('casting_name', $suggestion['casting_name']) }}"
                       class="mt-1 w-full rounded-lg border border-neutral-300 px-3 py-1.5 dark:border-neutral-700 dark:bg-neutral-950">
            </label>

            <label class="block text-sm">
                <span class="block font-medium">Brand</span>
                <span class="block text-xs text-neutral-500">Hot Wheels, Matchbox, …</span>
                <input type="text" name="brand" required list="{{ $list }}-brands" value="{{ $was('brand', $suggestion['brand']) }}"
                       class="mt-1 w-full rounded-lg border border-neutral-300 px-3 py-1.5 dark:border-neutral-700 dark:bg-neutral-950">
                <datalist id="{{ $list }}-brands">
                    @foreach ($vocabulary['brands'] as $option)<option value="{{ $option }}"></option>@endforeach
                </datalist>
            </label>

            <label class="block text-sm">
                <span class="block font-medium">Series</span>
                <span class="block text-xs text-neutral-500">The line: Mainline, Car Culture, Premium…</span>
                <input type="text" name="series" required list="{{ $list }}-lines" value="{{ $was('series') }}"
                       class="mt-1 w-full rounded-lg border border-neutral-300 px-3 py-1.5 dark:border-neutral-700 dark:bg-neutral-950">
                <datalist id="{{ $list }}-lines">
                    @foreach ($vocabulary['lines'] as $option)<option value="{{ $option }}"></option>@endforeach
                </datalist>
            </label>

            <label class="block text-sm">
                <span class="block font-medium">Sub-series <span class="font-normal text-neutral-500">(if the card names one)</span></span>
                <span class="block text-xs text-neutral-500">The wave inside the line: Factory Fresh, HW J-Imports…</span>
                <input type="text" name="sub_series" list="{{ $list }}-series" value="{{ $was('sub_series') }}"
                       class="mt-1 w-full rounded-lg border border-neutral-300 px-3 py-1.5 dark:border-neutral-700 dark:bg-neutral-950">
                <datalist id="{{ $list }}-series">
                    @foreach ($vocabulary['series'] as $option => $under)<option value="{{ $option }}">{{ $under }}</option>@endforeach
                </datalist>
            </label>

            <label class="block text-sm">
                <span class="block font-medium">Year</span>
                <input type="number" name="year" required min="1900" max="2100" value="{{ $was('year', $suggestion['year']) }}"
                       class="mt-1 w-full rounded-lg border border-neutral-300 px-3 py-1.5 dark:border-neutral-700 dark:bg-neutral-950">
            </label>

            <label class="block text-sm">
                <span class="block font-medium">Colour</span>
                <span class="block text-xs text-neutral-500">A sealed card shows its artwork, not its car, so this one is yours.</span>
                <input type="text" name="color" value="{{ $was('color') }}"
                       class="mt-1 w-full rounded-lg border border-neutral-300 px-3 py-1.5 dark:border-neutral-700 dark:bg-neutral-950">
            </label>

            <label class="block text-sm">
                <span class="block font-medium">Real make</span>
                <input type="text" name="make" list="{{ $list }}-makes" value="{{ $was('make', $suggestion['make']) }}"
                       class="mt-1 w-full rounded-lg border border-neutral-300 px-3 py-1.5 dark:border-neutral-700 dark:bg-neutral-950">
                <datalist id="{{ $list }}-makes">
                    @foreach ($vocabulary['makes'] as $option)<option value="{{ $option }}"></option>@endforeach
                </datalist>
            </label>

            <label class="block text-sm">
                <span class="block font-medium">Real model</span>
                <span class="block text-xs text-neutral-500">The nameplate alone — 911, not 911 Carrera T.</span>
                <input type="text" name="model" list="{{ $list }}-models" value="{{ $was('model') }}"
                       class="mt-1 w-full rounded-lg border border-neutral-300 px-3 py-1.5 dark:border-neutral-700 dark:bg-neutral-950">
                <datalist id="{{ $list }}-models">
                    @foreach ($vocabulary['models'] as $option => $make)<option value="{{ $option }}">{{ $make }}</option>@endforeach
                </datalist>
            </label>

            <label class="block text-sm">
                <span class="block font-medium">Collector number</span>
                <input type="text" name="collector_number" value="{{ $was('collector_number', $suggestion['collector_number']) }}"
                       class="mt-1 w-full rounded-lg border border-neutral-300 px-3 py-1.5 dark:border-neutral-700 dark:bg-neutral-950">
            </label>

            <label class="block text-sm">
                <span class="block font-medium">Toy number</span>
                <input type="text" name="toy_number" value="{{ $was('toy_number', $suggestion['toy_number']) }}"
                       class="mt-1 w-full rounded-lg border border-neutral-300 px-3 py-1.5 dark:border-neutral-700 dark:bg-neutral-950">
            </label>

            <label class="block text-sm">
                <span class="block font-medium">Wheel type</span>
                <input type="text" name="rim_type" value="{{ $was('rim_type') }}"
                       class="mt-1 w-full rounded-lg border border-neutral-300 px-3 py-1.5 dark:border-neutral-700 dark:bg-neutral-950">
            </label>
        </div>

        <label class="flex items-start gap-2 text-sm">
            <input type="checkbox" name="is_fantasy" value="1" class="mt-1" @checked($was('is_fantasy'))>
            <span>Fantasy casting — there is no real car behind it, so leave the make and model empty.</span>
        </label>

        <label class="flex items-start gap-2 text-sm">
            <input type="checkbox" name="queue_for_review" value="1" class="mt-1" @checked($was('queue_for_review'))>
            <span>
                <strong>Check it before it goes live.</strong> Saves the car pending and puts it in
                <a href="{{ route('admin.proposals.index') }}" class="underline">Inferred records</a> to approve.
                It joins the catalog, and your collection, once you approve it — so nothing read off a card at a
                glance reaches the public catalog unchecked.
            </span>
        </label>

        <button type="submit" class="rounded-lg bg-neutral-900 px-4 py-2 text-sm font-medium text-white dark:bg-white dark:text-neutral-900">
            Add this car
        </button>
    </form>
</details>
