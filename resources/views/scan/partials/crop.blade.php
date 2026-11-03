@if ($detection->crop_path)
    <img src="{{ route('scan.crop', [$detection->scan_id, $detection->id]) }}" alt="Car {{ $detection->number }} from photo {{ $detection->photo }}"
         class="size-24 shrink-0 rounded-lg bg-neutral-100 object-contain dark:bg-neutral-800">
@else
    <span class="grid size-24 shrink-0 place-items-center rounded-lg bg-neutral-100 text-center text-xs text-neutral-400 dark:bg-neutral-800">Picture deleted</span>
@endif
