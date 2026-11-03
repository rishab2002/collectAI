@php
    $releases = $casting->versions->flatMap->releases;
    $latest = $releases->sortByDesc('year')->first();
    $variationLabels = [
        'color' => 'Colour',
        'finish' => 'Finish',
        'rim_type' => 'Wheels',
        'tampo' => 'Tampo',
        'interior_color' => 'Interior',
        'window_tint' => 'Windows',
        'base_color' => 'Base',
        'toy_number' => 'Toy #',
        'country' => 'Country',
        'notes' => 'Notes',
    ];
@endphp

<x-layouts.app :title="$casting->name">
    <div class="flex items-baseline justify-between gap-4">
        <a href="{{ route('admin.catalog.index') }}" class="text-sm text-neutral-500 hover:text-neutral-900 dark:hover:text-neutral-100">← Catalog</a>
        <a href="{{ route('admin.castings.create') }}" class="text-sm text-neutral-500 hover:text-neutral-900 dark:hover:text-neutral-100">Add a different car</a>
    </div>

    <div class="mt-3 flex items-start justify-between gap-4">
        <div class="min-w-0">
            <h1 class="text-xl font-semibold">{{ $casting->name }}</h1>
            <p class="mt-1 text-sm text-neutral-500">
                @if ($casting->is_fantasy)
                    Fantasy casting
                @elseif ($casting->model)
                    {{ $casting->model->make->name }} {{ $casting->model->fullName() }}
                @else
                    Real car, not identified yet — its releases stay pending until it is.
                @endif
            </p>
            @if ($casting->source)
                {{-- The wiki is CC-BY-SA; attribution rides on record_sources.source_url. --}}
                <p class="mt-1 text-sm text-neutral-500">
                    Imported from <a href="{{ $casting->source->source_url }}" class="underline" rel="noopener" target="_blank">{{ parse_url($casting->source->source_url, PHP_URL_HOST) }}</a>
                </p>
            @endif
        </div>
        <x-catalog.status :status="$casting->status" />
    </div>

    <x-catalog.flash />

    <p class="mt-4 text-sm text-neutral-500">
        {{ $releases->count() }} {{ Str::plural('release', $releases->count()) }},
        @php($variationCount = $releases->sum(fn ($release) => $release->variations->count()))
        {{ $variationCount }} {{ Str::plural('variation', $variationCount) }}
    </p>

    <div class="mt-4 space-y-4">
        @forelse ($casting->versions as $version)
            @if ($casting->versions->count() > 1)
                <h2 class="pt-2 text-sm font-semibold uppercase tracking-wide text-neutral-500">
                    Tooling {{ $version->version_number }}{{ $version->notes ? ' — '.$version->notes : '' }}
                    @if ($version->retool_year) · retooled {{ $version->retool_year }} @endif
                </h2>
            @endif

            @foreach ($version->releases as $release)
                <section class="rounded-xl border border-neutral-200 bg-white dark:border-neutral-800 dark:bg-neutral-900">
                    <header class="flex items-baseline justify-between gap-4 border-b border-neutral-200 px-4 py-3 dark:border-neutral-800">
                        <span class="min-w-0">
                            <span class="font-medium">{{ $release->year }} · {{ $release->series->name }}</span>
                            <span class="block text-sm text-neutral-500">
                                {{ $release->series->brand->name }}
                                @if ($release->collector_number) · #{{ $release->collector_number }} @endif
                            </span>
                        </span>
                        <x-catalog.status :status="$release->status" />
                    </header>

                    <ul class="divide-y divide-neutral-200 dark:divide-neutral-800">
                        @foreach ($release->variations as $variation)
                            <li id="variation-{{ $variation->id }}" class="space-y-3 px-4 py-3">
                                <div class="flex items-start justify-between gap-4">
                                    <dl class="grid min-w-0 grid-cols-[auto_1fr] gap-x-3 gap-y-0.5 text-sm">
                                        @foreach ($variationLabels as $attribute => $label)
                                            @if (filled($variation->{$attribute}))
                                                <dt class="text-neutral-500">{{ $label }}</dt>
                                                <dd class="min-w-0 break-words">{{ $variation->{$attribute} }}</dd>
                                            @endif
                                        @endforeach
                                    </dl>
                                    <x-catalog.status :status="$variation->status" />
                                </div>

                                @if ($variation->photos->isNotEmpty())
                                    <ul class="flex flex-wrap gap-3">
                                        @foreach ($variation->photos as $photo)
                                            <li class="w-28 space-y-1">
                                                <a href="{{ $photo->url() }}" target="_blank" rel="noopener">
                                                    <img
                                                        src="{{ $photo->url() }}"
                                                        alt="{{ $casting->name }}, {{ str_replace('_', ' ', $photo->type ?? 'photo') }}"
                                                        loading="lazy"
                                                        @class([
                                                            'aspect-square w-28 rounded-lg bg-neutral-100 object-cover dark:bg-neutral-800',
                                                            'ring-2 ring-neutral-900 dark:ring-white' => $photo->is_primary,
                                                        ])
                                                    >
                                                </a>
                                                <div class="flex justify-between text-xs">
                                                    @if ($photo->is_primary)
                                                        <span class="text-neutral-500">Primary</span>
                                                    @else
                                                        <form method="POST" action="{{ route('admin.variations.photos.primary', [$variation, $photo]) }}">
                                                            @csrf
                                                            <button type="submit" class="text-neutral-500 underline">Make primary</button>
                                                        </form>
                                                    @endif
                                                    <form method="POST" action="{{ route('admin.variations.photos.destroy', [$variation, $photo]) }}">
                                                        @csrf
                                                        @method('DELETE')
                                                        <button type="submit" class="text-red-700 underline dark:text-red-300">Remove</button>
                                                    </form>
                                                </div>
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif

                                <details>
                                    <summary class="cursor-pointer text-sm text-neutral-500">Add photos</summary>
                                    <form method="POST" action="{{ route('admin.variations.photos.store', $variation) }}" enctype="multipart/form-data" class="mt-3 space-y-3">
                                        @csrf
                                        <x-catalog.photo-slots compact />
                                        <button type="submit" class="rounded-lg border border-neutral-300 px-3 py-2 text-sm font-medium dark:border-neutral-700">Upload</button>
                                    </form>
                                </details>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endforeach
        @empty
            <p class="text-sm text-neutral-500">No releases yet.</p>
        @endforelse
    </div>

    <form
        id="add-variation"
        method="POST"
        action="{{ route('admin.castings.variations.store', $casting) }}"
        enctype="multipart/form-data"
        class="mt-8 space-y-6"
    >
        @csrf
        <h2 class="text-lg font-semibold">Add a release or variation</h2>
        <p class="text-sm text-neutral-500">
            A new colour, a new year, or this tool sold under another brand.
            @unless ($casting->isUnidentified())
                It goes live as soon as you save it.
            @else
                It will be saved but held back until the casting is identified.
            @endunless
        </p>

        @if ($casting->versions->count() > 1)
            <div>
                <label for="casting_version_id" class="block text-sm font-medium">Tooling</label>
                <select id="casting_version_id" name="casting_version_id" class="mt-1 w-full rounded-lg border border-neutral-300 bg-white px-3 py-2 dark:border-neutral-700 dark:bg-neutral-950">
                    @foreach ($casting->versions as $version)
                        <option value="{{ $version->id }}" @selected((int) old('casting_version_id', $casting->versions->last()->id) === $version->id)>
                            Tooling {{ $version->version_number }}{{ $version->notes ? ' — '.$version->notes : '' }}
                        </option>
                    @endforeach
                </select>
            </div>
        @endif

        {{-- The usual reason to be on this form is the same series in a new
             colour, so the most recent release is the starting point. --}}
        <x-catalog.variation-fields
            :brands="$brands"
            :defaults="[
                'brand' => $latest?->series->brand->name,
                'series' => $latest?->series->name,
                'year' => $latest?->year,
            ]"
        />

        <div class="rounded-xl border border-neutral-200 bg-white p-4 dark:border-neutral-800 dark:bg-neutral-900">
            <h2 class="font-medium">Photos</h2>
            <p class="mt-1 mb-4 text-sm text-neutral-500">Optional.</p>
            <x-catalog.photo-slots />
        </div>

        <button type="submit" class="w-full rounded-lg bg-neutral-900 px-4 py-3 font-medium text-white dark:bg-white dark:text-neutral-900">
            Add variation
        </button>
    </form>
</x-layouts.app>
