<x-layouts.app title="Capture">
    @php
        // `capture="environment"` opens the rear camera directly on iOS instead
        // of the photo picker, which is what you want with the car in your other
        // hand. It also *removes* the library option, so it's only on the two
        // slots that are genuinely one shot each — see the loose slot below.
        $cardSlots = [
            ['name' => 'card_front', 'label' => 'Card front', 'hint' => 'Brand, series, collector number.'],
            ['name' => 'card_back', 'label' => 'Card back', 'hint' => 'Toy number and small print.'],
        ];
    @endphp

    <div class="flex items-baseline justify-between gap-4">
        <h1 class="text-xl font-semibold">Capture</h1>
        <a href="{{ route('admin.dashboard') }}" class="text-sm text-neutral-500 hover:text-neutral-900 dark:hover:text-neutral-100">
            Review queue
        </a>
    </div>

    @if (session('status'))
        <p class="mt-4 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800 dark:border-green-900 dark:bg-green-950 dark:text-green-200">
            {{ session('status') }}
        </p>
    @endif

    <form
        method="POST"
        action="{{ route('admin.capture.store') }}"
        enctype="multipart/form-data"
        class="mt-6 space-y-6"
        data-capture-form
    >
        @csrf

        {{-- Rendered by the browser on a plain POST, and repopulated by app.js
             from the JSON body when the upload goes through XHR. --}}
        <div
            class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200"
            data-capture-errors
            hidden
        ></div>

        @if ($errors->any())
            <ul class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        @endif

        <div class="space-y-3">
            @foreach ($cardSlots as $slot)
                <label
                    class="flex items-center gap-4 rounded-xl border border-neutral-200 bg-white p-4 dark:border-neutral-800 dark:bg-neutral-900"
                    data-photo-slot
                >
                    <input
                        type="file"
                        name="{{ $slot['name'] }}"
                        accept="image/*"
                        capture="environment"
                        class="sr-only"
                        data-photo-input
                    >

                    <span
                        class="grid size-16 shrink-0 place-items-center overflow-hidden rounded-lg bg-neutral-100 text-2xl text-neutral-400 dark:bg-neutral-800"
                        data-photo-thumb
                    >+</span>

                    <span class="min-w-0">
                        <span class="block font-medium">{{ $slot['label'] }}</span>
                        <span class="block truncate text-sm text-neutral-500" data-photo-status>{{ $slot['hint'] }}</span>
                    </span>
                </label>
            @endforeach

            {{-- No `capture` here: several angles of a loose car is the normal
                 case, and on iOS `capture` and `multiple` contradict each other
                 — capture wins and you get one shot. Without it the picker still
                 offers Take Photo, so nothing is lost. --}}
            <label
                class="flex items-center gap-4 rounded-xl border border-neutral-200 bg-white p-4 dark:border-neutral-800 dark:bg-neutral-900"
                data-photo-slot
            >
                <input
                    type="file"
                    name="loose[]"
                    accept="image/*"
                    multiple
                    class="sr-only"
                    data-photo-input
                >

                <span
                    class="grid size-16 shrink-0 place-items-center overflow-hidden rounded-lg bg-neutral-100 text-2xl text-neutral-400 dark:bg-neutral-800"
                    data-photo-thumb
                >+</span>

                <span class="min-w-0">
                    <span class="block font-medium">Loose car</span>
                    <span class="block truncate text-sm text-neutral-500" data-photo-status>Optional, up to 6 angles.</span>
                </span>
            </label>
        </div>

        <div class="rounded-xl border border-neutral-200 bg-white p-4 dark:border-neutral-800 dark:bg-neutral-900">
            <h2 class="font-medium">Hints</h2>
            <p class="mt-1 text-sm text-neutral-500">
                Optional. Anything you fill in lets the pipeline narrow to known castings
                in that series and year instead of working from the photo alone.
            </p>

            <div class="mt-4 space-y-4">
                <div>
                    <label for="hint_brand" class="block text-sm font-medium">Brand</label>
                    <input
                        id="hint_brand"
                        name="hint_brand"
                        type="text"
                        autocomplete="off"
                        value="{{ old('hint_brand') }}"
                        placeholder="Hot Wheels"
                        class="mt-1 w-full rounded-lg border border-neutral-300 bg-white px-3 py-2 dark:border-neutral-700 dark:bg-neutral-950"
                    >
                </div>

                <div>
                    <label for="hint_year" class="block text-sm font-medium">Year</label>
                    <input
                        id="hint_year"
                        name="hint_year"
                        type="number"
                        inputmode="numeric"
                        min="1900"
                        max="2100"
                        value="{{ old('hint_year') }}"
                        placeholder="{{ now()->year }}"
                        class="mt-1 w-full rounded-lg border border-neutral-300 bg-white px-3 py-2 dark:border-neutral-700 dark:bg-neutral-950"
                    >
                </div>

                <div>
                    <label for="hint_series" class="block text-sm font-medium">Series</label>
                    <input
                        id="hint_series"
                        name="hint_series"
                        type="text"
                        autocomplete="off"
                        value="{{ old('hint_series') }}"
                        placeholder="Night Burnerz"
                        class="mt-1 w-full rounded-lg border border-neutral-300 bg-white px-3 py-2 dark:border-neutral-700 dark:bg-neutral-950"
                    >
                </div>
            </div>
        </div>

        {{-- Revealed by app.js during an XHR upload. iOS suspends background
             uploads, so this has to stay on screen and honest: a bar that
             finishes at 100% and then sits there is how you end up with an
             admin who locks the phone mid-transfer. --}}
        <div class="space-y-2" data-capture-progress hidden>
            <div class="h-2 overflow-hidden rounded-full bg-neutral-200 dark:bg-neutral-800">
                <div class="h-full w-0 bg-neutral-900 transition-[width] duration-150 dark:bg-white" data-capture-bar></div>
            </div>
            <p class="text-sm text-neutral-500" data-capture-progress-label>Uploading…</p>
        </div>

        <div class="flex gap-3">
            <button
                type="submit"
                class="flex-1 rounded-lg bg-neutral-900 px-4 py-3 font-medium text-white disabled:opacity-50 dark:bg-white dark:text-neutral-900"
                data-capture-submit
            >
                Upload &amp; queue
            </button>

            {{-- A failed upload has to be retryable rather than silent. The files
                 are still in the inputs, so this just re-sends them. --}}
            <button
                type="button"
                class="rounded-lg border border-neutral-300 px-4 py-3 font-medium dark:border-neutral-700"
                data-capture-retry
                hidden
            >
                Retry
            </button>
        </div>
    </form>
</x-layouts.app>
