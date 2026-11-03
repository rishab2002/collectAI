{{--
    For admins: whether the public pages are showing pending records too, and
    the switch. Never rendered for anyone else.
--}}
@props(['preview' => false])

@if (auth()->user()?->is_admin)
    <form method="POST" action="{{ route('catalog.preview') }}" @class([
        'mb-4 flex items-center justify-between gap-3 rounded-xl border px-4 py-2 text-sm',
        'border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200' => $preview,
        'border-neutral-200 bg-white text-neutral-600 dark:border-neutral-800 dark:bg-neutral-900 dark:text-neutral-400' => ! $preview,
    ])>
        @csrf
        <span>
            @if ($preview)
                <strong>Admin preview</strong> — including pending records the public can't see.
            @else
                Showing what the public sees: live records only.
            @endif
        </span>
        <button type="submit" class="shrink-0 underline">{{ $preview ? 'Stop preview' : 'Preview pending' }}</button>
    </form>
@endif
