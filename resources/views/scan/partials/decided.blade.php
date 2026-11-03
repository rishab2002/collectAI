<p class="mt-3 text-sm {{ $detection->decision === 'added' ? 'text-green-800 dark:text-green-200' : 'text-neutral-500' }}">
    @if ($detection->decision === 'added')
        ✓ Added: {{ $detection->variation?->release->castingVersion->casting->name }} ({{ $detection->variation?->color ?? 'colour not recorded' }})
    @else
        Skipped.
    @endif
</p>
