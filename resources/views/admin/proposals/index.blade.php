@php
    use App\Support\ProposalPresenter;
@endphp

<x-layouts.app title="Inferred records">
    <div class="flex items-baseline justify-between gap-4">
        <h1 class="text-xl font-semibold">Inferred records</h1>
        <a href="{{ route('admin.dashboard') }}" class="text-sm text-neutral-500 hover:text-neutral-900 dark:hover:text-neutral-100">
            Review queue
        </a>
    </div>

    <p class="mt-1 text-sm text-neutral-500">
        {{-- Said plainly, because it is the difference between this screen and
             the submission one: there are no photos to check these against. --}}
        Judgements the pipeline made about imported records. Nothing was photographed —
        check each against its source.
    </p>

    @if (session('status'))
        <p class="mt-4 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800 dark:border-green-900 dark:bg-green-950 dark:text-green-200">
            {{ session('status') }}
        </p>
    @endif

    @if (session('error'))
        <p class="mt-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200">
            {{ session('error') }}
        </p>
    @endif

    @if ($unidentified > 0)
        {{-- An empty queue and a finished job are different things: a casting the
             agent hasn't reached, or honestly couldn't name, produces no review
             item at all and would otherwise be invisible from here. --}}
        <p class="mt-4 rounded-xl border border-neutral-200 bg-white px-4 py-3 text-sm text-neutral-500 dark:border-neutral-800 dark:bg-neutral-900">
            {{ $unidentified }} casting{{ $unidentified === 1 ? '' : 's' }} still
            {{ $unidentified === 1 ? 'has' : 'have' }} no attribution at all.
            Run <code class="font-mono text-xs">catalog:identify-castings</code> to work through them.
        </p>
    @endif

    @if ($proposals->isEmpty())
        <p class="mt-4 text-sm text-neutral-500">Nothing awaiting review.</p>
    @else
        <ul class="mt-4 space-y-3">
            @foreach ($proposals as $proposal)
                @php
                    $record = $proposal->proposable;
                    $summary = ProposalPresenter::for($record);
                    $blockers = $record->approvalBlockers();
                    $alsoPublished = $record->pendingDependencies();
                    $source = $record->source;
                @endphp

                <li class="rounded-xl border border-neutral-200 bg-white p-4 dark:border-neutral-800 dark:bg-neutral-900">
                    <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                        <span class="text-xs uppercase tracking-wide text-neutral-500">{{ $summary->type }}</span>

                        @if ($proposal->confidence === null)
                            <span class="text-xs text-neutral-500">No confidence score</span>
                        @else
                            {{-- Scored against the import threshold, not the
                                 review one: these are two separate dials on
                                 purpose, and showing the wrong bar here would
                                 flag confident rows as doubtful. --}}
                            <span class="text-xs {{ $proposal->requiresManualReview((float) config('catalog.import.auto_approve_threshold')) ? 'text-amber-700 dark:text-amber-400' : 'text-neutral-500' }}">
                                {{ number_format((float) $proposal->confidence * 100, 1) }}% confidence
                            </span>
                        @endif
                    </div>

                    <p class="mt-1 font-medium">{{ $summary->title }}</p>

                    @if ($summary->details !== [])
                        <dl class="mt-3 grid gap-x-4 gap-y-1 text-sm sm:grid-cols-[9rem_1fr]">
                            @foreach ($summary->details as $label => $value)
                                <dt class="text-neutral-500">{{ $label }}</dt>
                                <dd>{{ $value }}</dd>
                            @endforeach
                        </dl>
                    @endif

                    @if ($source?->source_url)
                        {{-- The only evidence there is. The submission screen has
                             photos in this position; here the source page is what
                             a reviewer checks the attribution against. --}}
                        <p class="mt-3 text-sm">
                            <a
                                href="{{ $source->source_url }}"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="text-neutral-500 underline hover:text-neutral-900 dark:hover:text-neutral-100"
                            >Check against the source page</a>
                        </p>
                    @endif

                    @if ($blockers !== [])
                        <div class="mt-3 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200">
                            <p class="font-medium">Can't be approved yet</p>
                            <ul class="mt-1 space-y-1">
                                @foreach ($blockers as $blocker)
                                    <li>{{ $blocker }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @elseif ($alsoPublished !== [])
                        <div class="mt-3 rounded-lg bg-neutral-50 px-3 py-2 text-sm dark:bg-neutral-950">
                            <p class="text-neutral-500">Approving also publishes:</p>
                            <ul class="mt-1 space-y-1">
                                @foreach ($alsoPublished as $dependency)
                                    @php $parent = ProposalPresenter::for($dependency); @endphp
                                    <li>
                                        <span class="text-neutral-500">{{ $parent->type }}</span>
                                        {{ $parent->title }}
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <div class="mt-4 flex gap-3">
                        <form method="POST" action="{{ route('admin.proposals.approve', $proposal) }}" class="flex-1">
                            @csrf
                            <button
                                type="submit"
                                @disabled($blockers !== [])
                                class="w-full rounded-lg bg-neutral-900 px-4 py-3 font-medium text-white disabled:opacity-40 dark:bg-white dark:text-neutral-900"
                            >
                                Approve
                            </button>
                        </form>

                        <form method="POST" action="{{ route('admin.proposals.reject', $proposal) }}">
                            @csrf
                            <button
                                type="submit"
                                class="rounded-lg border border-neutral-300 px-4 py-3 font-medium dark:border-neutral-700"
                            >
                                {{-- Not "Reject": for an imported casting this
                                     retracts the attribution and leaves the row
                                     alone. See Casting::rejectProposed(). --}}
                                {{ $record instanceof \App\Models\Casting && $source !== null ? 'Wrong car' : 'Reject' }}
                            </button>
                        </form>
                    </div>
                </li>
            @endforeach
        </ul>

        <div class="mt-6">
            {{ $proposals->links() }}
        </div>
    @endif
</x-layouts.app>
