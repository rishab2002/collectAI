{{--
    Cutting a package out of a photo by hand, and scanning that.

    The scanner finds a package by the printing on it, so one whose name it
    cannot read it walks past entirely — no detection, nothing to confirm, and
    no way to reach it from the results. Drawing a box round it is the way
    back in, and it can be repeated for as many packages as it missed.

    Plain form first: four fraction boxes, which work with no JavaScript at
    all. `resources/js/app.js` turns the photo into a drag-to-select that
    writes the same four values — enhancement, not dependency, as the capture
    form is. Nothing inline, because the CSP forbids it.
--}}
<details class="mt-8 rounded-xl border border-neutral-200 bg-white dark:border-neutral-800 dark:bg-neutral-900">
    <summary class="cursor-pointer px-4 py-3 font-medium">
        Missed one? Cut it out yourself
        <span class="block text-sm font-normal text-neutral-500">
            Draw a box round a package the scanner walked past and scan just that — as many times as you need.
        </span>
    </summary>

    <div class="space-y-8 border-t border-neutral-200 p-4 dark:border-neutral-800">
        @foreach ($scan->scanPhotos as $photo)
            @continue($photo->path === null)
            <form method="POST" action="{{ route('scan.rescan', [$scan, $photo]) }}" class="space-y-3" data-crop-form>
                @csrf
                <p class="text-sm font-medium">Photo {{ $photo->number }}</p>

                <div class="relative inline-block max-w-full" data-crop-canvas>
                    <img src="{{ route('scan.photo', [$scan, $photo]) }}" alt="Photo {{ $photo->number }} of this scan"
                         class="max-h-96 w-auto max-w-full select-none rounded-lg border border-neutral-200 dark:border-neutral-800"
                         data-crop-image>
                    <div class="pointer-events-none absolute hidden border-2 border-sky-500 bg-sky-500/20" data-crop-box></div>
                </div>

                <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                    @foreach ([['x', 'Left'], ['y', 'Top'], ['width', 'Width'], ['height', 'Height']] as [$field, $label])
                        <label class="block text-sm">
                            <span class="block text-xs text-neutral-500">{{ $label }}</span>
                            <input type="number" name="{{ $field }}" step="0.001" min="0" max="1" required
                                   value="{{ old($field, in_array($field, ['width', 'height'], true) ? '1' : '0') }}"
                                   data-crop-field="{{ $field }}"
                                   class="mt-1 w-full rounded-lg border border-neutral-300 px-2 py-1 dark:border-neutral-700 dark:bg-neutral-950">
                        </label>
                    @endforeach
                </div>
                <p class="text-xs text-neutral-500">Fractions of the photo, 0 to 1. Drag on the picture to set them.</p>

                @error('width') <p class="text-sm text-red-700 dark:text-red-300">{{ $message }}</p> @enderror

                <button type="submit" class="rounded-lg bg-neutral-900 px-4 py-2 text-sm font-medium text-white dark:bg-white dark:text-neutral-900">
                    Scan this piece
                </button>
            </form>
        @endforeach
    </div>
</details>
