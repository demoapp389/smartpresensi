@props([
    'hasTemplate' => null,
    'checkedAt' => null,
])

@php
    $checkedLabel = $checkedAt ? ' ('.$checkedAt->format('d M Y H:i').')' : '';
@endphp

@if($hasTemplate === true)
    <span class="inline-flex items-center gap-1 rounded border border-emerald-300 bg-emerald-50 px-1.5 py-0.5 text-[10px] font-semibold text-emerald-700 dark:border-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300"
        title="Template sidik jari terverifikasi ada di mesin{{ $checkedLabel }}">
        <svg class="size-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
        <span>Ada sidik jari</span>
    </span>
@elseif($hasTemplate === false)
    <span class="inline-flex items-center gap-1 rounded border border-amber-300 bg-amber-50 px-1.5 py-0.5 text-[10px] font-semibold text-amber-700 dark:border-amber-700 dark:bg-amber-950/40 dark:text-amber-300"
        title="PIN terdaftar, tetapi template sidik jari belum terekam di mesin{{ $checkedLabel }}">
        <svg class="size-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        <span>Belum ada sidik jari</span>
    </span>
@else
    <span class="inline-flex items-center gap-1 rounded border border-[var(--border)] bg-[var(--surface-secondary)] px-1.5 py-0.5 text-[10px] font-medium text-[var(--text-muted)]"
        title="Status sidik jari belum diverifikasi. Jalankan Verifikasi Sidik Jari di halaman mesin.">
        <svg class="size-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        <span>Belum diverifikasi</span>
    </span>
@endif
