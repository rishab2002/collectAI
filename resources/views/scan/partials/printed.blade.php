{{--
    What the package itself says, as printed — shown whether or not the
    catalog has the car. A package the scanner cannot match is still a car its
    owner can read the name off, and showing them "Read: 'FORD MUSTANG / HW
    EXOTICS / 4/250'" and nothing else makes it look like the scan failed.

    Null everywhere for a loose car: there is no card to read.
--}}
@php
    $card = $detection->reading['card'] ?? null;
    $printed = collect([
        $card['brand'] ?? null,
        $detection->reading['headline'] ?? null,
        $card['collector_number'] ?? null,
        $card['toy_number'] ?? null,
    ])->filter()->all();
@endphp

@if ($printed)
    <span class="block text-sm text-neutral-600 dark:text-neutral-400">
        <span class="text-neutral-500">The card says</span> {{ implode(' · ', $printed) }}
    </span>
@endif
