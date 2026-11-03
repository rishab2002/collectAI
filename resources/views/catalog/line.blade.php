<x-layouts.app :title="$line->name.' — '.$line->brand->name">
    <x-catalog.preview-banner :preview="$preview" />
    <x-catalog.crumbs :items="[
        'Catalog' => route('home'),
        $line->brand->name => route('catalog.brand', $line->brand),
        $line->name => null,
    ]" />

    <h1 class="text-2xl font-semibold">{{ $line->name }}</h1>
    <p class="mt-1 text-neutral-500">
        {{ $line->brand->name }} · choose a series{{ $year ? ' from '.$year : '' }}.
    </p>

    {{-- Mainline alone spans sixty years and hundreds of series; a year is how
         a collector narrows it. Scrolls sideways on a phone. --}}
    @if (count($years) > 1)
        <nav aria-label="Filter by year" class="-mx-4 mt-4 overflow-x-auto px-4">
            <ul class="flex gap-2 pb-1 text-sm">
                <li>
                    <a href="{{ route('catalog.line', $line) }}" @class([
                        'block whitespace-nowrap rounded-full border px-3 py-1',
                        'border-neutral-900 bg-neutral-900 text-white dark:border-white dark:bg-white dark:text-neutral-900' => $year === null,
                        'border-neutral-300 dark:border-neutral-700' => $year !== null,
                    ])>All years</a>
                </li>
                @foreach ($years as $option)
                    <li>
                        <a href="{{ route('catalog.line', [$line, 'year' => $option]) }}" @class([
                            'block whitespace-nowrap rounded-full border px-3 py-1',
                            'border-neutral-900 bg-neutral-900 text-white dark:border-white dark:bg-white dark:text-neutral-900' => $year === $option,
                            'border-neutral-300 dark:border-neutral-700' => $year !== $option,
                        ])>{{ $option }}</a>
                    </li>
                @endforeach
            </ul>
        </nav>
    @endif

    @if ($subSeries->isEmpty())
        <p class="mt-6 text-sm text-neutral-500">Nothing in {{ $line->name }} from {{ $year }}.</p>
    @else
        <ul class="mt-4 divide-y divide-neutral-200 rounded-xl border border-neutral-200 bg-white dark:divide-neutral-800 dark:border-neutral-800 dark:bg-neutral-900">
            @foreach ($subSeries as $sub)
                <li>
                    <a href="{{ route('catalog.line', array_filter([$line->id, 'sub' => $sub->name, 'year' => $year])) }}" class="flex items-center justify-between gap-4 px-4 py-3 hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
                        <span class="min-w-0">
                            <span class="block font-medium">
                                {{-- A group sharing the line's name is cars the wiki
                                     filed under the line with no sub-series. --}}
                                @if (mb_strtolower($sub->name) === mb_strtolower($line->name))
                                    {{ $line->name }} <span class="font-normal text-neutral-500">(no sub-series)</span>
                                @else
                                    {{ $sub->name }}
                                @endif
                            </span>
                            <span class="block text-sm text-neutral-500">
                                {{ $sub->first_year === $sub->last_year ? $sub->first_year : $sub->first_year.'–'.$sub->last_year }}
                                · {{ number_format($sub->cars) }} {{ Str::plural('casting', $sub->cars) }}
                            </span>
                        </span>
                        <span aria-hidden="true" class="text-neutral-400">›</span>
                    </a>
                </li>
            @endforeach
        </ul>
    @endif
</x-layouts.app>
