/**
 * Hybrid Filter Composable untuk Alpine.js
 *
 * Mengelola state filter, debounce, abort controller, dan URL synchronization.
 *
 * @param {Object} options
 * @param {string} options.action URL target untuk filtering
 * @param {number} options.debounce Delay debounce untuk input teks (ms)
 * @param {boolean} options.autoSubmit Otomatis submit saat input berubah
 * @param {string} options.targetContainer Selector container tabel yang akan diganti
 */
export function useHybridFilter(options = {}) {
    const defaultOptions = {
        action: window.location.pathname,
        debounce: 400,
        autoSubmit: true,
        targetContainer: '#table-container',
    };

    const config = { ...defaultOptions, ...options };

    return {
        // State
        filters: {},
        loading: false,
        abortController: null,
        debounceTimer: null,

        // Initialize from URL query parameters
        init() {
            this.syncFromUrl();

            // Dengarkan custom event dari child components
            this.$el.addEventListener('filter-changed', (event) => {
                const { name, value } = event.detail;
                this.filters[name] = value;
                if (config.autoSubmit) {
                    this.applyFilter();
                }
            });

            // Handle browser back/forward buttons
            window.addEventListener('popstate', () => {
                this.syncFromUrl();
                this.fetchData(false); // don't push state again
            });
        },

        // Sync filter values from current URL
        syncFromUrl() {
            const params = new URLSearchParams(window.location.search);
            this.filters = {};
            for (const [key, value] of params.entries()) {
                this.filters[key] = value;
            }
        },

        // Build query string from current filters
        buildQueryString() {
            const params = new URLSearchParams();
            for (const [key, value] of Object.entries(this.filters)) {
                if (value !== '' && value !== null && value !== undefined) {
                    params.append(key, value);
                }
            }
            return params.toString();
        },

        // Update URL without reloading page
        updateUrl(pushState = true) {
            const queryString = this.buildQueryString();
            const newUrl = queryString
                ? `${config.action}?${queryString}`
                : config.action;

            if (pushState) {
                window.history.pushState(this.filters, '', newUrl);
            } else {
                window.history.replaceState(this.filters, '', newUrl);
            }
        },

        // Main filter execution with race condition prevention
        applyFilter() {
            // Cancel previous in-flight request
            if (this.abortController) {
                this.abortController.abort();
            }

            this.abortController = new AbortController();
            this.loading = true;

            const queryString = this.buildQueryString();
            const url = queryString
                ? `${config.action}?${queryString}`
                : config.action;

            this.updateUrl(true);

            fetch(url, {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'text/html',
                },
                signal: this.abortController.signal,
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                return response.text();
            })
            .then(html => {
                const parser = new DOMParser();
                const doc = parser.parseFromString(html, 'text/html');
                const newTable = doc.querySelector(config.targetContainer);
                const currentTable = document.querySelector(config.targetContainer);

                if (newTable && currentTable) {
                    currentTable.innerHTML = newTable.innerHTML;

                    // Re-initialize any scripts/components inside the table if needed
                    this.$dispatch('table-updated');
                } else {
                    // Fallback to full page reload if target container not found
                    window.location.href = url;
                }
            })
            .catch(error => {
                if (error.name !== 'AbortError') {
                    console.error('Filter fetch error:', error);
                    window.toast?.('Gagal memuat data filter', 'error');
                }
            })
            .finally(() => {
                this.loading = false;
                this.abortController = null;
            });
        },

        // Check if date range is complete before applying
        checkDateRange() {
            const from = this.filters['date_from'];
            const to = this.filters['date_to'];

            // Hanya apply jika kedua tanggal sudah terisi atau keduanya kosong
            if ((from && to) || (!from && !to)) {
                this.applyFilter();
            }
        },

        // Explicit form submission (for apply button)
        submitForm() {
            this.applyFilter();
        },

        // Reset all filters to default
        resetFilters(resetUrl) {
            this.filters = {};
            if (this.abortController) {
                this.abortController.abort();
            }

            if (resetUrl) {
                window.location.href = resetUrl;
            } else {
                this.updateUrl(true);
                this.applyFilter();
            }
        },
    };
}
