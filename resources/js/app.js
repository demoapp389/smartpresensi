import Alpine from 'alpinejs';
import { useHybridFilter } from './composables/useHybridFilter.js';
import './tables.js';
import Chart from 'chart.js/auto';

window.Alpine = Alpine;
window.useHybridFilter = useHybridFilter;
window.Chart = Chart;

// Toast Notification Store
Alpine.store('toasts', {
    items: [],
    add(message, type = 'success', duration = 4500) {
        const id = Date.now() + Math.random().toString(36).substring(2, 5);
        this.items.push({ id, message, type });
        if (duration > 0) {
            setTimeout(() => {
                this.remove(id);
            }, duration);
        }
    },
    remove(id) {
        this.items = this.items.filter(item => item.id !== id);
    }
});

// Helper global function
window.toast = (message, type = 'success', duration = 4500) => {
    if (window.Alpine && window.Alpine.store('toasts')) {
        window.Alpine.store('toasts').add(message, type, duration);
    } else {
        document.addEventListener('alpine:init', () => {
            window.Alpine.store('toasts').add(message, type, duration);
        }, { once: true });
    }
};

// Sidebar submenu: perilaku akordeon (satu grup terbuka pada satu waktu).
//
// openKey:
//   null      -> belum ada pilihan pengguna; grup berisi halaman aktif yang dibuka
//   'none'    -> pengguna menutup semua submenu secara eksplisit
//   '<key>'   -> grup yang sedang dibuka
//
// Nilai disimpan di localStorage agar pilihan bertahan antar halaman.
const NAV_CLOSED = 'none';

Alpine.store('sidebarNav', {
    openKey: localStorage.getItem('gentelella_nav_open'),

    /** Status terbuka untuk grup tertentu pada state saat ini. */
    isOpen(key, activeByDefault) {
        if (this.openKey === NAV_CLOSED) {
            return false;
        }

        if (this.openKey) {
            return this.openKey === key;
        }

        return Boolean(activeByDefault);
    },

    /** Buka atau tutup grup, lalu terapkan ke seluruh grup sekaligus. */
    set(key, willOpen) {
        this.openKey = willOpen ? key : NAV_CLOSED;

        try {
            localStorage.setItem('gentelella_nav_open', this.openKey);
        } catch (error) {
            // localStorage bisa diblokir (mode privat); abaikan.
        }
    },
});

window.addEventListener('toast', (e) => {
    const { message, type, duration } = e.detail || {};
    if (message) {
        window.toast(message, type || 'success', duration || 4500);
    }
});

// Dialog konfirmasi: pengganti confirm() bawaan browser (Alpine + Tailwind).
//
// ask() mengembalikan Promise<boolean> sehingga pemanggil dapat menunggu
// keputusan pengguna sebelum melanjutkan aksi (submit form / fetch proses).
Alpine.store('confirmDialog', {
    open: false,
    title: 'Konfirmasi',
    message: '',
    confirmLabel: 'Lanjutkan',
    cancelLabel: 'Batal',
    variant: 'primary',
    resolve: null,

    ask(options = {}) {
        this.title = options.title || 'Konfirmasi';
        this.message = options.message || '';
        this.confirmLabel = options.confirmLabel || 'Lanjutkan';
        this.cancelLabel = options.cancelLabel || 'Batal';
        this.variant = options.variant || 'primary';
        this.open = true;

        return new Promise((resolve) => {
            this.resolve = resolve;
        });
    },

    confirm() {
        this.settle(true);
    },

    cancel() {
        this.settle(false);
    },

    settle(approved) {
        this.open = false;

        const resolve = this.resolve;
        this.resolve = null;

        if (resolve) {
            resolve(approved);
        }
    },
});

window.askConfirm = (options = {}) => {
    if (window.Alpine && window.Alpine.store('confirmDialog')) {
        return window.Alpine.store('confirmDialog').ask(options);
    }

    // Fallback bila Alpine belum siap: jangan sampai aksi berisiko terlanjur jalan.
    return Promise.resolve(window.confirm(options.message || 'Lanjutkan?'));
};

// Konfirmasi untuk <form> POST biasa (navigasi halaman penuh).
//
// Pemakaian: <form data-confirm="Pesan..."> dengan atribut opsional
// data-confirm-title, data-confirm-label, data-confirm-variant.
document.addEventListener('submit', (event) => {
    const form = event.target;

    if (!(form instanceof HTMLFormElement) || !form.dataset.confirm) {
        return;
    }

    event.preventDefault();

    window.askConfirm({
        title: form.dataset.confirmTitle,
        message: form.dataset.confirm,
        confirmLabel: form.dataset.confirmLabel,
        variant: form.dataset.confirmVariant,
    }).then((approved) => {
        if (approved) {
            // form.submit() tidak memicu ulang event submit, jadi tidak berulang.
            form.submit();
        }
    });
});

/**
 * Peluncur proses panjang dengan progress bar (import Excel, sinkronisasi massal mesin,
 * validasi nomor WhatsApp wali).
 *
 * Alur: kirim form/url via fetch -> server membalas UUID task -> polling endpoint
 * stateless sampai selesai/gagal -> progress bar + toast.
 *
 * Polling memakai endpoint API (bukan route web) supaya tidak ikut menunggu
 * lock file session yang sedang dipakai proses upload.
 */
/**
 * Scrollbar sidebar hanya tampil ketika area tersebut sedang digulir.
 *
 * CSS membuat thumb transparan secara bawaan; kelas .is-scrolling di sini yang
 * membuatnya terlihat. Kelas dilepas kembali setelah pengguna berhenti
 * menggulir supaya sidebar kembali tenang. Fokus keyboard juga memicu
 * tampilan singkat agar item di luar layar tetap terlihat saat dinavigasi.
 */
(function setupSidebarScrollbar() {
    const IDLE_DELAY = 700;

    function init() {
        const nav = document.querySelector('.sidebar-nav');

        if (!nav || nav.dataset.scrollbarBound === 'true') {
            return;
        }

        nav.dataset.scrollbarBound = 'true';

        let idleTimer = null;

        const show = () => {
            nav.classList.add('is-scrolling');

            clearTimeout(idleTimer);
            idleTimer = setTimeout(() => nav.classList.remove('is-scrolling'), IDLE_DELAY);
        };

        nav.addEventListener('scroll', show, { passive: true });

        // Gulir karena fokus keyboard (bukan wheel) tetap perlu menampilkan bar.
        nav.addEventListener('focusin', () => {
            if (nav.scrollHeight > nav.clientHeight) {
                show();
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();

/**
 * Scrollbar auto-hide generik: thumb hanya tampil ketika elemen sedang digulir.
 * Elemen menandai diri dengan atribut data-autohide-scrollbar.
 */
(function setupAutoHideScrollbars() {
    const IDLE_DELAY = 700;

    function init() {
        document.querySelectorAll('[data-autohide-scrollbar]').forEach((el) => {
            if (el.dataset.scrollbarBound === 'true') {
                return;
            }

            el.dataset.scrollbarBound = 'true';

            let idleTimer = null;

            el.addEventListener('scroll', () => {
                el.classList.add('is-scrolling');

                clearTimeout(idleTimer);
                idleTimer = setTimeout(() => el.classList.remove('is-scrolling'), IDLE_DELAY);
            }, { passive: true });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();

// Status proses panjang global (import, sinkronisasi, broadcast, validasi).
//
// taskRunner pada halaman mana pun akan menulis ke store ini agar progress bar
// di atas topbar tetap terlihat, termasuk ketika proses dimulai dari dalam modal.
Alpine.store('taskProgress', {
    visible: false,
    running: false,
    finished: false,
    failed: false,
    indeterminate: false,
    label: '',
    message: '',
    percent: 0,
    processed: 0,
    total: 0,
    unit: 'data',
    _hideTimer: null,

    update(state = {}) {
        Object.assign(this, state);

        this.visible = this.running || this.finished || this.failed;
        this.indeterminate = this.running && ! this.finished && ! this.failed && this.total <= 0;

        if (this._hideTimer) {
            clearTimeout(this._hideTimer);
            this._hideTimer = null;
        }

        if (!this.running && (this.finished || this.failed)) {
            this._hideTimer = setTimeout(() => this.reset(), 4000);
        }
    },

    reset() {
        if (this._hideTimer) {
            clearTimeout(this._hideTimer);
            this._hideTimer = null;
        }

        this.visible = false;
        this.running = false;
        this.finished = false;
        this.failed = false;
        this.indeterminate = false;
        this.label = '';
        this.message = '';
        this.percent = 0;
        this.processed = 0;
        this.total = 0;
        this.unit = 'data';
    },
});

Alpine.data('taskRunner', (config = {}) => ({
    progressUrl: config.progressUrl || '/api/v1/progress',
    interval: config.interval || 800,
    reloadOnDone: config.reloadOnDone !== false,
    reloadDelay: config.reloadDelay || 1200,
    // Proses yang berjalan di dalam modal: progress cukup tampil di modal,
    // jadi bar global di topbar tidak dipakai (topbar tertutup overlay modal).
    inline: config.inline === true,

    running: false,
    activeAction: null,
    finished: false,
    failed: false,
    label: config.label || 'Memproses',
    uuid: null,
    token: null,
    percent: 0,
    processed: 0,
    total: 0,
    unit: config.unit || 'data',
    message: '',
    timer: null,
    consecutiveErrors: 0,

    /**
     * Satuan item yang sedang diproses, diturunkan dari tipe task server.
     */
    unitFor(type) {
        if (! type) {
            return this.unit;
        }

        if (type === 'whatsapp_broadcast') {
            return 'pesan';
        }

        if (type.startsWith('devices.sync-all') || type.startsWith('devices.sync-attendance')) {
            return 'absensi';
        }

        if (type.startsWith('devices.sync-students') || type.startsWith('students.')) {
            return 'siswa';
        }

        if (type.startsWith('devices.')) {
            return 'mesin';
        }

        if (type.startsWith('guardians.')) {
            return 'wali';
        }

        return this.unit;
    },

    /**
     * Jalankan proses dari sebuah <form>. Label opsional per-panggilan.
     */
    async submit(form, label, action = null) {
        if (this.running) {
            return;
        }

        if (label) {
            this.label = label;
        }

        await this.launch(new FormData(form), form.action, action);
    },

    /**
     * Jalankan proses dari URL sederhana (tanpa form).
     */
    async run(url, label, body = {}, action = null) {
        if (this.running) {
            return;
        }

        if (label) {
            this.label = label;
        }

        const formData = new FormData();
        Object.entries(body).forEach(([key, value]) => formData.append(key, value));

        await this.launch(formData, url, action);
    },

    /**
     * Pantau task yang sudah dimulai di server (mis. siaran broadcast) tanpa POST ulang.
     */
    async watch(uuid, token, label) {
        if (this.running) {
            return;
        }

        if (label) {
            this.label = label;
        }

        this.reset();
        this.running = true;
        this.uuid = uuid;
        this.token = token || null;
        this.publishGlobal();
        this.timer = setInterval(() => this.poll(), this.interval);
        await this.poll();
    },

    async launch(body, url, action = null) {
        this.reset();
        this.activeAction = action;
        this.running = true;
        this.publishGlobal();

        try {
            const response = await fetch(url || this.url, {
                method: 'POST',
                body,
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': this.csrfToken(),
                },
                credentials: 'same-origin',
            });

            const payload = await this.safeJson(response);

            if (!response.ok) {
                throw new Error(this.firstError(payload) || payload.message || 'Gagal memulai proses.');
            }

            this.uuid = payload.uuid;
            this.token = payload.progress_token || null;
            this.total = payload.total || 0;
            this.unit = this.unitFor(payload.type);
            this.message = payload.message || 'Memulai...';
            this.timer = setInterval(() => this.poll(), this.interval);
            await this.poll();
        } catch (error) {
            this.running = false;
            this.failed = true;
            this.message = error.message;
            this.publishGlobal();
            window.toast(error.message, 'error', 9000);
        }
    },

    async poll() {
        if (!this.uuid) {
            return;
        }

        try {
            const url = `${this.progressUrl}/${this.uuid}` + (this.token ? `?token=${encodeURIComponent(this.token)}` : '');
            const response = await fetch(url, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });

            if (!response.ok) {
                throw new Error('Endpoint progres tidak merespons (HTTP ' + response.status + ').');
            }

            const data = await response.json();
            this.consecutiveErrors = 0;

            this.percent = data.percent;
            this.processed = data.processed;
            this.total = data.total;
            this.unit = this.unitFor(data.type);
            this.message = data.message || '';
            this.publishGlobal();

            if (data.status === 'completed') {
                this.stop();
                window.toast(data.message || `${this.label} selesai.`, 'success', 8000);
                this.$dispatch('progress:completed', {
                    results: data.results || {},
                    type: data.type || '',
                });
                this.refreshIfNeeded();
            } else if (data.status === 'failed') {
                this.stop();
                this.failed = true;
                this.message = data.message || 'Proses gagal.';
                this.publishGlobal();
                window.toast(this.message, 'error', 10000);
                this.$dispatch('progress:failed');
            }
        } catch (error) {
            this.consecutiveErrors += 1;

            // Proses mungkin masih jalan di server meski polling sempat gagal sesaat.
            if (this.consecutiveErrors >= 5) {
                this.stop();
                this.failed = true;
                this.message = 'Pemantauan terhenti karena koneksi tidak stabil. Proses mungkin masih berjalan di server.';
                this.publishGlobal();
                window.toast(this.message, 'warning', 9000);
            }
        }
    },

    reset() {
        this.activeAction = null;
        this.finished = false;
        this.failed = false;
        this.uuid = null;
        this.token = null;
        this.percent = 0;
        this.processed = 0;
        this.total = 0;
        this.unit = config.unit || 'data';
        this.message = '';
        this.consecutiveErrors = 0;
    },

    publishGlobal() {
        // Proses inline hanya menampilkan progress di dalam modal.
        if (this.inline) {
            return;
        }

        const store = this.$store.taskProgress;

        if (!store) {
            return;
        }

        store.update({
            running: this.running,
            finished: this.finished,
            failed: this.failed,
            label: this.label,
            message: this.message,
            percent: this.percent,
            processed: this.processed,
            total: this.total,
            unit: this.unit,
        });
    },

    refreshIfNeeded() {
        if (this.reloadOnDone) {
            setTimeout(() => window.location.reload(), this.reloadDelay);
        }
    },

    stop() {
        this.running = false;
        this.finished = true;
        this.percent = 100;

        if (this.timer) {
            clearInterval(this.timer);
            this.timer = null;
        }

        this.publishGlobal();
    },

    csrfToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.content : '';
    },

    async safeJson(response) {
        try {
            return await response.json();
        } catch (error) {
            return {};
        }
    },

    firstError(payload) {
        if (payload && payload.errors) {
            const messages = Object.values(payload.errors).flat();
            if (messages.length) {
                return messages[0];
            }
        }
        return null;
    },
}));

Alpine.data('studentPicker', (config = {}) => ({
    endpoint: config.endpoint || '/students/search',
    selected: config.selected && config.selected.id ? config.selected : null,
    query: '',
    open: false,
    loading: false,
    error: '',
    results: [],
    activeIndex: -1,
    searchTimer: null,
    requestSequence: 0,

    init() {
        this.query = this.selected ? this.selected.name : '';
    },

    onInput() {
        this.selected = null;
        this.open = false;
        clearTimeout(this.searchTimer);
        this.searchTimer = setTimeout(() => this.search(), 250);
    },

    async search() {
        const query = this.query.trim();

        if (query.length < 2) {
            this.results = [];
            this.open = false;
            this.loading = false;

            return;
        }

        const sequence = ++this.requestSequence;
        this.loading = true;
        this.error = '';

        try {
            const response = await fetch(`${this.endpoint}?q=${encodeURIComponent(query)}`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });

            if (!response.ok) {
                throw new Error('Pencarian siswa gagal. Coba lagi.');
            }

            const payload = await response.json();

            if (sequence !== this.requestSequence) {
                return;
            }

            this.results = Array.isArray(payload.data) ? payload.data : [];
            this.open = true;
            this.activeIndex = this.results.length > 0 ? 0 : -1;
        } catch (error) {
            if (sequence !== this.requestSequence) {
                return;
            }

            this.error = error.message || 'Pencarian siswa gagal.';
            this.results = [];
            this.open = true;
            this.activeIndex = -1;
        } finally {
            if (sequence === this.requestSequence) {
                this.loading = false;
            }
        }
    },

    setSelected(student) {
        this.selected = student && student.id ? { ...student } : null;
        this.query = this.selected ? this.selected.name : '';
        this.open = false;
        this.error = '';
        this.results = [];
        this.activeIndex = -1;
    },

    clear() {
        this.setSelected(null);
    },

    choose(student) {
        this.setSelected(student);
    },

    move(step) {
        if (!this.open || this.results.length === 0) {
            if (this.query.trim().length >= 2) {
                this.search();
            }

            return;
        }

        this.activeIndex = (this.activeIndex + step + this.results.length) % this.results.length;
    },

    pickActive() {
        if (this.activeIndex >= 0 && this.results[this.activeIndex]) {
            this.choose(this.results[this.activeIndex]);
        }
    },

    isActive(index) {
        return this.activeIndex === index;
    },
}));

Alpine.start();
