<x-layouts.app title="Review queue">
    <h1 class="text-xl font-semibold">Review queue</h1>

    @if ($submissions->isEmpty())
        <p class="mt-4 text-sm text-neutral-500">Nothing awaiting review.</p>
    @else
        <ul class="mt-4 space-y-3">
            @foreach ($submissions as $submission)
                <li class="rounded-xl border border-neutral-200 bg-white p-4 dark:border-neutral-800 dark:bg-neutral-900">
                    <div class="flex items-baseline justify-between gap-4">
                        <span class="font-medium">
                            {{ $submission->ocr_output['casting_name'] ?? 'Submission #'.$submission->id }}
                        </span>
                        <span class="text-sm text-neutral-500">
                            {{ $submission->undecided_count }} pending
                        </span>
                    </div>

                    <p class="mt-1 text-sm text-neutral-500">
                        {{ collect([$submission->hint_brand, $submission->hint_year, $submission->hint_series])
                            ->filter()
                            ->implode(' · ') ?: 'No capture hints' }}
                    </p>
                </li>
            @endforeach
        </ul>
    @endif
</x-layouts.app>
