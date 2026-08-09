<x-layouts.app title="Review queue">
    <div class="flex items-baseline justify-between gap-4">
        <h1 class="text-xl font-semibold">Review queue</h1>
        <a
            href="{{ route('admin.capture') }}"
            class="rounded-lg bg-neutral-900 px-3 py-2 text-sm font-medium text-white dark:bg-white dark:text-neutral-900"
        >
            New capture
        </a>
    </div>

    @if ($submissions->isEmpty())
        <p class="mt-4 text-sm text-neutral-500">Nothing awaiting review.</p>
    @else
        <ul class="mt-4 space-y-3">
            @foreach ($submissions as $submission)
                <li>
                    <a
                        href="{{ route('admin.submissions.show', $submission) }}"
                        class="block rounded-xl border border-neutral-200 bg-white p-4 hover:border-neutral-400 dark:border-neutral-800 dark:bg-neutral-900 dark:hover:border-neutral-600"
                    >
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
                    </a>
                </li>
            @endforeach
        </ul>
    @endif
</x-layouts.app>
