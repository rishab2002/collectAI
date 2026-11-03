<x-layouts.app title="Scan results">
    <a href="{{ route('scan.index') }}" class="text-sm text-neutral-500 hover:underline">← Scan more</a>
    <h1 class="mt-2 text-2xl font-semibold">Scan results</h1>
    <p class="mt-2 text-neutral-500">
        {{ $scan->detections->count() }} {{ Str::plural('car', $scan->detections->count()) }} found in
        {{ $scan->photos }} {{ Str::plural('photo', $scan->photos) }} ·
        <strong class="text-neutral-900 dark:text-neutral-100">{{ $confident->count() }} identified</strong>
    </p>

    <x-catalog.flash />

    @if ($scan->error)
        <p class="mt-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200">{{ $scan->error }}</p>
    @endif

    @if ($confident->isNotEmpty())
        <h2 class="mt-8 text-lg font-semibold">Identified — confirm each one</h2>
        <p class="mt-1 text-sm text-neutral-500">The casting is what the scanner is sure of. Pick the version in your hand; the ones whose colour matches the photo come first.</p>

        <ul class="mt-4 space-y-4">
            @foreach ($confident as $detection)
                <li id="d{{ $detection->id }}" class="rounded-xl border border-neutral-200 bg-white p-3 dark:border-neutral-800 dark:bg-neutral-900">
                    <div class="flex gap-3">
                        @include('scan.partials.crop', ['detection' => $detection])
                        <div class="min-w-0 flex-1">
                            <a href="{{ route('catalog.casting', $detection->casting_id) }}" class="block font-medium hover:underline">{{ $detection->casting?->name ?? $detection->match['casting_name'] }}</a>
                            <span class="block text-sm text-neutral-500">
                                {{ number_format($detection->confidence * 100) }}% sure · photo {{ $detection->photo }}, car {{ $detection->number }}
                                @if ($detection->colour_family) · looks {{ $detection->colour_family }}{{ $scan->layout !== 'single' && ($detection->reading['lines'] ?? []) ? ' (base or body)' : '' }} @endif
                            </span>
                            <span class="block text-xs text-neutral-500">{{ implode(' · ', $detection->match['reasons'] ?? []) }}</span>
                        </div>
                    </div>

                    @if ($detection->isPending())
                        @php($options = collect($detection->offeredVariationIds())->map(fn ($id) => $variations[$id] ?? null)->filter())
                        @if ($options->isEmpty())
                            <p class="mt-3 text-sm text-neutral-500">None of this casting's versions are published yet. <a href="{{ route('scan.show', ['scan' => $scan, 'label' => $detection->id]) }}#d{{ $detection->id }}" class="underline">Find it another way</a>.</p>
                        @else
                            <form method="POST" action="{{ route('scan.add', [$scan, $detection]) }}" class="mt-3">
                                @csrf
                                <fieldset class="space-y-2">
                                    <legend class="sr-only">Which version?</legend>
                                    @foreach ($options as $i => $variation)
                                        @php($offer = collect($detection->match['variations'])->firstWhere('variation_id', $variation->id))
                                        <label class="flex items-center gap-3 rounded-lg border border-neutral-200 p-2 text-sm dark:border-neutral-800">
                                            <input type="radio" name="variation_id" value="{{ $variation->id }}" @checked($i === 0) required>
                                            @if ($photo = $variation->photos->first())
                                                <img src="{{ $photo->url() }}" alt="" class="size-12 shrink-0 rounded bg-neutral-100 object-cover dark:bg-neutral-800">
                                            @endif
                                            <span class="min-w-0">
                                                <span class="block">{{ $variation->release->year }} · {{ $variation->color ?? 'colour not recorded' }}
                                                    @if (($offer['colour_match'] ?? 0) >= 1) <span class="rounded bg-green-100 px-1 text-xs text-green-800 dark:bg-green-950 dark:text-green-200">{{ ($offer['colour_matched_on'] ?? 'body') === 'base' ? 'base colour matches' : 'colour matches' }}</span>
                                                    @elseif (($offer['colour_match'] ?? 0) > 0) <span class="rounded bg-neutral-100 px-1 text-xs text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300">similar colour</span>
                                                    @endif
                                                    @if ($offer['code_match'] ?? false) <span class="rounded bg-green-100 px-1 text-xs text-green-800 dark:bg-green-950 dark:text-green-200">code matches</span> @endif
                                                </span>
                                                <span class="block text-xs text-neutral-500">{{ collect([$variation->rim_type, $variation->release->series->path(), $variation->toy_number])->filter()->implode(' · ') }}
                                                    @if ($owned[$variation->id] ?? false) · you have {{ $owned[$variation->id] }} @endif</span>
                                            </span>
                                        </label>
                                    @endforeach
                                </fieldset>
                                <div class="mt-3 flex flex-wrap items-center gap-3">
                                    <button type="submit" class="rounded-lg bg-neutral-900 px-4 py-2 text-sm font-medium text-white dark:bg-white dark:text-neutral-900">Add to my collection</button>
                                    <a href="{{ route('scan.show', ['scan' => $scan, 'label' => $detection->id]) }}#d{{ $detection->id }}" class="text-sm underline">It's a different car</a>
                                </div>
                            </form>
                        @endif
                        <form method="POST" action="{{ route('scan.reject', [$scan, $detection]) }}" class="mt-2">
                            @csrf
                            <button type="submit" class="text-sm text-neutral-500 underline">Skip</button>
                        </form>
                        @if ($labelling === $detection->id)
                            @include('scan.partials.label', ['detection' => $detection])
                        @endif
                    @else
                        @include('scan.partials.decided', ['detection' => $detection])
                    @endif
                </li>
            @endforeach
        </ul>
    @endif

    @if ($unnamed->isNotEmpty())
        <h2 class="mt-10 text-lg font-semibold">Found, but not sure what they are</h2>
        <p class="mt-1 text-sm text-neutral-500">
            The scanner won't guess. Turn these over and scan the bases, or name them here{{ $scan->consent ? ' — each one you name teaches the scanner.' : '.' }}
        </p>

        <ul class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
            @foreach ($unnamed as $detection)
                <li id="d{{ $detection->id }}" class="rounded-xl border border-neutral-200 bg-white p-3 dark:border-neutral-800 dark:bg-neutral-900 {{ $labelling === $detection->id ? 'sm:col-span-2' : '' }}">
                    <div class="flex gap-3">
                        @include('scan.partials.crop', ['detection' => $detection])
                        <div class="min-w-0 flex-1 text-sm">
                            <span class="block">Photo {{ $detection->photo }}, car {{ $detection->number }}@if ($detection->colour_family) · {{ $detection->colour_family }}@endif</span>
                            @if ($lines = $detection->reading['lines'] ?? [])
                                <span class="block truncate text-xs text-neutral-500">Read: “{{ Str::limit(implode(' / ', $lines), 80) }}”</span>
                            @endif
                            @if ($best = $detection->match['candidates'][0] ?? null)
                                <span class="block text-xs text-neutral-500">Maybe {{ $best['name'] }}? Not sure enough to say.</span>
                            @endif
                            @if ($lookalike = $lookalikes[$detection->match['looks']['candidates'][0]['casting_id'] ?? 0] ?? null)
                                <span class="block text-xs text-neutral-500">Looks most like
                                    <a href="{{ route('scan.show', ['scan' => $scan, 'label' => $detection->id, 'q' => $lookalike]) }}#d{{ $detection->id }}" class="underline">{{ $lookalike }}</a>
                                    — not sure enough to say.</span>
                            @endif
                        </div>
                    </div>
                    @if ($detection->isPending())
                        <div class="mt-2 flex flex-wrap gap-3 text-sm">
                            <a href="{{ route('scan.show', ['scan' => $scan, 'label' => $detection->id]) }}#d{{ $detection->id }}" class="underline">Name it</a>
                            <form method="POST" action="{{ route('scan.reject', [$scan, $detection]) }}">
                                @csrf
                                <button type="submit" class="text-neutral-500 underline">Skip</button>
                            </form>
                        </div>
                        @if ($labelling === $detection->id)
                            @include('scan.partials.label', ['detection' => $detection])
                        @endif
                    @else
                        @include('scan.partials.decided', ['detection' => $detection])
                    @endif
                </li>
            @endforeach
        </ul>
    @endif

    @if ($scan->detections->isEmpty())
        <p class="mt-6 rounded-xl border border-neutral-200 bg-white p-4 text-sm text-neutral-500 dark:border-neutral-800 dark:bg-neutral-900">
            No cars stood out from the background. Try a plain, contrasting surface with the cars spread apart, or choose “One car close up”.
        </p>
    @endif
</x-layouts.app>
