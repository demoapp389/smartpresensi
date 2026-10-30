{{--
  Submenu sidebar yang dapat dibuka/tutup (pola .nav-tree Gentelella).

  - Header (.nav-toggle) punya bentuk sama dengan .nav-link + chevron kanan
    yang berotasi 90° saat terbuka.
  - Anak (.nav-sub) memakai teknik grid-template-rows 0fr → 1fr agar
    transisi mulus tanpa menghitung tinggi via JS.
  - Sidebar bersifat akordeon: membuka satu grup menutup grup lain.
  - Grup yang memuat halaman aktif otomatis terbuka.
  - Pada rail mode submenu disembunyikan dan diganti flyout, sehingga
    semua menu tetap dapat diakses.
--}}
@props([
    'storageKey',
    'label',
    'icon' => 'dashboard',
    'items' => [],
    'level' => 1,
])

@php
    $anyActive = collect($items)->contains(fn ($item) => $item['active'] ?? false);
@endphp

<div class="nav-tree flex flex-col"
     x-data="{
         get open() {
             return $store.sidebarNav.isOpen(@js($storageKey), @js($anyActive));
         },
         toggle() {
             $store.sidebarNav.set(@js($storageKey), ! this.open);
         },
         flyout: false,
         flyoutTop: 0,
         flyoutLeft: 0,
         toggleFlyout(event, isRailMode) {
             if (! isRailMode || window.innerWidth < 1024) {
                 this.toggle();
                 return;
             }

             const rect = event.currentTarget.getBoundingClientRect();
             this.flyoutTop = rect.top;
             this.flyoutLeft = rect.right + 8;
             this.flyout = ! this.flyout;
         },
     }"
     x-on:keydown.escape.window="flyout = false"
     x-on:click.outside="flyout = false"
     x-on:resize.window="flyout = false"
     x-on:scroll.window.capture="flyout = false"
     x-bind:class="open && 'open'">

    <button type="button"
            class="nav-toggle group relative mb-px flex min-h-8 w-full items-center gap-2.5 rounded-[var(--radius-sm)] border-0 bg-transparent px-3 py-1.5 text-left text-[13px] transition-colors duration-150
                   {{ $anyActive ? 'text-[var(--sidebar-text-hover)]' : 'text-[var(--sidebar-text)] hover:text-[var(--sidebar-text-hover)] hover:bg-[var(--sidebar-hover)]' }}"
            aria-controls="nav-sub-{{ $storageKey }}"
            x-bind:aria-expanded="open.toString()"
            x-on:click="toggleFlyout($event, railMode)">
        <x-icon :name="$icon" class="nav-icon size-4.5 shrink-0 opacity-50 transition-opacity hover:opacity-85
                               {{ $anyActive ? '!opacity-85' : '' }}" />
        <span class="nav-text truncate rail-hide">{{ $label }}</span>
        <x-icon name="chevron" class="nav-chev rail-hide ml-auto size-3 shrink-0 opacity-55 transition-transform duration-200" />
    </button>

    {{-- Submenu inline. Kelas baris grid di-bind sepenuhnya supaya hanya satu
         state (0fr / 1fr) yang aktif sekaligus — bila keduanya dibiarkan di
         atribut class, Tailwind bisa menulis keduanya dan yang menang tidak
         Islesuai. --}}
    <div id="nav-sub-{{ $storageKey }}" class="nav-sub grid transition-[grid-template-rows] duration-200 ease-[cubic-bezier(0.2,0,0,1)]"
         x-bind:class="open ? 'grid-rows-[1fr]' : 'grid-rows-[0fr]'">
        <div class="relative min-h-0 overflow-hidden py-1 pl-7">
            {{-- Guide rail vertikal yang menghubungkan induk ke anak --}}
            <span aria-hidden="true"
                  class="nav-rail absolute inset-y-1 left-[19px] w-px bg-[var(--sidebar-border)] transition-colors duration-200"
                  x-bind:class="open && 'bg-white/10'"></span>

            @foreach($items as $item)
                <x-sidebar-link
                    :route="$item['route']"
                    :label="$item['label']"
                    :icon="$item['icon'] ?? $icon"
                    :active="$item['active'] ?? false"
                    :badge="$item['badge'] ?? null"
                    :badge-tone="$item['badgeTone'] ?? 'warning'"
                    sub />
            @endforeach
        </div>
    </div>

    {{-- Flyout untuk rail mode (submenu inline disembuny oleh CSS).
         Dipindahkan ke body agar tidak terpotong overflow sidebar dan tidak
         menumpuk di topbar sebelum Alpine selesai menginisialisasi state. --}}
    <template x-teleport="body">
        <div x-cloak
             x-show="railMode && flyout"
             x-transition:enter="transition ease-out duration-150"
             x-transition:enter-start="opacity-0 -translate-x-1"
             x-transition:enter-end="opacity-100 translate-x-0"
             :style="`top: ${flyoutTop}px; left: ${flyoutLeft}px;`"
             class="fixed z-[60] w-52 rounded-[var(--radius)] border border-[var(--sidebar-border)] bg-[var(--sidebar-bg)] p-1 shadow-xl">
            @foreach($items as $item)
                <x-sidebar-link
                    :route="$item['route']"
                    :label="$item['label']"
                    :icon="$item['icon'] ?? $icon"
                    :active="$item['active'] ?? false"
                    :badge="$item['badge'] ?? null"
                    :badge-tone="$item['badgeTone'] ?? 'warning'" />
            @endforeach
        </div>
    </template>
</div>
