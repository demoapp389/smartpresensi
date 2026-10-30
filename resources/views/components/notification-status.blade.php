@props(['status'])

@php
    $map = [
        'queued' => ['label' => 'Antrean', 'wrap' => 'bg-amber-50 text-amber-700 border-amber-200'],
        'sent' => ['label' => 'Terkirim', 'wrap' => 'bg-blue-50 text-blue-700 border-blue-200'],
        'delivered' => ['label' => 'Diterima', 'wrap' => 'bg-emerald-50 text-emerald-700 border-emerald-200'],
        'read' => ['label' => 'Dibaca', 'wrap' => 'bg-indigo-50 text-indigo-700 border-indigo-200'],
        'held' => ['label' => 'Ditahan', 'wrap' => 'bg-orange-50 text-orange-700 border-orange-200'],
        'cancelled' => ['label' => 'Dibatalkan', 'wrap' => 'bg-slate-100 text-slate-600 border-slate-200'],
        'failed' => ['label' => 'Gagal', 'wrap' => 'bg-rose-50 text-rose-700 border-rose-200'],
    ];
    $cfg = $map[$status] ?? ['label' => 'Tidak Dikenal', 'wrap' => 'bg-slate-100 text-slate-600 border-slate-200'];
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-bold border '.$cfg['wrap']]) }}>
    {{ $cfg['label'] }}
</span>
