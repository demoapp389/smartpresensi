import './style.css';
import Alpine from 'alpinejs';

function meta(name) {
  const el = document.querySelector(`meta[name="${name}"]`);
  return el ? el.getAttribute('content') : '';
}

Alpine.data('wizard', () => ({
  token: meta('setup-token'),
  mode: meta('setup-mode') || 'install',
  step: 0,
  busy: false,
  error: '',
  healthMessage: 'Belum dijalankan.',

  steps: [
    { title: 'Selamat Datang' },
    { title: 'Cek Sistem' },
    { title: 'Cek Komponen' },
    { title: 'Panduan' },
    { title: 'Konfigurasi' },
    { title: 'Layanan' },
    { title: 'Uji Sistem' },
    { title: 'Selesai' },
  ],

  prerequisites: [
    'Sistem operasi Windows 64-bit.',
    'Jalankan sebagai Administrator agar layanan dapat dipasang.',
    'Ruang disk minimal 2 GB untuk runtime dan data.',
    'Tidak ada aplikasi lain yang memakai port web/database.',
  ],

  state: { os: '', arch: '', admin: false, hostname: '' },
  components: [],
  systemChecks: [],
  services: [
    { key: 'mysql', name: 'SmartPresensiMySQL', status: 'Belum dipasang' },
    { key: 'apache', name: 'SmartPresensiApache', status: 'Belum dipasang' },
    { key: 'supervisor', name: 'SmartPresensiSupervisor', status: 'Belum dipasang' },
  ],
  config: { schoolName: '', timezone: 'Asia/Jakarta', webPort: 8080, dbPort: 3307, dbPassword: '', appUrl: '', supervisorToken: '', adminName: 'Administrator Sekolah', adminEmail: 'admin@sekolah.sch.id', adminPassword: '' },
  stepLog: [],
  progress: { active: false, current: 0, total: 0, step: '', done: false, ok: false },
  configSaved: false,

  async init() {
    try {
      const data = await this.api('/api/state');
      this.state.os = data.os;
      this.state.arch = data.arch;
      this.state.admin = data.admin;
      this.state.hostname = data.hostname;
      this.components = data.components || [];
      if (data.config) {
        if (data.config.schoolName) { this.config.schoolName = data.config.schoolName; }
        if (data.config.webPort) { this.config.webPort = data.config.webPort; }
        if (data.config.dbPort) { this.config.dbPort = data.config.dbPort; }
        if (data.config.appUrl) { this.config.appUrl = data.config.appUrl; }
      }
    } catch (e) {
      this.error = e.message;
    }
  },

  async api(path, body) {
    const res = await fetch(path, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Setup-Token': this.token },
      body: JSON.stringify(body || {}),
    });
    const data = await res.json().catch(() => ({ ok: false, error: 'Respons tidak valid' }));
    if (!res.ok || data.ok === false) {
      throw new Error(data.error || `Permintaan gagal (HTTP ${res.status})`);
    }
    return data;
  },

  async checkSystem() {
    this.busy = true;
    this.error = '';
    try {
      const data = await this.api('/api/check/system');
      this.systemChecks = data.checks || [];
    } catch (e) {
      this.error = e.message;
    } finally {
      this.busy = false;
    }
  },

  async checkDeps() {
    this.busy = true;
    this.error = '';
    try {
      const data = await this.api('/api/check/dependencies');
      this.components = data.components || [];
    } catch (e) {
      this.error = e.message;
    } finally {
      this.busy = false;
    }
  },

  async runHealth() {
    this.busy = true;
    this.error = '';
    this.healthMessage = '';
    try {
      const data = await this.api('/api/health');
      this.healthMessage = data.message || 'Selesai.';
    } catch (e) {
      this.error = e.message;
    } finally {
      this.busy = false;
    }
  },

  pushLog(res) {
    if (!res) { return; }
    if (Array.isArray(res.steps)) {
      res.steps.forEach((s) => this.stepLog.push({ name: s.name, status: s.status, message: s.message }));
    }
    if (Array.isArray(res.warnings)) {
      res.warnings.forEach((w) => this.stepLog.push({ name: 'peringatan', status: 'warn', message: w }));
    }
  },

  configPayload() {
    const payload = {
      schoolName: this.config.schoolName,
      timezone: this.config.timezone,
      webPort: this.config.webPort,
      dbPort: this.config.dbPort,
      adminName: this.config.adminName,
      adminEmail: this.config.adminEmail,
      adminPassword: this.config.adminPassword,
    };
    if (this.config.dbPassword) { payload.dbPassword = this.config.dbPassword; }
    return payload;
  },

  adminValid() {
    const email = (this.config.adminEmail || '').trim();
    const password = this.config.adminPassword || '';
    return /^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email) && password.length >= 8;
  },

  async apply(action, params) {
    this.busy = true;
    this.error = '';
    try {
      const data = await this.api('/api/apply', { action, params: params || this.configPayload() });
      this.pushLog(data);
      const d = data.data || {};
      if (d.dbPassword) { this.config.dbPassword = d.dbPassword; }
      if (d.appUrl) { this.config.appUrl = d.appUrl; }
      if (d.supervisorToken) { this.config.supervisorToken = d.supervisorToken; }
      return data;
    } catch (e) {
      this.error = e.message;
      return null;
    } finally {
      this.busy = false;
    }
  },

  async saveConfig() {
    const data = await this.apply('apply-config');
    if (data && data.ok) { this.configSaved = true; }
  },

  async refreshServices() {
    const data = await this.apply('check-services', {});
    if (data && Array.isArray(data.steps)) {
      data.steps.forEach((s) => {
        const svc = this.services.find((x) => x.name === s.name);
        if (svc) { svc.status = s.message || s.status; }
      });
    }
  },

  progressPercent() {
    return this.progress.total ? Math.round((this.progress.current / this.progress.total) * 100) : 0;
  },

  async installServices() {
    this.stepLog = [];
    this.error = '';
    if (!this.adminValid()) {
      this.error = 'Lengkapi Akun Administrator Pertama pada langkah Konfigurasi: email yang valid dan kata sandi minimal 8 karakter.';
      return;
    }
    this.busy = true;
    this.progress = { active: true, current: 0, total: 0, step: '', done: false, ok: false };
    try {
      const start = await this.api('/api/apply', { action: 'apply-all', params: this.configPayload() });
      const jobId = start.jobId;
      if (!jobId) {
        this.pushLog(start);
        this.progress.active = false;
        await this.refreshServices();
        return;
      }
      for (;;) {
        await new Promise((r) => setTimeout(r, 800));
        const p = await this.api('/api/apply/progress', { job: jobId });
        this.progress.total = p.total || 0;
        this.progress.current = p.current || 0;
        this.progress.step = p.step || '';
        if (Array.isArray(p.steps) && p.steps.length) { this.stepLog = p.steps.slice(); }
        if (p.done) {
          this.progress.done = true;
          this.progress.ok = !!p.resultOk;
          if (Array.isArray(p.warnings)) {
            p.warnings.forEach((w) => this.stepLog.push({ name: 'peringatan', status: 'warn', message: w }));
          }
          break;
        }
      }
    } catch (e) {
      this.error = e.message;
    } finally {
      this.progress.active = false;
      this.busy = false;
      await this.refreshServices();
    }
  },

  async repairServices() {
    this.stepLog = [];
    await this.apply('repair');
    await this.refreshServices();
  },

  async runServiceHealth() {
    this.healthMessage = '';
    const data = await this.apply('health', {
      webPort: this.config.webPort,
      dbPort: this.config.dbPort,
    });
    if (data) {
      this.healthMessage = data.ok
        ? 'Pemeriksaan selesai — tinjau detail di bawah.'
        : 'Ada masalah yang perlu ditinjau.';
    } else {
      this.healthMessage = 'Gagal menjalankan uji.';
    }
  },

  async runDiagnostic() {
    await this.apply('diagnostic', {});
  },

  async resetDbPassword() {
    this.stepLog = [];
    this.healthMessage = '';
    const data = await this.apply('reset-db-password', {});
    if (data) {
      this.healthMessage = data.ok
        ? 'Kata sandi database disetel ulang sesuai app\\.env.'
        : 'Reset kata sandi database gagal — tinjau log.';
    }
  },

  async finish() {
    try {
      await this.api('/api/shutdown');
    } catch (e) {
      this.error = e.message;
    }
    document.body.innerHTML =
      '<div class="flex min-h-screen items-center justify-center bg-canvas p-6 text-center">' +
      '<div><h1 class="text-lg font-semibold text-ink">Setup selesai</h1>' +
      '<p class="mt-2 text-sm text-ink-soft">Server setup telah dihentikan. Anda boleh menutup jendela ini.</p></div></div>';
  },

  goto(i) { this.step = Math.max(0, Math.min(this.steps.length - 1, i)); },
  next() { this.goto(this.step + 1); },
  prev() { this.goto(this.step - 1); },
}));

window.Alpine = Alpine;
Alpine.start();
