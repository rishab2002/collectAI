<x-layouts.app :title="$brand->name">
    <x-catalog.preview-banner :preview="$preview" />
    <x-catalog.crumbs :items="['Catalog' => route('home'), $brand->name => null]" />

    <h1 class="text-2xl font-semibold">{{ $brand->name }}</h1>
    <p class="mt-1 text-neutral-500">Choose a line.</p>

    <ul class="mt-6 divide-y divide-neutral-200 rounded-xl border border-neutral-200 bg-white dark:divide-neutral-800 dark:border-neutral-800 dark:bg-neutral-900">
        @foreach ($lines as $line)
            <li>
                <a href="{{ route('catalog.line', $line->id) }}" class="flex items-center justify-between gap-4 px-4 py-3 hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
                    <span class="min-w-0">
                        <span class="block font-medium">{{ $line->name }}</span>
                        <span class="block text-sm text-neutral-500">
                            {{ $line->first_year === $line->last_year ? $line->first_year : $line->first_year.'–'.$line->last_year }}
                            · {{ number_format($line->cars) }} {{ Str::plural('casting', $line->cars) }}
                            @if ($line->subseries > 0)
                                · {{ number_format($line->subseries) }} {{ Str::plural('series', $line->subseries) }}
                            @endif
                        </span>
                    </span>
                    <span aria-hidden="true" class="text-neutral-400">›</span>
                </a>
            </li>
        @endforeach
    </ul>
</x-layouts.app>
