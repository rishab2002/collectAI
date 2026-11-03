@props(['status'])

<span @class([
    'shrink-0 rounded-full px-2 py-0.5 text-xs font-medium',
    'bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-200' => $status === 'live',
    'bg-amber-100 text-amber-900 dark:bg-amber-950 dark:text-amber-200' => $status === 'pending',
    'bg-neutral-200 text-neutral-700 dark:bg-neutral-800 dark:text-neutral-300' => $status === 'rejected',
])>{{ ucfirst($status) }}</span>
