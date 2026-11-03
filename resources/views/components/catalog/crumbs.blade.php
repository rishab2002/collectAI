{{-- Breadcrumbs: label => url, the last one being the current page. --}}
@props(['items' => []])

<nav aria-label="Breadcrumb" class="mb-2 text-sm text-neutral-500">
    <ol class="flex flex-wrap items-center gap-x-1">
        @foreach ($items as $label => $url)
            <li class="flex items-center gap-x-1">
                @if (! $loop->first)<span aria-hidden="true">›</span>@endif
                @if ($url && ! $loop->last)
                    <a href="{{ $url }}" class="hover:text-neutral-900 hover:underline dark:hover:text-neutral-100">{{ $label }}</a>
                @else
                    <span @if ($loop->last) aria-current="page" class="text-neutral-700 dark:text-neutral-300" @endif>{{ $label }}</span>
                @endif
            </li>
        @endforeach
    </ol>
</nav>
