{{--
    "Hot Wheels · 2010–2026" over "Mainline / HW J-Imports · Car Culture / Deluxe
    +12 more". Built by CastingSummaries; null when the casting has no releases
    (a hand-made casting before its first variation, say).
--}}
@props(['summary'])

@if ($summary)
    <span {{ $attributes->class('block text-sm text-neutral-500') }}>
        <span class="block truncate">
            {{ implode(' / ', $summary['brands']) }} · {{ $summary['years'] }}
        </span>
        <span class="block truncate">
            {{ implode(' · ', $summary['paths']) }}@if ($summary['more'] > 0) <span class="text-neutral-400">+{{ $summary['more'] }} more</span>@endif
        </span>
    </span>
@endif
