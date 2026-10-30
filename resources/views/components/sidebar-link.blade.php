{{--
  Item navigasi sidebar.

  Mengikuti pola Gentelella:
    - level 0  -> .nav-link   (menu biasa, ada ikon + label)
    - level >0 -> .nav-sublink (anak submenu, lebih kecil, ada tick penghubung
                  ke guide rail vertikal)

  Param:
    - route      : nama route Laravel
    - label      : teks tombol (juga dipakai sebagai tooltip di rail mode)
    - icon       : kunci ikon pada components/icon.blade.php
    - active     : true bila route saat ini sedang dibuka
    - sub        : true bila item berada di dalam submenu
    - badge      : nilai badge opsional
    - badgeTone  : warna badge
--}}
@props([
    'route',
    'label',
    'icon' => 'dashboard',
    'active' => false,
    'sub' => false,
    'badge' => null,
    'badgeTone' => 'warning',
])

@php
    $toneClass = match ($badgeTone) {
        'danger' => 'bg-rose-500/15 text-rose-300',
        'info' => 'bg-sky-500/15 text-sky-300',
        default => 'bg-[var(--primary-soft)] text-[var(--primary)]',
    };

    $base = 'flex items-center gap-2.5 rounded-[var(--radius-sm)] transition-colors duration-150 cursor-pointer no-underline';
    $tone = $active
        ? 'text-[var(--sidebar-text-active)] bg-[var(--sidebar-active)] font-medium'
        : 'text-[var(--sidebar-text)] hover:text-[var(--sidebar-text-hover)] hover:bg-[var(--sidebar-hover)]';
@endphp

@if($sub)
    <a href="{{ route($route) }}"
       data-rail-label="{{ $label }}"
       @if($active) aria-current="page" @endif
       class="relative my-px flex items-center gap-2.5 rounded-[var(--radius-sm)] px-3 py-[7px] text-[12.5px] no-underline transition-colors duration-150 {{ $tone }}">
        {{-- Tick penghubung ke guide rail vertikal --}}
        <span aria-hidden="true"
              class="absolute top-1/2 h-px -translate-y-1/2 transition-all duration-150
                     {{ $active ? '-left-[13px] w-3 bg-[var(--primary)]' : '-left-[9px] w-2 bg-[var(--sidebar-border)]' }}"></span>
        <span class="truncate">{{ $label }}</span>
        @if($badge)
            <span class="ml-auto rounded px-1.5 py-px text-[10px] font-semibold leading-relaxed {{ $toneClass }}">
                {{ $badge }}
            </span>
        @endif
    </a>
@else
    <a href="{{ route($route) }}"
       data-rail-label="{{ $label }}"
       @if($active) aria-current="page" @endif
       class="{{ $base }} relative mb-px min-h-8 px-3 py-1.5 text-[13px] {{ $tone }}">
        <x-icon :name="$icon" class="size-4.5 shrink-0 opacity-50 transition-opacity hover:opacity-85
                               {{ $active ? '!opacity-85' : '' }}" />
        <span class="nav-text truncate rail-hide">{{ $label }}</span>
        @if($badge)
            <span class="badge ml-auto rounded px-1.5 py-px text-[10px] font-semibold leading-relaxed rail-hide {{ $toneClass }}">
                {{ $badge }}
            </span>
        @endif
    </a>
@endif
