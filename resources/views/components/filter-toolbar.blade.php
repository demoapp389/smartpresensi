@props([
    'action' => null,
    'method' => 'GET',
    'debounce' => 400,
    'autoSubmit' => true,
    'hasApplyButton' => false,
    'resetRoute' => null,
    'targetContainer' => '#table-container',
])

<div x-data="useHybridFilter({
        action: '{{ $action ? (Route::has($action) ? route($action) : $action) : url()->current() }}',
        debounce: {{ $debounce }},
        autoSubmit: {{ $autoSubmit ? 'true' : 'false' }},
        targetContainer: '{{ $targetContainer }}'
    })"
    class="flex flex-wrap items-center justify-between gap-3 rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] p-3 shadow-[var(--shadow)] text-xs">

    <form x-ref="filterForm"
          @submit.prevent="submitForm()"
          method="{{ $method }}"
          action="{{ $action ? (Route::has($action) ? route($action) : $action) : url()->current() }}"
          class="flex flex-wrap items-center gap-2 w-full md:w-auto">

        {{ $slot }}

        {{-- Loading indicator --}}
        <div x-show="loading" class="flex items-center gap-1.5 text-[var(--text-muted)] text-xs">
            <svg class="animate-spin size-3.5 text-[var(--primary-accessible)]" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            <span class="text-[11px]">Memuat...</span>
        </div>

        @if($hasApplyButton)
            <button type="submit"
                    class="inline-flex h-8.5 w-full sm:w-auto min-h-[38px] sm:min-h-[34px] items-center justify-center rounded-[var(--radius-sm)] bg-[var(--primary)] px-3 text-xs font-semibold text-white hover:brightness-95 transition-colors cursor-pointer">
                Terapkan
            </button>
        @endif

        @if($resetRoute)
            <a href="{{ Route::has($resetRoute) ? route($resetRoute) : $resetRoute }}"
               @click.prevent="resetFilters('{{ Route::has($resetRoute) ? route($resetRoute) : $resetRoute }}')"
               class="inline-flex h-8.5 w-full sm:w-auto min-h-[38px] sm:min-h-[34px] items-center justify-center rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface)] px-3 text-xs font-medium text-[var(--text-secondary)] hover:bg-[var(--surface-secondary)] transition-colors cursor-pointer">
                Reset
            </a>
        @endif
    </form>

    @if(isset($extra))
        <div class="flex flex-wrap items-center gap-2 w-full md:w-auto">
            {{ $extra }}
        </div>
    @endif
</div>
