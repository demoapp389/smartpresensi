@if ($paginator->hasPages())
    @php
        $currentPage = $paginator->currentPage();
        $maxVisiblePages = 5;
        $windowedElements = collect($elements)
            ->map(function ($element) use ($currentPage, $maxVisiblePages) {
                if (! is_array($element)) {
                    return $element;
                }

                $side = intdiv($maxVisiblePages, 2);

                return array_filter(
                    $element,
                    fn ($page) => abs((int) $page - $currentPage) <= $side,
                    ARRAY_FILTER_USE_KEY
                );
            })
            ->filter(fn ($element) => is_string($element) || count($element) > 0)
            ->values();
    @endphp

    <nav role="navigation" aria-label="{{ __('Pagination Navigation') }}" class="flex items-center justify-between gap-2">

        <p class="m-0 text-xs text-[var(--text-muted)]">
            @if ($paginator->firstItem())
                Menampilkan <span class="font-semibold text-[var(--text)]">{{ $paginator->firstItem() }}</span>
                - <span class="font-semibold text-[var(--text)]">{{ $paginator->lastItem() }}</span>
                dari <span class="font-semibold text-[var(--text)]">{{ $paginator->total() }}</span>
            @else
                <span class="font-semibold text-[var(--text)]">{{ $paginator->count() }}</span>
            @endif
        </p>

        <div class="flex items-center gap-0.5">
            {{-- Previous Page Link --}}
            @if ($paginator->onFirstPage())
                <span aria-disabled="true" aria-label="{{ __('pagination.previous') }}"
                    class="inline-flex h-[30px] min-w-[30px] cursor-not-allowed items-center justify-center rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface)] px-2 text-[12.5px] font-medium text-[var(--text-muted)] opacity-45 select-none">
                    &lsaquo;
                </span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="{{ __('pagination.previous') }}"
                    class="inline-flex h-[30px] min-w-[30px] items-center justify-center rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface)] px-2 text-[12.5px] font-medium text-[var(--text-secondary)] transition-colors hover:bg-[var(--surface-secondary)] hover:text-[var(--text)]">
                    &lsaquo;
                </a>
            @endif

            {{-- Pagination Elements --}}
            @foreach ($windowedElements as $element)
                {{-- "Three Dots" Separator --}}
                @if (is_string($element))
                    <span aria-disabled="true" class="px-1 text-xs text-[var(--text-muted)] select-none">{{ $element }}</span>
                @endif

                {{-- Array Of Links --}}
                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <span aria-current="page"
                                class="inline-flex h-[30px] min-w-[30px] items-center justify-center rounded-[var(--radius-sm)] border border-[var(--primary)] bg-[var(--primary)] px-2 text-[12.5px] font-medium text-white">
                                {{ $page }}
                            </span>
                        @else
                            <a href="{{ $url }}" aria-label="{{ __('Go to page :page', ['page' => $page]) }}"
                                class="inline-flex h-[30px] min-w-[30px] items-center justify-center rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface)] px-2 text-[12.5px] font-medium text-[var(--text-secondary)] transition-colors hover:bg-[var(--surface-secondary)] hover:text-[var(--text)]">
                                {{ $page }}
                            </a>
                        @endif
                    @endforeach
                @endif
            @endforeach

            {{-- Next Page Link --}}
            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="{{ __('pagination.next') }}"
                    class="inline-flex h-[30px] min-w-[30px] items-center justify-center rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface)] px-2 text-[12.5px] font-medium text-[var(--text-secondary)] transition-colors hover:bg-[var(--surface-secondary)] hover:text-[var(--text)]">
                    &rsaquo;
                </a>
            @else
                <span aria-disabled="true" aria-label="{{ __('pagination.next') }}"
                    class="inline-flex h-[30px] min-w-[30px] cursor-not-allowed items-center justify-center rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface)] px-2 text-[12.5px] font-medium text-[var(--text-muted)] opacity-45 select-none">
                    &rsaquo;
                </span>
            @endif
        </div>
    </nav>
@endif
