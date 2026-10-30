@props([
    'name' => 'student_id',
    'id' => null,
    'selected' => null,
    'placeholder' => 'Ketik nama atau NIS (min. 2 huruf)',
])

@php($pickerId = $id ?: 'student-picker-'.uniqid())

<div {{ $attributes->class(['relative']) }}
    x-data="studentPicker({ selected: @js($selected), endpoint: @js(route('students.search-options')) })"
    @click.outside="open = false">
    <input type="hidden" name="{{ $name }}" :value="selected ? selected.id : ''">

    <input id="{{ $pickerId }}" type="text" x-model="query"
        @input="onInput()"
        @focus="if (query.trim().length >= 2) search()"
        @keydown.arrow-down.prevent="move(1)"
        @keydown.arrow-up.prevent="move(-1)"
        @keydown.enter.prevent="pickActive()"
        @keydown.escape="open = false"
        autocomplete="off"
        placeholder="{{ $placeholder }}"
        role="combobox"
        aria-autocomplete="list"
        aria-controls="{{ $pickerId }}-list"
        :aria-expanded="open.toString()"
        class="h-9 w-full rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] px-3 text-xs text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none">

    <div x-cloak x-show="open" id="{{ $pickerId }}-list" role="listbox"
        class="absolute z-30 mt-1 max-h-56 w-full overflow-y-auto rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] p-1 shadow-[var(--shadow)]">
        <div x-show="loading" class="px-3 py-2 text-[11px] text-[var(--text-muted)]">Mencari siswa...</div>
        <div x-show="!loading && error" x-text="error" class="px-3 py-2 text-[11px] text-[var(--danger)]"></div>
        <div x-show="!loading && !error && results.length === 0" class="px-3 py-2 text-[11px] text-[var(--text-muted)]">
            Siswa tidak ditemukan.
        </div>

        <template x-for="(student, index) in results" :key="student.id">
            <button type="button" role="option"
                @click="choose(student)"
                @mouseenter="activeIndex = index"
                :aria-selected="isActive(index).toString()"
                :class="isActive(index) ? 'bg-[var(--primary-soft)] text-[var(--primary-accessible)]' : 'text-[var(--text)]'"
                class="flex w-full items-start justify-between gap-3 rounded-[var(--radius-sm)] px-3 py-2 text-left text-xs transition-colors">
                <span class="min-w-0">
                    <span class="block truncate font-semibold" x-text="student.name"></span>
                    <span class="mt-0.5 block truncate text-[10px] text-[var(--text-muted)]">
                        <span x-text="student.nis ? 'NIS ' + student.nis : 'Tanpa NIS'"></span>
                        <span> &bull; </span>
                        <span x-text="student.class"></span>
                    </span>
                </span>
            </button>
        </template>
    </div>

    <div x-cloak x-show="selected" class="mt-1.5 flex items-start justify-between gap-2 rounded-[var(--radius-sm)] border border-[var(--primary)]/20 bg-[var(--primary-soft)] px-2.5 py-1.5 text-xs">
        <div class="min-w-0">
            <div class="truncate font-semibold text-[var(--primary-accessible)]" x-text="selected ? selected.name : ''"></div>
            <div class="mt-0.5 truncate text-[10px] text-[var(--text-muted)]">
                <span x-text="selected && selected.nis ? 'NIS ' + selected.nis : 'Tanpa NIS'"></span>
                <span> &bull; </span>
                <span x-text="selected ? selected.class : ''"></span>
            </div>
        </div>
        <button type="button" @click="clear()" aria-label="Hapus pilihan siswa"
            class="shrink-0 rounded p-0.5 text-[var(--text-muted)] transition-colors hover:text-[var(--danger)]">
            &times;
        </button>
    </div>
</div>
