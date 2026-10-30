@php
    $currentPerPage = $paginator->perPage();
    $currentPageName = $paginator->getPageName();
    $perPageOptions = \App\Http\Controllers\Controller::PER_PAGE_OPTIONS;
    $selectId = 'per_page_'.$currentPageName;
@endphp

<div class="flex flex-wrap items-center gap-2">
    <form method="GET" class="flex flex-wrap items-center gap-2">
        @foreach (request()->except(['per_page', $currentPageName]) as $key => $value)
            @if (is_array($value))
                @foreach ($value as $nestedValue)
                    <input type="hidden" name="{{ $key }}[]" value="{{ $nestedValue }}">
                @endforeach
            @elseif ($value !== null && $value !== '')
                <input type="hidden" name="{{ $key }}" value="{{ $value }}">
            @endif
        @endforeach

        <span class="text-xs text-[var(--text-muted)]">Menampilkan</span>

        <select id="{{ $selectId }}" name="per_page" onchange="this.form.submit()"
            class="rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface)] px-2 py-1 text-xs text-[var(--text)] focus:border-[var(--primary)] focus:outline-none focus:ring-1 focus:ring-[var(--primary)]">
            @foreach ($perPageOptions as $perPageOption)
                <option value="{{ $perPageOption }}" @selected($currentPerPage === $perPageOption)>
                    {{ $perPageOption }}
                </option>
            @endforeach
        </select>

        <span class="text-xs text-[var(--text-muted)]">dari {{ $paginator->total() }} data</span>
    </form>
</div>
