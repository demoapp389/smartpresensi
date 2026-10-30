@props([
    'title',
    'message' => null,
    'icon' => 'inbox',
])

<div {{ $attributes->merge(['class' => 'col-span-full rounded-[var(--radius)] border border-dashed border-[var(--border)] bg-[var(--surface)] px-6 py-10 text-center']) }}>
    <span class="mx-auto mb-3 flex size-11 items-center justify-center rounded-full bg-[var(--surface-secondary)] text-[var(--text-muted)]">
        <x-icon :name="$icon" class="size-5" />
    </span>
    <p class="text-sm font-semibold text-[var(--text)]">{{ $title }}</p>
    @if($message)
        <p class="mx-auto mt-1 max-w-md text-xs leading-relaxed text-[var(--text-muted)]">{{ $message }}</p>
    @endif
    @isset($action)
        <div class="mt-4 flex justify-center">{{ $action }}</div>
    @endisset
</div>
