@props([
    'nameFrom' => 'date_from',
    'nameTo' => 'date_to',
    'valueFrom' => request()->input($nameFrom ?? 'date_from', ''),
    'valueTo' => request()->input($nameTo ?? 'date_to', ''),
    'label' => 'Tanggal',
])

@php
    $fromId = 'filter-' . ($nameFrom ?? 'date_from');
    $toId = 'filter-' . ($nameTo ?? 'date_to');
@endphp

@if($label)
    <label class="text-[var(--text-secondary)] font-medium mr-1">{{ $label }}</label>
@endif

<div class="flex items-center gap-1.5 rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface-secondary)] px-2.5 py-1">
    <input type="date"
           name="{{ $nameFrom }}"
           id="{{ $fromId }}"
           value="{{ $valueFrom }}"
           class="bg-transparent font-medium text-[var(--text)] focus:outline-none cursor-pointer text-xs"
           x-model="filters.{{ $nameFrom }}"
           @change="checkDateRange()">
    <span class="text-[var(--text-muted)] text-xs">s/d</span>
    <input type="date"
           name="{{ $nameTo }}"
           id="{{ $toId }}"
           value="{{ $valueTo }}"
           class="bg-transparent font-medium text-[var(--text)] focus:outline-none cursor-pointer text-xs"
           x-model="filters.{{ $nameTo }}"
           @change="checkDateRange()">
</div>