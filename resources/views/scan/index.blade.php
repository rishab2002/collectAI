<x-layouts.app title="Scan your cars">
    <h1 class="text-2xl font-semibold">Scan your cars</h1>
    <p class="mt-2 text-neutral-500">
        Photograph your cars and add them to your collection. Only cars the scanner is sure of are offered,
        and nothing is added until you confirm it.
    </p>

    <x-catalog.flash />

    <form method="POST" action="{{ route('scan.store') }}" enctype="multipart/form-data"
          class="mt-6 space-y-5 rounded-xl border border-neutral-200 bg-white p-4 dark:border-neutral-800 dark:bg-neutral-900">
        @csrf

        <label class="block">
            <span class="block font-medium">Photos</span>
            <span class="block text-sm text-neutral-500">Up to {{ $maxPhotos }} at a time — JPEG, PNG or WebP.</span>
            <input type="file" name="photos[]" multiple required accept="image/jpeg,image/png,image/webp"
                   class="mt-2 block w-full text-sm file:mr-3 file:rounded-lg file:border-0 file:bg-neutral-900 file:px-3 file:py-2 file:text-white dark:file:bg-white dark:file:text-neutral-900">
            @error('photos') <span class="mt-1 block text-sm text-red-700 dark:text-red-300">{{ $message }}</span> @enderror
            @error('photos.*') <span class="mt-1 block text-sm text-red-700 dark:text-red-300">{{ $message }}</span> @enderror
        </label>

        <fieldset>
            <legend class="font-medium">What's in the photos?</legend>
            <div class="mt-2 space-y-2 text-sm">
                <label class="flex items-start gap-2">
                    <input type="radio" name="layout" value="pile" class="mt-1" @checked(old('layout', 'pile') === 'pile')>
                    <span><strong>Several cars, spread out</strong> on a plain table or sheet, not touching.
                        <span class="block text-neutral-500">Best: lay them <em>upside down</em> so the base text shows.</span></span>
                </label>
                <label class="flex items-start gap-2">
                    <input type="radio" name="layout" value="single" class="mt-1" @checked(old('layout') === 'single')>
                    <span><strong>One car close up</strong> — its base, or the card it's on.</span>
                </label>
                <label class="flex items-start gap-2">
                    <input type="radio" name="layout" value="auto" class="mt-1" @checked(old('layout') === 'auto')>
                    <span>Not sure — let the scanner guess.</span>
                </label>
            </div>
        </fieldset>

        <label class="flex items-start gap-2 text-sm">
            <input type="checkbox" name="consent" value="1" class="mt-1" @checked(old('consent'))>
            <span>
                <strong>Help teach the scanner.</strong> Keep the pictures of the cars I confirm, with what they are,
                to train collectAI's own recognizer. They are never published or shared outside collectAI, and you can
                delete a scan at any time. Unticked, the pictures are deleted once you finish the scan.
                <a href="{{ route('privacy') }}" class="underline">Privacy policy</a>
            </span>
        </label>

        <button type="submit" class="rounded-lg bg-neutral-900 px-4 py-2 font-medium text-white dark:bg-white dark:text-neutral-900">Scan</button>
    </form>

    <div class="mt-6 rounded-xl border border-neutral-200 p-4 text-sm text-neutral-600 dark:border-neutral-800 dark:text-neutral-400">
        <h2 class="font-medium text-neutral-900 dark:text-neutral-100">What it can and can't do yet</h2>
        <ul class="mt-2 list-disc space-y-1 pl-5">
            <li><strong>Bases:</strong> the casting name, © year and code printed underneath are read, so upside-down cars are identified best.</li>
            <li><strong>Cards:</strong> the name and toy number on the card are read.</li>
            <li><strong>Loose cars right way up:</strong> each one is found and its colour noted, and the scanner shows what it looks most like — but it won't name a car by looks alone until it has learned enough to be sure. Flip them over, or name them yourself on the next screen.</li>
            <li>Everything runs on collectAI's own server. No photo is sent to any outside service.</li>
        </ul>
    </div>

    @if ($scans->isNotEmpty())
        <h2 class="mt-10 text-lg font-semibold">Recent scans</h2>
        <ul class="mt-3 divide-y divide-neutral-200 rounded-xl border border-neutral-200 bg-white dark:divide-neutral-800 dark:border-neutral-800 dark:bg-neutral-900">
            @foreach ($scans as $scan)
                <li class="flex items-center justify-between gap-3 px-4 py-3 text-sm">
                    <a href="{{ route('scan.show', $scan) }}" class="hover:underline">
                        {{ $scan->created_at->format('j M Y, H:i') }} — {{ $scan->detections_count }} found,
                        {{ $scan->confident_count }} identified, {{ $scan->added_count }} added
                    </a>
                    <form method="POST" action="{{ route('scan.destroy', $scan) }}">
                        @csrf @method('DELETE')
                        <button type="submit" class="text-red-700 underline dark:text-red-300">Delete</button>
                    </form>
                </li>
            @endforeach
        </ul>
    @endif
</x-layouts.app>
