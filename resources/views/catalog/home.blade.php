<x-layouts.app title="collectAI — diecast catalog">
    <x-catalog.preview-banner :preview="$preview" />

    <h1 class="text-2xl font-semibold">Diecast catalog</h1>
    <p class="mt-1 text-neutral-500">Pick a brand, then a line, then a series.</p>

    @if ($brands->isEmpty())
        <p class="mt-6 rounded-xl border border-neutral-200 bg-white p-4 text-sm text-neutral-500 dark:border-neutral-800 dark:bg-neutral-900">
            Nothing is published yet.
        </p>
    @else
        <ul class="mt-6 grid gap-3 sm:grid-cols-2">
            @foreach ($brands as $brand)
                <li>
                    <a href="{{ route('catalog.brand', $brand->id) }}" class="block rounded-xl border border-neutral-200 bg-white p-4 hover:border-neutral-400 dark:border-neutral-800 dark:bg-neutral-900 dark:hover:border-neutral-600">
                        <span class="block text-lg font-semibold">{{ $brand->name }}</span>
                        <span class="mt-1 block text-sm text-neutral-500">
                            {{ number_format($brand->cars) }} {{ Str::plural('casting', $brand->cars) }} ·
                            {{ $brand->first_year === $brand->last_year ? $brand->first_year : $brand->first_year.'–'.$brand->last_year }}
                        </span>
                    </a>
                </li>
            @endforeach
        </ul>
    @endif
</x-layouts.app>
