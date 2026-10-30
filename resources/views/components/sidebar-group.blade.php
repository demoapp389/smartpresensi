{{--
  Kategori sidebar (level 0).

  Hanya berisi judul kategori (.nav-label, gaya Gentelella) dan daftar anak.
  Judul tidak dapat diklik — hanya submenu yang punya anak sendiri yang
  memakai komponen <x-sidebar-group> secara rekursif (level > 0).
--}}
@props([
    'storageKey',
    'label',
    'icon' => 'dashboard',
    'items' => [],
])

@php
    $linkItems = array_values(array_filter($items, fn ($item) => ! isset($item['items'])));
    $subGroups = array_values(array_filter($items, fn ($item) => isset($item['items'])));

    $anyActive = collect($linkItems)->contains(fn ($item) => $item['active'] ?? false)
        || collect($subGroups)->contains(
            fn ($sub) => collect($sub['items'])->contains(fn ($item) => $item['active'] ?? false)
        );
@endphp

<div class="mb-0.5">
    <p class="rail-hide px-2.5 pb-1 pt-4 text-[10px] font-semibold uppercase tracking-[0.5px] text-[var(--sidebar-text)]/50">
        {{ $label }}
    </p>

    <div class="space-y-0.5">
        @foreach($subGroups as $subIndex => $sub)
            <x-sidebar-tree
                :storage-key="$storageKey.'-'.$subIndex"
                :label="$sub['label']"
                :icon="$sub['icon']"
                :items="$sub['items']" />
        @endforeach

        @foreach($linkItems as $link)
            <x-sidebar-link
                :route="$link['route']"
                :label="$link['label']"
                :icon="$link['icon'] ?? $icon"
                :active="$link['active'] ?? false"
                :badge="$link['badge'] ?? null"
                :badge-tone="$link['badgeTone'] ?? 'warning'" />
        @endforeach
    </div>
</div>
