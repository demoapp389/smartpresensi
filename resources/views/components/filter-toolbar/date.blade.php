@props([
    'name' => 'date',
    'value' => request()->input($name ?? 'date', ''),
    'label' => 'Tanggal',
])

@php
    $inputId = 'filter-' . ($name ?? 'date');
@endphp

@if($label)
    <label for="{{ $inputId }}" class="text-[var(--text-secondary)] font-medium mr-1">{{ $label }}</label>
@endif

<div class="flex items-center gap-1.5 rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface-secondary)] px-2.5 py-1">
    <input type="date"
           name="{{ $name }}"
           id="{{ $inputId }}"
           value="{{ $value }}"
           class="bg-transparent font-medium text-[var(--text)] focus:outline-none cursor-pointer text-xs"
           x-model="filters.{{ $name }}"
           @change="$dispatch('filter-changed', { name: '{{ $name }}', value: $event.target.value })">
</div>