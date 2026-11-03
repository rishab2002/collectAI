<x-layouts.app title="Review queue">
    {{-- Title and actions stack on a phone: three buttons beside the title
         squeezed it into a column and broke "Add a car" over two lines. --}}
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-xl font-semibold">Review queue</h1>
        <div class="flex flex-wrap items-center gap-2 whitespace-nowrap">
            <a
                href="{{ route('admin.catalog.index') }}"
                class="rounded-lg border border-neutral-300 px-3 py-2 text-sm font-medium dark:border-neutral-700"
            >
                Catalog
            </a>
            <a
                href="{{ route('admin.castings.create') }}"
                class="rounded-lg border border-neutral-300 px-3 py-2 text-sm font-medium dark:border-neutral-700"
            >
                Add a car
            </a>
            <a
                href="{{ route('admin.capture') }}"
                class="rounded-lg bg-neutral-900 px-3 py-2 text-sm font-medium text-white dark:bg-white dark:text-neutral-900"
            >
                New capture
            </a>
        </div>
    </div>

    @if ($inferredCount > 0)
        {{-- A separate queue rather than more rows in the list below: these have
             no photos, and the thing a reviewer checks them against is the source
             page they were imported from. --}}
        <a
            href="{{ route('admin.proposals.index') }}"
            class="mt-4 flex items-baseline justify-between gap-4 rounded-xl border border-neutral-200 bg-white p-4 hover:border-neutral-400 dark:border-neutral-800 dark:bg-neutral-900 dark:hover:border-neutral-600"
        >
            <span>
                <span class="font-medium">Inferred records</span>
                <span class="mt-1 block text-sm text-neutral-500">
                    Make and model the pipeline worked out from imported casting names
                </span>
            </span>
            <span class="shrink-0 text-sm text-neutral-500">{{ $inferredCount }} pending</span>
        </a>
    @endif

    @if ($submissions->isEmpty())
        <p class="mt-4 text-sm text-neutral-500">No captures awaiting review.</p>
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
