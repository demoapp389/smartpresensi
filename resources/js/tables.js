// Gentelella 2026 v4 — DataTables integration
// Dynamic-imports DataTables only when a [data-datatable] table is present.

/**
 * Initialize DataTables on every `<table data-datatable>` on the page.
 * Reads `data-page-length` (default 10) on the table and `data-orderable="false"`
 * on individual `<th>` cells to disable sorting per column.
 *
 * Opt-in extras (just add the attribute to the `<table>`):
 * - `data-toolbar-filter="#selector"` — move the page's own filter controls (a
 *   plain GET `<form>`) into the DataTables top toolbar, directly after the
 *   page-length dropdown, so filters sit on the left and the search box on the
 *   right. The element keeps working as a normal form when JS is unavailable.
 * - `data-empty-message="…"` — text shown when the table has no rows (or no
 *   rows match the search).
 * - `data-selectable` — wire row checkboxes (header checkbox = select all visible).
 * - `data-export="filename"` — show a CSV export button in the table card header.
 * - `data-ajax="/url"` — server-side mode: paging, search and ordering are done
 *   by the server instead of in the browser. The endpoint receives DataTables'
 *   standard request parameters and replies with
 *   `{ draw, recordsTotal, recordsFiltered, data: [[cell, …], …] }`, where each
 *   cell is already-rendered HTML. Pair with `data-ajax-method="POST"` to send
 *   a POST (the CSRF token is read from `<meta name="csrf-token">`).
 * - `data-export-url="/url"` — required for export in server-side mode, where
 *   the browser only holds one page and a client-side CSV would silently export
 *   just that page. The current search and ordering are forwarded.
 *
 * In server-side mode any `[data-table-filter="name"]` control inside the same
 * card is collected and sent as `filters[name]`, and changing one reloads the
 * table. A control may also carry `data-table-filter-part="from|to"` to build a
 * range, which arrives as `filters[name][from]`.
 *
 * Lazily imports `datatables.net`; the import never fires on pages without a
 * matching table.
 * @returns {Promise<void>}
 */
export async function initTables() {
    const tables = document.querySelectorAll('table[data-datatable]');
    if (!tables.length) {return;}

    const { default: DataTable } = await import('datatables.net');

    tables.forEach((table) => {
        // Cegah error DataTables tn/4 jika ada placeholder kosong Blade ber-colspan di dalam tbody
        const tbody = table.querySelector('tbody');
        if (tbody && tbody.rows.length === 1) {
            const firstRow = tbody.rows[0];
            const singleCell = firstRow.cells.length === 1 ? firstRow.cells[0] : null;
            if (singleCell && singleCell.hasAttribute('colspan')) {
                if (!table.dataset.emptyMessage && singleCell.textContent.trim()) {
                    table.dataset.emptyMessage = singleCell.textContent.trim();
                }
                firstRow.remove();
            }
        }

        const columnDefs = [];
        table.querySelectorAll('thead th').forEach((th, i) => {
            if (th.dataset.orderable === 'false') {
                columnDefs.push({ targets: i, orderable: false });
            }
        });

        const ajaxUrl = table.dataset.ajax;
        const serverSide = Boolean(ajaxUrl);
        const lengthChange = table.dataset.lengthChange === 'true';
        const searching = table.dataset.searching !== 'false';

        // Page-owned controls (filters) to slot into the top toolbar, right
        // after the page-length dropdown.
        const toolbarFilter = table.dataset.toolbarFilter
            ? document.querySelector(table.dataset.toolbarFilter)
            : null;
        const topStart = [];
        if (lengthChange) {topStart.push('pageLength');}
        if (toolbarFilter) {topStart.push(toolbarFilter);}

        const pageLength = parseInt(table.dataset.pageLength || '10', 10);
        const lengthMenu = [10, 25, 50, 100];
        if (!lengthMenu.includes(pageLength)) {
            lengthMenu.push(pageLength);
            lengthMenu.sort((a, b) => a - b);
        }

        const options = {
            pageLength,
            lengthChange,
            searching,
            lengthMenu,
            order: [],
            columnDefs,
            // Tata letak standar DataTables, sesuai template Gentelella:
            // dropdown jumlah baris (lalu filter halaman) di kiri-atas, kotak
            // pencarian di kanan-atas, info "Total N entri" di kiri-bawah,
            // tombol pagination di kanan-bawah.
            layout: {
                topStart: topStart.length ? topStart : null,
                topEnd: searching ? 'search' : null,
                bottomStart: 'info',
                bottomEnd: 'paging'
            },
            language: {
                search: '',
                searchPlaceholder: 'Cari…',
                info: 'Total _TOTAL_ entri',
                infoEmpty: 'Total 0 entri',
                infoFiltered: '(disaring dari _MAX_ total entri)',
                zeroRecords: table.dataset.emptyMessage || 'Tidak ditemukan data yang cocok',
                emptyTable: table.dataset.emptyMessage || 'Belum ada data',
                lengthMenu: 'Tampilkan _MENU_',
                paginate: { previous: '←', next: '→' }
            }
        };

        if (serverSide) {
            options.serverSide = true;
            options.processing = true;
            options.searchDelay = 400;
            options.ajax = ajaxConfig(table, ajaxUrl);
        }

        const dt = new DataTable(table, options);

        // A page may ship its filter `hidden` to avoid a pre-init flash at its
        // natural DOM position; reveal it once DataTables has slotted it into
        // the toolbar.
        if (toolbarFilter) {
            toolbarFilter.removeAttribute('hidden');
        }

        if (serverSide) {
            wireFilters(table, dt, ajaxUrl);
        }

        if (table.hasAttribute('data-selectable') || hasRowCheckboxes(table)) {
            wireRowSelection(table, dt);
        }
        if (table.hasAttribute('data-export')) {
            if (serverSide) {
                wireServerExport(table, dt);
            } else {
                wireCsvExport(table, dt);
            }
        }

        const searchInput = table.closest('.dt-container')?.querySelector('.dt-search input');
        if (searchInput && !searchInput.hasAttribute('aria-label')) {
            searchInput.setAttribute('aria-label', 'Search table');
        }
    });
}

/**
 * Build the DataTables `ajax` option for server-side mode.
 * GET by default — it needs no CSRF token and stays cacheable; POST is there
 * for tables whose parameters outgrow a query string.
 */
function ajaxConfig(table, url) {
    const method = (table.dataset.ajaxMethod || 'GET').toUpperCase();
    const config = { url, type: method };

    if (method === 'POST') {
        const token = document.querySelector('meta[name="csrf-token"]')?.content;
        if (token) {config.headers = { 'X-CSRF-TOKEN': token };}
    }

    return config;
}

/** Every filter control belonging to this table, as a plain object. */
function collectFilters(table) {
    const scope = table.closest('.card') || document;
    const out = {};

    scope.querySelectorAll('[data-table-filter]').forEach((el) => {
        const name = el.dataset.tableFilter;
        const part = el.dataset.tableFilterPart;
        const value = el.type === 'checkbox' ? (el.checked ? '1' : '') : el.value;

        if (value === '') {return;}

        if (part) {
            out[name] = out[name] && typeof out[name] === 'object' ? out[name] : {};
            out[name][part] = value;
            return;
        }

        out[name] = value;
    });

    return out;
}

/**
 * Reload on change.
 *
 * The filters go into the endpoint URL rather than through DataTables'
 * `ajax.data` callback, which is not invoked in every version. Setting the URL
 * is explicit, survives paging and sorting — DataTables keeps using it for
 * subsequent draws — and is trivially inspectable in the network tab.
 *
 * Typed controls are debounced for the same reason the search box is; a select
 * or a date fires immediately, since those change once per decision.
 */
function wireFilters(table, dt, baseUrl) {
    const scope = table.closest('.card') || document;
    const controls = scope.querySelectorAll('[data-table-filter]');
    if (!controls.length) {return;}

    const urlWithFilters = () => {
        const target = new URL(baseUrl, window.location.href);

        Object.entries(collectFilters(table)).forEach(([name, value]) => {
            if (value && typeof value === 'object') {
                Object.entries(value).forEach(([part, inner]) => {
                    target.searchParams.set(`filters[${name}][${part}]`, inner);
                });
                return;
            }
            target.searchParams.set(`filters[${name}]`, value);
        });

        return target.toString();
    };

    let timer = null;
    const reload = (delay) => {
        window.clearTimeout(timer);
        timer = window.setTimeout(() => dt.ajax.url(urlWithFilters()).load(null, true), delay);
    };

    controls.forEach((el) => {
        el.addEventListener('change', () => reload(0));
        if (el.tagName === 'INPUT' && ['text', 'search', 'number'].includes(el.type)) {
            el.addEventListener('input', () => reload(400));
        }
    });

    const reset = scope.querySelector('[data-table-filter-reset]');
    if (reset) {
        reset.addEventListener('click', () => {
            controls.forEach((el) => {
                if (el.type === 'checkbox') {el.checked = false;} else {el.value = '';}
            });
            reload(0);
        });
    }
}

function hasRowCheckboxes(table) {
    return !!table.querySelector('thead input[type="checkbox"]');
}

function wireRowSelection(table, dt) {
    const headerCb = table.querySelector('thead input[type="checkbox"]');

    const updateHeader = () => {
        if (!headerCb) {return;}
        const rowCbs = table.querySelectorAll('tbody input[type="checkbox"]');
        const checked = [...rowCbs].filter((c) => c.checked).length;
        headerCb.checked = checked > 0 && checked === rowCbs.length;
        headerCb.indeterminate = checked > 0 && checked < rowCbs.length;
        table.classList.toggle('has-selection', checked > 0);
        const counter = table.closest('.card')?.querySelector('.bulk-selection-count');
        if (counter) {counter.textContent = checked ? `${checked} selected` : '';}
    };

    if (headerCb) {
        headerCb.addEventListener('change', () => {
            const rowCbs = table.querySelectorAll('tbody input[type="checkbox"]');
            rowCbs.forEach((c) => { c.checked = headerCb.checked; });
            updateHeader();
        });
    }
    table.addEventListener('change', (e) => {
        const cb = e.target.closest('tbody input[type="checkbox"]');
        if (!cb) {return;}
        updateHeader();
    });

    dt?.on('draw', updateHeader);
}

/**
 * The export button. Reuses a `[data-export-btn]` the page already provides,
 * otherwise injects one into the card header. Returns null when there is no
 * card header to put it in.
 */
function ensureExportButton(table) {
    const existing = table.closest('.card')?.querySelector('[data-export-btn]');
    if (existing) {return existing;}

    const header = table.closest('.card')?.querySelector('.card-header');
    if (!header) {return null;}

    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'btn btn-outline btn-sm';
    btn.setAttribute('data-export-btn', '');
    btn.textContent = 'Export CSV';

    let actions = header.querySelector('.card-actions');
    if (!actions) {
        actions = document.createElement('div');
        actions.className = 'card-actions';
        actions.style.marginLeft = 'auto';
        header.appendChild(actions);
    }
    actions.appendChild(btn);

    return btn;
}

/**
 * Server-side export: hand the current search and ordering to the endpoint in
 * `data-export-url` and let it stream the file. Without that attribute there is
 * nothing to call, so no button is shown — better than one that exports a
 * single page and looks like it exported everything.
 */
function wireServerExport(table, dt) {
    const url = table.dataset.exportUrl;
    if (!url) {return;}

    const btn = ensureExportButton(table);
    if (!btn) {return;}

    btn.addEventListener('click', () => {
        const target = new URL(url, window.location.href);
        const search = dt.search();
        if (search) {target.searchParams.set('search', search);}

        const [order] = dt.order();
        if (order) {
            target.searchParams.set('order_column', String(order[0]));
            target.searchParams.set('order_dir', String(order[1]));
        }

        window.location.assign(target.toString());
    });
}

function wireCsvExport(table, dt) {
    const filename = (table.dataset.export || 'export') + '.csv';
    const btn = ensureExportButton(table);
    if (!btn) {return;}

    btn.addEventListener('click', () => {
        const rows = [];
        const headers = [];
        dt.columns().every(function () {
            const th = this.header();
            if (th && th.dataset.orderable === 'false' && !th.textContent.trim()) {
                headers.push(null);
            } else {
                headers.push(th ? th.textContent.trim() : '');
            }
        });
        rows.push(headers.filter((h) => h !== null).map(csvEscape).join(','));

        const indexes = dt.rows({ search: 'applied', order: 'applied' }).indexes();
        for (let n = 0; n < indexes.length; n += 1) {
            const rowEl = dt.row(indexes[n]).node();
            if (!rowEl) {continue;}
            const cells = [];
            [...rowEl.cells].forEach((td, idx) => {
                if (headers[idx] === null) {return;}
                cells.push(csvEscape(td.textContent.trim().replace(/\s+/g, ' ')));
            });
            rows.push(cells.join(','));
        }

        downloadFile(filename, rows.join('\n'), 'text/csv;charset=utf-8;');
        window.toast?.(`Exported ${filename}`, 'success');
    });
}

function csvEscape(v) {
    const s = String(v ?? '');
    if (/[",\n]/.test(s)) {return `"${s.replace(/"/g, '""')}"`;}
    return s;
}

function downloadFile(filename, content, type) {
    const blob = new Blob([content], { type });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 0);
}

// Auto-initialize on DOM ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initTables);
} else {
    initTables();
}