{{-- One labelled text input, repopulated from old() after a failed submit. --}}
@props([
    'name',
    'label',
    'type' => 'text',
    'value' => null,
    'hint' => null,
    'list' => null,
    'required' => false,
    'placeholder' => null,
])

<div {{ $attributes->only('class') }}>
    <label for="{{ $name }}" class="block text-sm font-medium">
        {{ $label }}@if ($required)<span class="text-red-600" aria-hidden="true"> *</span>@endif
    </label>

    <input
        id="{{ $name }}"
        name="{{ $name }}"
        type="{{ $type }}"
        value="{{ old($name, $value) }}"
        autocomplete="off"
        @if ($list) list="{{ $list }}" @endif
        @if ($required) required @endif
        @if ($placeholder) placeholder="{{ $placeholder }}" @endif
        @if ($type === 'number') inputmode="numeric" @endif
        {{ $attributes->except('class') }}
        @class([
            'mt-1 w-full rounded-lg border bg-white px-3 py-2 dark:bg-neutral-950',
            'border-red-400 dark:border-red-700' => $errors->has($name),
            'border-neutral-300 dark:border-neutral-700' => ! $errors->has($name),
        ])
    >

    @if ($hint)
        <p class="mt-1 text-xs text-neutral-500">{{ $hint }}</p>
    @endif

    @error($name)
        <p class="mt-1 text-sm text-red-700 dark:text-red-300">{{ $message }}</p>
    @enderror
</div>
