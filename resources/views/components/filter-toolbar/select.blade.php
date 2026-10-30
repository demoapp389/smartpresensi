@props([
    'name' => '',
    'options' => [],
    'value' => request()->input($name ?? ''),
    'label' => '',
    'placeholder' => 'Semua...',
    'width' => 'w-full sm:w-auto sm:min-w-[200px]',
    'includeUnassigned' => false,
    'unassignedLabel' => 'Belum Ada Kelas',
    'unassignedValue' => 'unassigned',
    'group' => false,
])

@php
    $inputName = $name;
    $inputValue = $value ?? '';
    $inputId = 'filter-' . $inputName;
@endphp

@if($label)
    <label for="{{ $inputId }}" class="text-[var(--text-secondary)] font-medium mr-1">{{ $label }}</label>
@endif

<select name="{{ $inputName }}"
        id="{{ $inputId }}"
        value="{{ $inputValue }}"
        class="h-8.5 rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface-secondary)] px-2.5 text-xs text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none cursor-pointer {{ $width }}"
        x-model="filters.{{ $inputName }}"
        @change="$dispatch('filter-changed', { name: '{{ $inputName }}', value: $event.target.value })">
    <option value="">{{ $placeholder }}</option>
    @if($includeUnassigned)
        <option value="{{ $unassignedValue }}" {{ $inputValue == $unassignedValue ? 'selected' : '' }}>
            {{ $unassignedLabel }}
        </option>
    @endif
    @foreach($options as $option)
        @if(is_array($option) || is_object($option))
            @php
                $optValue = $option['value'] ?? ($option->id ?? '');
                $optLabel = $option['label'] ?? ($option->name ?? '');
            @endphp
            <option value="{{ $optValue }}" {{ $inputValue == $optValue ? 'selected' : '' }}>
                {{ $optLabel }}
            </option>
        @else
            @php
                $optValue = is_object($option) ? $option->id : $option;
                $optLabel = is_object($option) ? ($option->name ?? $option) : $option;
            @endphp
            <option value="{{ $optValue }}" {{ $inputValue == $optValue ? 'selected' : '' }}>
                {{ $optLabel }}
            </option>
        @endif
    @endforeach
</select>