<x-layouts.app title="Catalog">
    <div class="flex items-baseline justify-between gap-4">
        <h1 class="text-xl font-semibold">Catalog</h1>
        <a
            href="{{ route('admin.castings.create') }}"
            class="rounded-lg bg-neutral-900 px-3 py-2 text-sm font-medium text-white dark:bg-white dark:text-neutral-900"
        >
            Add a car
        </a>
    </div>

    <form method="GET" action="{{ route('admin.catalog.index') }}" class="mt-4 flex gap-2" role="search">
        <label for="q" class="sr-only">Search castings</label>
        <input
            id="q"
            name="q"
            type="search"
            value="{{ $query }}"
            placeholder="Casting, make or model — “RX-7”, “Bone Shaker”, “Porsche”"
            class="min-w-0 flex-1 rounded-lg border border-neutral-300 bg-white px-3 py-2 dark:border-neutral-700 dark:bg-neutral-950"
        >
        <button type="submit" class="rounded-lg border border-neutral-300 px-4 py-2 font-medium dark:border-neutral-700">Search</button>
    </form>

    <p class="mt-3 text-sm text-neutral-500">
        @if ($query === '')
            {{ number_format($total) }} castings, every status. Pending ones are held back from the public catalog.
        @else
            {{ number_format($castings->total()) }} {{ Str::plural('casting', $castings->total()) }} matching “{{ $query }}”.
        @endif
    </p>

    @if ($castings->isEmpty())
        <p class="mt-6 text-sm text-neutral-500">
            Nothing found. <a href="{{ route('admin.castings.create') }}" class="underline">Add it by hand</a>.
        </p>
    @else
        <ul class="mt-4 divide-y divide-neutral-200 rounded-xl border border-neutral-200 bg-white dark:divide-neutral-800 dark:border-neutral-800 dark:bg-neutral-900">
            @foreach ($castings as $casting)
                <li>
                    <a href="{{ route('admin.castings.show', $casting) }}" class="flex items-baseline justify-between gap-4 px-4 py-3 hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
                        <span class="min-w-0">
                            <span class="block truncate font-medium">{{ $casting->name }}</span>
                            <span class="block truncate text-sm text-neutral-500">
                                {{-- The brand is a fact about a release, not the casting,
                                     so a shared tool lists every brand that sold it. Without
                                     it, a Hot Wheels and a Matchbox tool of the same car read
                                     as a duplicate. --}}
                                @if ($brands->has($casting->id))
                                    {{ implode(' / ', $brands[$casting->id]) }} ·
                                @endif
                                @if ($casting->is_fantasy)
                                    Fantasy casting
                                @elseif ($casting->model)
                                    {{ $casting->model->make->name }} {{ $casting->model->fullName() }}
                                @else
                                    Not identified yet
                                @endif
                            </span>
                        </span>
                        <x-catalog.status :status="$casting->status" />
                    </a>
                </li>
            @endforeach
        </ul>

        <div class="mt-4">{{ $castings->links() }}</div>
    @endif
</x-layouts.app>
