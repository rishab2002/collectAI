@php
    $releases = $casting->versions->flatMap->releases->sortByDesc('year')->values();
    $years = $releases->pluck('year');
    $yearRange = $years->min() === $years->max() ? $years->min() : $years->min().'–'.$years->max();
    $hero = $releases->flatMap->variations->flatMap->photos->sortByDesc('is_primary')->first();
    // The breadcrumb follows the line holding most of this car's releases —
    // its main home — rather than whichever release happens to be oldest.
    $home = $releases
        ->groupBy(fn ($release) => ($release->series->parent ?? $release->series)->id)
        ->sortByDesc(fn ($group) => $group->count())
        ->first()?->first();
    $labels = [
        'color' => 'Colour', 'finish' => 'Finish', 'rim_type' => 'Wheels', 'tampo' => 'Tampo',
        'interior_color' => 'Interior', 'window_tint' => 'Windows', 'base_color' => 'Base',
        'toy_number' => 'Toy #', 'country' => 'Made in',
    ];
@endphp

<x-layouts.app :title="$casting->name">
    <x-catalog.preview-banner :preview="$preview" />
    <x-catalog.flash />
    @if ($home)
        <x-catalog.crumbs :items="[
            'Catalog' => route('home'),
            $home->series->brand->name => route('catalog.brand', $home->series->brand),
            ($home->series->parent ?? $home->series)->name => route('catalog.line', $home->series->parent ?? $home->series),
            $casting->name => null,
        ]" />
    @endif

    <h1 class="text-2xl font-semibold">{{ $casting->name }}</h1>
    <p class="mt-1 text-neutral-500">
        @if ($casting->is_fantasy)
            Original design — no real car behind it
        @elseif ($casting->model)
            {{ $casting->model->make->name }} {{ $casting->model->fullName() }}
        @else
            Real car, not yet identified
        @endif
        · {{ $yearRange }} · {{ $releases->count() }} {{ Str::plural('release', $releases->count()) }}
    </p>

    @if ($hero)
        <figure class="mt-4 overflow-hidden rounded-xl border border-neutral-200 bg-white dark:border-neutral-800 dark:bg-neutral-900">
            <img src="{{ $hero->url() }}" alt="{{ $casting->name }}" class="max-h-96 w-full bg-neutral-100 object-contain dark:bg-neutral-800">
            @if ($hero->needsCredit())
                {{-- The licence the photo was imported under requires the
                     author, the licence and the source wherever it is shown. --}}
                <figcaption class="px-4 py-2 text-xs text-neutral-500">
                    Photo: {{ $hero->credit ?? 'unknown author' }} · {{ $hero->license }}
                    @if ($hero->source)
                        · <a href="{{ $hero->source->source_url }}" class="underline" rel="noopener" target="_blank">source</a>
                    @endif
                </figcaption>
            @endif
        </figure>
    @else
        <p class="mt-4 grid h-40 place-items-center rounded-xl border border-dashed border-neutral-300 text-sm text-neutral-400 dark:border-neutral-700">No photo yet</p>
    @endif

    <h2 class="mt-8 text-lg font-semibold">Releases</h2>

    <div class="mt-3 space-y-3">
        @foreach ($releases as $release)
            <section class="rounded-xl border border-neutral-200 bg-white dark:border-neutral-800 dark:bg-neutral-900">
                <header class="border-b border-neutral-200 px-4 py-3 dark:border-neutral-800">
                    <span class="block font-medium">{{ $release->year }} · {{ $release->series->path() }}</span>
                    <span class="block text-sm text-neutral-500">
                        {{ $release->series->brand->name }}
                        @if ($release->collector_number) · #{{ $release->collector_number }} @endif
                    </span>
                </header>
                <ul class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    @foreach ($release->variations as $variation)
                        @php($price = $prices[$variation->id] ?? null)
                        @php($item = $owned[$variation->id] ?? null)
                        <li class="px-4 py-3">
                            <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-0.5 text-sm">
                                @foreach ($labels as $attribute => $label)
                                    @if (filled($variation->{$attribute}))
                                        <dt class="text-neutral-500">{{ $label }}</dt>
                                        <dd class="min-w-0 break-words">{{ $variation->{$attribute} }}</dd>
                                    @endif
                                @endforeach
                            </dl>

                            @if ($price)
                                {{-- The kind of price is always shown with it: a sold
                                     price, an asking price and an MSRP are three
                                     different claims. --}}
                                <p class="mt-2 text-sm">
                                    <strong>${{ number_format($price->price, 2) }}</strong>
                                    <span class="text-xs text-neutral-500">
                                        {{ $price->label() }} · {{ $price->observed_at->format('M Y') }}
                                        @if ($price->url) · <a href="{{ $price->url }}" class="underline" rel="noopener nofollow" target="_blank">see listings</a> @endif
                                    </span>
                                </p>
                            @endif

                            <div class="mt-3 flex flex-wrap items-center gap-2 text-sm">
                                @auth
                                    @if ($item)
                                        <a href="{{ route('collection.index') }}" class="rounded-lg bg-green-100 px-3 py-1 text-green-800 dark:bg-green-950 dark:text-green-200">In your collection ×{{ $item->quantity }}</a>
                                    @else
                                        <form method="POST" action="{{ route('collection.store') }}">
                                            @csrf
                                            <input type="hidden" name="variation_id" value="{{ $variation->id }}">
                                            <button type="submit" class="rounded-lg bg-neutral-900 px-3 py-1 font-medium text-white dark:bg-white dark:text-neutral-900">Add to my collection</button>
                                        </form>
                                        @if (isset($wanted[$variation->id]))
                                            <form method="POST" action="{{ route('wishlist.destroy', $variation->id) }}">
                                                @csrf @method('DELETE')
                                                <button type="submit" class="rounded-lg border border-neutral-300 px-3 py-1 dark:border-neutral-700">★ Wanted</button>
                                            </form>
                                        @else
                                            <form method="POST" action="{{ route('wishlist.store') }}">
                                                @csrf
                                                <input type="hidden" name="variation_id" value="{{ $variation->id }}">
                                                <button type="submit" class="rounded-lg border border-neutral-300 px-3 py-1 dark:border-neutral-700">☆ Want it</button>
                                            </form>
                                        @endif
                                    @endif
                                @else
                                    <a href="{{ route('register') }}" class="text-neutral-500 underline">Join to track this car in your collection</a>
                                @endauth
                            </div>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endforeach
    </div>

    @if ($casting->source)
        {{-- Facts imported from a CC-BY-SA wiki owe it attribution wherever the
             public catalog shows them. --}}
        <p class="mt-8 text-xs text-neutral-500">
            Release data from the
            <a href="{{ $casting->source->source_url }}" class="underline" rel="noopener" target="_blank">{{ parse_url($casting->source->source_url, PHP_URL_HOST) }}</a>
            article, used under <a href="https://creativecommons.org/licenses/by-sa/3.0/" class="underline" rel="noopener" target="_blank">CC BY-SA</a>.
        </p>
    @endif
</x-layouts.app>
