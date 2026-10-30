@props(['status'])

@php
    $map = [
        'active' => ['label' => 'Online', 'wrap' => 'bg-emerald-50 text-emerald-700 border-emerald-200', 'dot' => 'bg-[var(--success)]', 'pulse' => true],
        'maintenance' => ['label' => 'Maintenance', 'wrap' => 'bg-amber-50 text-amber-700 border-amber-200', 'dot' => 'bg-amber-500', 'pulse' => false],
        'offline' => ['label' => 'Offline', 'wrap' => 'bg-rose-50 text-rose-700 border-rose-200', 'dot' => 'bg-[var(--danger)]', 'pulse' => false],
    ];
    $cfg = $map[$status] ?? $map['offline'];
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1.5 px-2 py-0.5 rounded text-[11px] font-semibold border '.$cfg['wrap']]) }}>
    <span class="size-1.5 rounded-full {{ $cfg['dot'] }} {{ $cfg['pulse'] ? 'animate-pulse' : '' }}"></span>
    <span>{{ $cfg['label'] }}</span>
</span>
