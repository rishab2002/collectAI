{{-- Naming a car by hand: search the catalog, pick the version. --}}
<div class="mt-3 rounded-lg border border-neutral-200 p-3 dark:border-neutral-800">
    <form method="GET" action="{{ route('scan.show', $detection->scan_id) }}#d{{ $detection->id }}" class="flex gap-2">
        <input type="hidden" name="label" value="{{ $detection->id }}">
        <label class="sr-only" for="q{{ $detection->id }}">Casting name</label>
        <input id="q{{ $detection->id }}" type="search" name="q" value="{{ request('q') }}" placeholder="Casting name, e.g. Bone Shaker" autofocus
               class="min-w-0 flex-1 rounded-lg border border-neutral-300 px-3 py-1.5 text-sm dark:border-neutral-700 dark:bg-neutral-950">
        <button type="submit" class="rounded-lg border border-neutral-300 px-3 py-1.5 text-sm dark:border-neutral-700">Search</button>
    </form>

    @if (request()->filled('q') && $search->isEmpty())
        <p class="mt-2 text-sm text-neutral-500">No published casting by that name.</p>
    @endif

    @if ($suggestion)
        @include('scan.partials.add-to-catalog', ['detection' => $detection, 'suggestion' => $suggestion, 'vocabulary' => $vocabulary])
    @endif

    @foreach ($search as $casting)
        <p class="mt-3 text-sm font-medium">{{ $casting->name }}</p>
        <ul class="mt-1 space-y-1">
            @foreach ($casting->versions->flatMap->releases as $release)
                @foreach ($release->variations as $variation)
                    <li>
                        <form method="POST" action="{{ route('scan.add', [$detection->scan_id, $detection->id]) }}" class="flex items-center justify-between gap-2 text-sm">
                            @csrf
                            <input type="hidden" name="variation_id" value="{{ $variation->id }}">
                            <span class="min-w-0">{{ $release->year }} · {{ $variation->color ?? '—' }}
                                <span class="text-xs text-neutral-500">{{ collect([$variation->rim_type, $release->series->path()])->filter()->implode(' · ') }}</span></span>
                            <button type="submit" class="shrink-0 rounded border border-neutral-300 px-2 py-0.5 text-xs dark:border-neutral-700">This one</button>
                        </form>
                    </li>
                @endforeach
            @endforeach
        </ul>
    @endforeach
</div>
