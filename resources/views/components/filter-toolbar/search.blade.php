@props([
    'name' => 'search',
    'value' => request()->input($name ?? 'search', ''),
    'placeholder' => 'Cari...',
    'label' => '',
    'width' => 'w-full sm:w-60',
    'icon' => 'search',
])

@php
    $inputName = $name ?? 'search';
    $inputValue = $value ?? '';
    $inputId = 'filter-' . $inputName;
@endphp

@if($label)
    <label for="{{ $inputId }}" class="text-[var(--text-secondary)] font-medium mr-1">{{ $label }}</label>
@endif

<div class="relative {{ $width }}">
    <input type="text"
           name="{{ $inputName }}"
           id="{{ $inputId }}"
           value="{{ $inputValue }}"
           placeholder="{{ $placeholder }}"
           class="h-8.5 w-full rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface-secondary)] pl-8 pr-2.5 text-xs text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none"
           @keydown.enter.prevent
           x-model="filters.{{ $inputName }}"
           @input.debounce.400ms="$dispatch('filter-changed', { name: '{{ $inputName }}', value: $event.target.value })">
    <svg class="size-3.5 absolute left-2.5 top-2.5 text-[var(--text-muted)]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        @if($icon === 'search')
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
        @elseif($icon === 'user')
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
        @elseif($icon === 'id-card')
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
        @endif
    </svg>
</div>