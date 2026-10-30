@props([
    'label',
    'value',
    'hint' => null,
    'tone' => 'default',
])

@php
    $tones = [
        'default' => ['box' => 'border-[var(--border)] bg-[var(--surface)]', 'value' => 'text-[var(--text)]'],
        'primary' => ['box' => 'border-[var(--primary)]/25 bg-[var(--primary-soft)]', 'value' => 'text-[var(--primary-accessible)]'],
        'success' => ['box' => 'border-emerald-200/60 bg-[var(--surface)]', 'value' => 'text-emerald-700'],
        'warning' => ['box' => 'border-amber-200/60 bg-[var(--surface)]', 'value' => 'text-amber-800'],
        'info' => ['box' => 'border-blue-200/60 bg-[var(--surface)]', 'value' => 'text-blue-800'],
        'danger' => ['box' => 'border-rose-200/60 bg-[var(--surface)]', 'value' => 'text-rose-800'],
    ];
    $cfg = $tones[$tone] ?? $tones['default'];
@endphp

<article {{ $attributes->merge(['class' => 'min-w-0 rounded-[var(--radius)] border p-3.5 shadow-[var(--shadow)] '.$cfg['box']]) }}>
    <p class="text-[11px] font-semibold uppercase tracking-wider text-[var(--text-muted)] truncate">{{ $label }}</p>
    <p class="mt-1 text-2xl font-bold leading-none tabular-nums {{ $cfg['value'] }}">{{ $value }}</p>
    @if($hint)
        <p class="mt-1.5 text-[11px] text-[var(--text-secondary)]">{{ $hint }}</p>
    @endif
</article>
