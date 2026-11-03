{{--
    The same three named slots as the capture screen, as plain file inputs —
    no JavaScript involved, so a broken bundle can't stop a photo being added.
    `capture="environment"` only on the one-shot card slots: on iOS it removes
    the library option and beats `multiple`, which is wrong for loose angles.
--}}
@props(['compact' => false])

@php
    $field = 'mt-1 block w-full text-sm text-neutral-600 file:mr-3 file:rounded-lg file:border-0 file:bg-neutral-100 file:px-3 file:py-2 file:text-sm file:font-medium dark:text-neutral-400 dark:file:bg-neutral-800 dark:file:text-neutral-100';
@endphp

<div {{ $attributes->class([$compact ? 'grid gap-3 sm:grid-cols-3' : 'space-y-4']) }}>
    <label class="block">
        <span class="block text-sm font-medium">Card front</span>
        <input type="file" name="card_front" accept="image/*" capture="environment" class="{{ $field }}">
    </label>

    <label class="block">
        <span class="block text-sm font-medium">Card back</span>
        <input type="file" name="card_back" accept="image/*" capture="environment" class="{{ $field }}">
    </label>

    <label class="block">
        <span class="block text-sm font-medium">Loose car <span class="font-normal text-neutral-500">(up to 6)</span></span>
        <input type="file" name="loose[]" accept="image/*" multiple class="{{ $field }}">
    </label>
</div>
