@php
    use App\Support\ProposalPresenter;

    $hints = collect([
        'Brand' => $submission->hint_brand,
        'Year' => $submission->hint_year,
        'Series' => $submission->hint_series,
    ])->filter();

    $undecided = $submission->proposals->reject->isDecided();
@endphp

<x-layouts.app title="Submission #{{ $submission->id }}">
    <div class="flex items-baseline justify-between gap-4">
        <h1 class="text-xl font-semibold">Submission #{{ $submission->id }}</h1>
        <a href="{{ route('admin.dashboard') }}" class="text-sm text-neutral-500 hover:text-neutral-900 dark:hover:text-neutral-100">
            Review queue
        </a>
    </div>

    <p class="mt-1 text-sm text-neutral-500">
        Captured {{ $submission->created_at->diffForHumans() }} ·
        {{ $undecided->count() }} of {{ $submission->proposals->count() }} still to rule on
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

    @if ($submission->state === \App\Models\Submission::STATE_FAILED)
        {{-- The pipeline lifecycle is a different question from the catalog
             status of what it produced, and a failed run can still have left
             usable proposals behind — so this is stated, not used to hide the
             list below. --}}
        <p class="mt-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200">
            This pipeline run failed: {{ $submission->error ?: 'no reason recorded' }}
        </p>
    @elseif ($submission->state !== \App\Models\Submission::STATE_COMPLETED)
        <p class="mt-4 rounded-xl border border-neutral-200 bg-white px-4 py-3 text-sm text-neutral-500 dark:border-neutral-800 dark:bg-neutral-900">
            Still {{ $submission->state }} — more proposals may appear once the pipeline finishes.
        </p>
    @endif

    <section class="mt-6">
        <h2 class="font-medium">Photos</h2>

        @if ($submission->photos->isEmpty())
            <p class="mt-2 text-sm text-neutral-500">No photos on this submission.</p>
        @else
            <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-3">
                @foreach ($submission->photos as $photo)
                    <figure class="overflow-hidden rounded-xl border border-neutral-200 bg-white dark:border-neutral-800 dark:bg-neutral-900">
                        {{-- Opens full size in a new tab: judging a tampo or a
                             collector number off a thumbnail is guesswork, and
                             the bytes are already public on the CDN. --}}
                        <a href="{{ $photo->url() }}" target="_blank" rel="noopener">
                            <img
                                src="{{ $photo->url() }}"
                                alt="{{ str($photo->type)->headline() }}"
                                loading="lazy"
                                class="aspect-square w-full bg-neutral-100 object-cover dark:bg-neutral-800"
                            >
                        </a>
                        <figcaption class="px-3 py-2 text-xs text-neutral-500">
                            {{ str($photo->type)->headline() }}
                        </figcaption>
                    </figure>
                @endforeach
            </div>
        @endif
    </section>

    @if ($hints->isNotEmpty() || $reading !== [])
        <section class="mt-6 rounded-xl border border-neutral-200 bg-white p-4 dark:border-neutral-800 dark:bg-neutral-900">
            <h2 class="font-medium">What the pipeline was working from</h2>

            @if ($hints->isNotEmpty())
                <dl class="mt-3 grid gap-x-4 gap-y-2 text-sm sm:grid-cols-[8rem_1fr]">
                    @foreach ($hints as $label => $value)
                        <dt class="text-neutral-500">{{ $label }} hint</dt>
                        <dd>{{ $value }}</dd>
                    @endforeach
                </dl>
            @endif

            @if ($reading !== [])
                <h3 class="mt-4 text-sm font-medium">Read off the packaging</h3>

                {{-- Per-field confidence sits next to the field it scores: a
                     single number for the whole read would tell a reviewer to
                     check everything or nothing, and the useful case is a card
                     with a crisp logo and an illegible toy number. --}}
                <dl class="mt-2 grid gap-x-4 gap-y-2 text-sm sm:grid-cols-[9rem_1fr]">
                    @foreach ($reading as $field)
                        <dt class="text-neutral-500">{{ $field['label'] }}</dt>
                        <dd class="flex flex-wrap items-baseline gap-x-2">
                            <span @class(['text-neutral-500' => $field['value'] === 'Not readable'])>
                                {{ $field['value'] }}
                            </span>

                            @if ($field['confidence'] !== null)
                                <span class="text-xs {{ $field['confidence'] < 0.8 ? 'text-amber-700 dark:text-amber-400' : 'text-neutral-500' }}">
                                    {{ number_format($field['confidence'] * 100, 0) }}%
                                </span>
                            @endif
                        </dd>
                    @endforeach
                </dl>
            @endif
        </section>
    @endif

    <section class="mt-6">
        <h2 class="font-medium">Proposed records</h2>

        @if ($submission->proposals->isEmpty())
            <p class="mt-2 text-sm text-neutral-500">
                This submission hasn't produced any proposals.
            </p>
        @else
            <ul class="mt-3 space-y-3">
                @foreach ($submission->proposals as $proposal)
                    @php
                        $record = $proposal->proposable;
                        $summary = ProposalPresenter::for($record);
                        $blockers = $record->approvalBlockers();
                        $alsoPublished = $proposal->isDecided() ? [] : $record->pendingDependencies();
                    @endphp

                    <li class="rounded-xl border border-neutral-200 bg-white p-4 dark:border-neutral-800 dark:bg-neutral-900">
                        <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                            <span class="text-xs uppercase tracking-wide text-neutral-500">{{ $summary->type }}</span>

                            @if ($proposal->confidence === null)
                                <span class="text-xs text-neutral-500">No confidence score</span>
                            @else
                                <span class="text-xs {{ $proposal->requiresManualReview() ? 'text-amber-700 dark:text-amber-400' : 'text-neutral-500' }}">
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

                        @if ($proposal->isDecided())
                            {{-- Built as one string rather than three tags so it
                                 reads as a sentence, including to anything
                                 matching on the rendered text. --}}
                            <p class="mt-3 text-sm text-neutral-500">
                                {{ collect([
                                    ucfirst($proposal->decision),
                                    $proposal->decidedBy ? 'by '.$proposal->decidedBy->name : null,
                                    $proposal->decided_at?->diffForHumans(),
                                ])->filter()->implode(' ') }}
                            </p>
                        @else
                            @if ($proposal->requiresManualReview())
                                <p class="mt-3 text-sm text-amber-700 dark:text-amber-400">
                                    Below the auto-approval threshold — needs your eyes on the photo.
                                </p>
                            @endif

                            @if ($blockers !== [])
                                {{-- Approving is refused server-side too; this
                                     is here so the reason arrives before the
                                     click rather than after it. --}}
                                <div class="mt-3 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200">
                                    <p class="font-medium">Can't be approved yet</p>
                                    <ul class="mt-1 space-y-1">
                                        @foreach ($blockers as $blocker)
                                            <li>{{ $blocker }}</li>
                                        @endforeach
                                    </ul>
                                </div>
                            @elseif ($alsoPublished !== [])
                                {{-- The walk promotes ancestors whether or not
                                     the reviewer thought about them, so the one
                                     thing this screen owes them is knowing what
                                     else a single click makes public. --}}
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
                                <form
                                    method="POST"
                                    action="{{ route('admin.submissions.proposals.approve', [$submission, $proposal]) }}"
                                    class="flex-1"
                                >
                                    @csrf
                                    <button
                                        type="submit"
                                        @disabled($blockers !== [])
                                        class="w-full rounded-lg bg-neutral-900 px-4 py-3 font-medium text-white disabled:opacity-40 dark:bg-white dark:text-neutral-900"
                                    >
                                        Approve
                                    </button>
                                </form>

                                <form
                                    method="POST"
                                    action="{{ route('admin.submissions.proposals.reject', [$submission, $proposal]) }}"
                                >
                                    @csrf
                                    <button
                                        type="submit"
                                        class="rounded-lg border border-neutral-300 px-4 py-3 font-medium dark:border-neutral-700"
                                    >
                                        Reject
                                    </button>
                                </form>
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</x-layouts.app>
