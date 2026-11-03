<x-layouts.app title="My collection">
    <div class="flex items-baseline justify-between gap-4">
        <h1 class="text-2xl font-semibold">My collection</h1>
        <a href="{{ route('account.show') }}" class="text-sm text-neutral-500 hover:text-neutral-900 dark:hover:text-neutral-100">Account</a>
    </div>

    <x-catalog.flash />

    <p class="mt-2 text-neutral-500">
        {{ $items->sum('quantity') }} {{ Str::plural('car', $items->sum('quantity')) }}
        @if ($pricedCount > 0)
            · worth about <strong class="text-neutral-900 dark:text-neutral-100">${{ number_format($totalValue, 2) }}</strong>
            <span class="text-xs">({{ $pricedCount }} of {{ $items->count() }} priced)</span>
        @endif
    </p>

    @if ($items->isEmpty())
        <p class="mt-6 rounded-xl border border-neutral-200 bg-white p-4 text-sm text-neutral-500 dark:border-neutral-800 dark:bg-neutral-900">
            Nothing here yet. Open any car in the <a href="{{ route('home') }}" class="underline">catalog</a> and choose
            <strong>Add to my collection</strong>.
        </p>
    @else
        <ul class="mt-6 space-y-3">
            @foreach ($items as $item)
                @php($variation = $item->variation)
                @php($casting = $variation->release->castingVersion->casting)
                @php($price = $prices[$variation->id] ?? null)
                <li class="rounded-xl border border-neutral-200 bg-white p-3 dark:border-neutral-800 dark:bg-neutral-900">
                    <div class="flex gap-3">
                        @if ($photo = $variation->photos->first())
                            <img src="{{ $photo->url() }}" alt="" class="size-20 shrink-0 rounded-lg bg-neutral-100 object-cover dark:bg-neutral-800">
                        @else
                            <span class="grid size-20 shrink-0 place-items-center rounded-lg bg-neutral-100 text-xs text-neutral-400 dark:bg-neutral-800">No photo</span>
                        @endif
                        <div class="min-w-0 flex-1">
                            <a href="{{ route('catalog.casting', $casting->id) }}" class="block font-medium hover:underline">{{ $casting->name }}</a>
                            <span class="block text-sm text-neutral-500">
                                {{ $variation->release->series->brand->name }} · {{ $variation->release->year }} · {{ $variation->release->series->path() }}
                            </span>
                            <span class="block text-sm text-neutral-500">{{ collect([$variation->color, $variation->rim_type, $variation->toy_number])->filter()->implode(' · ') }}</span>
                            @if ($price)
                                <span class="mt-1 block text-sm"><strong>${{ number_format($price->price, 2) }}</strong> <span class="text-xs text-neutral-500">{{ $price->label() }}</span></span>
                            @endif
                        </div>
                    </div>

                    <form method="POST" action="{{ route('collection.update', $item->id) }}" class="mt-3 flex flex-wrap items-end gap-2 text-sm">
                        @csrf @method('PATCH')
                        <label class="block">
                            <span class="block text-xs text-neutral-500">Qty</span>
                            <input type="number" name="quantity" min="1" max="999" value="{{ $item->quantity }}" class="w-16 rounded-lg border border-neutral-300 px-2 py-1 dark:border-neutral-700 dark:bg-neutral-950">
                        </label>
                        <label class="block">
                            <span class="block text-xs text-neutral-500">Condition</span>
                            <select name="condition" class="rounded-lg border border-neutral-300 px-2 py-1 dark:border-neutral-700 dark:bg-neutral-950">
                                <option value="">—</option>
                                @foreach ($conditions as $condition)
                                    <option value="{{ $condition->value }}" @selected($item->condition === $condition)>{{ ucfirst($condition->value) }}</option>
                                @endforeach
                            </select>
                        </label>
                        <button type="submit" class="rounded-lg border border-neutral-300 px-3 py-1 dark:border-neutral-700">Save</button>
                    </form>
                    <form method="POST" action="{{ route('collection.destroy', $item->id) }}" class="mt-2 text-sm">
                        @csrf @method('DELETE')
                        <button type="submit" class="text-red-700 underline dark:text-red-300">Remove</button>
                    </form>
                </li>
            @endforeach
        </ul>
    @endif

    <h2 class="mt-10 text-lg font-semibold">Wishlist</h2>
    @if ($wishlist->isEmpty())
        <p class="mt-2 text-sm text-neutral-500">Cars you mark <strong>Want it</strong> appear here.</p>
    @else
        <ul class="mt-3 divide-y divide-neutral-200 rounded-xl border border-neutral-200 bg-white dark:divide-neutral-800 dark:border-neutral-800 dark:bg-neutral-900">
            @foreach ($wishlist as $variation)
                @php($price = $prices[$variation->id] ?? null)
                <li class="flex items-center justify-between gap-3 px-4 py-3">
                    <span class="min-w-0">
                        <a href="{{ route('catalog.casting', $variation->release->castingVersion->casting->id) }}" class="block truncate font-medium hover:underline">{{ $variation->release->castingVersion->casting->name }}</a>
                        <span class="block truncate text-sm text-neutral-500">{{ $variation->release->year }} · {{ $variation->release->series->path() }} · {{ $variation->color }}</span>
                        @if ($price)<span class="block text-sm">${{ number_format($price->price, 2) }} <span class="text-xs text-neutral-500">{{ $price->label() }}</span></span>@endif
                    </span>
                    <form method="POST" action="{{ route('wishlist.destroy', $variation->id) }}">
                        @csrf @method('DELETE')
                        <button type="submit" class="text-sm text-neutral-500 underline">Remove</button>
                    </form>
                </li>
            @endforeach
        </ul>
    @endif
</x-layouts.app>
