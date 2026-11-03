@php
    $subLabel = mb_strtolower($sub) === mb_strtolower($line->name) ? $line->name.' (no sub-series)' : $sub;
@endphp

<x-layouts.app :title="$subLabel.' — '.$line->brand->name">
    <x-catalog.preview-banner :preview="$preview" />
    <x-catalog.crumbs :items="[
        'Catalog' => route('home'),
        $line->brand->name => route('catalog.brand', $line->brand),
        $line->name => route('catalog.line', array_filter([$line->id, 'year' => $year])),
        $subLabel => null,
    ]" />

    <h1 class="text-2xl font-semibold">{{ $subLabel }}</h1>
    <p class="mt-1 text-neutral-500">
        {{ $line->brand->name }} · {{ $line->name }}{{ $year ? ' · '.$year : '' }} ·
        {{ number_format($cars->total()) }} {{ Str::plural('casting', $cars->total()) }}
    </p>

    @if ($cars->isEmpty())
        <p class="mt-6 text-sm text-neutral-500">Nothing here{{ $year ? ' from '.$year : '' }}.</p>
    @else
        <ul class="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-3">
            @foreach ($cars as $car)
                @php($casting = $castings[$car->casting_id] ?? null)
                @continue($casting === null)
                <li>
                    <a href="{{ route('catalog.casting', $casting->id) }}" class="block h-full overflow-hidden rounded-xl border border-neutral-200 bg-white hover:border-neutral-400 dark:border-neutral-800 dark:bg-neutral-900 dark:hover:border-neutral-600">
                        @if ($photo = $photos[$casting->id] ?? null)
                            <img src="{{ $photo->url() }}" alt="{{ $casting->name }}" loading="lazy" class="aspect-[4/3] w-full bg-neutral-100 object-cover dark:bg-neutral-800">
                        @else
                            {{-- No photo yet is the common case; say so plainly
                                 rather than leave a broken image. --}}
                            <span class="grid aspect-[4/3] w-full place-items-center bg-neutral-100 text-xs text-neutral-400 dark:bg-neutral-800">No photo yet</span>
                        @endif
                        <span class="block p-3">
                            <span class="block text-sm font-medium leading-snug">{{ $casting->name }}</span>
                            <span class="mt-1 block text-xs text-neutral-500">
                                {{ $car->first_year === $car->last_year ? $car->first_year : $car->first_year.'–'.$car->last_year }}
                                · {{ $car->variations }} {{ Str::plural('version', $car->variations) }}
                            </span>
                        </span>
                    </a>
                </li>
            @endforeach
        </ul>

        <div class="mt-6">{{ $cars->links() }}</div>
    @endif
</x-layouts.app>
