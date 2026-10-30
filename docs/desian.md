# DESIGN SPECIFICATION — Gentelella-Inspired Admin UI (Tailwind CSS)

> **Status:** Panduan desain dan implementasi UI untuk aplikasi yang **sudah berjalan**  
> **Referensi utama:** https://preview.colorlib.com/theme/gentelella/production/  
> **Referensi kode desain:** https://github.com/ColorlibHQ/gentelella  
> **Versi referensi yang diamati:** Gentelella v4 (2026), 13 Oktober 2026  
> **Target implementasi:** Tailwind CSS yang telah digunakan aplikasi; framework, backend, routing, serta fitur tetap dipertahankan.  
> **Prioritas:** Kemiripan visual dan konsistensi antarmuka, tanpa migrasi ke Bootstrap.

---

## 1. Tujuan dan batasan

Ubah **lapisan tampilan** aplikasi yang ada agar memiliki karakter visual seperti dashboard **Gentelella v4**:

- Sidebar **navy gelap** di kiri dengan navigasi berkelompok.
- Topbar terang, ramping, dengan pencarian, aksi cepat, notifikasi, dan profil.
- Background utama **abu-abu kebiruan sangat muda**; card/panel putih dengan border tipis.
- Aksen **teal `#1ABB9C`** pada elemen aktif, grafik, ikon, serta penekanan visual.
- Tipografi **Inter**, ukuran dasar **14px**, hierarki sederhana, kepadatan data sedang/kompak.
- Sudut card **6px**, border sangat halus, dan shadow minimal.
- Dashboard berbasis grid: statistik ringkas, chart, tabel, aktivitas terkini, dan widget.
- Komponen konsisten di seluruh halaman: tabel, formulir, modal, badge, tombol, tabs, pagination, empty/loading/error states.

**Batasan wajib:**

1. **Tetap gunakan Tailwind CSS** yang sudah terpasang. **Jangan menambahkan Bootstrap, jQuery, atau menyalin seluruh template Gentelella** ke proyek.
2. Jangan mengganti framework yang ada (Laravel/Blade, React, Vue, Livewire, Alpine, atau lainnya), struktur backend, database, API, autentikasi, routing, permission, maupun logika bisnis.
3. Ubah markup, kelas Tailwind, token warna, dan komponen visual secara bertahap. Pertahankan nama field, endpoint, action, event, validasi, serta state aplikasi.
4. Ikuti **menu dan terminologi milik aplikasi saat ini**. Label/menu pada demo Gentelella hanyalah referensi penataan, **bukan** fitur yang harus ditambahkan.
5. Tidak wajib mereplikasi semua widget dalam demo. Hanya tampilkan data dan tindakan yang memang ada.
6. Lakukan audit kode dan versi Tailwind sebelum memilih cara konfigurasi tema.

**Catatan versi:** Tautan referensi saat ini mengarah ke **Gentelella v4**, yang berbeda dari Gentelella Bootstrap versi lama (v2/v3). Dokumen ini mengikuti **tampilan v4 pada URL yang diberikan**, bukan estetika atau dependensi Bootstrap klasik.

## 2. DNA visual dan hierarchy

| Aspek | Spesifikasi |
| --- | --- |
| Gaya | Dashboard enterprise yang bersih, profesional, tidak dekoratif |
| Sidebar | Navy gelap, teks abu-biru, icon tipis, active state teal transparan |
| Topbar | Putih sedikit transparan dengan border bawah dan blur halus |
| Latar konten | Soft cool-gray / kebiruan |
| Card | Putih, border 1px halus, radius 6–8px, shadow hampir tidak terlihat |
| Aksen | Teal untuk identitas; hijau/biru/kuning/merah khusus semantik data |
| Font | Inter, fallback system-ui / Segoe UI / sans-serif |
| Kerapatan | Compact; kontrol umumnya 32–36px, tabel mudah dipindai |
| Animasi | 120–220ms untuk hover, collapse, drawer; hindari efek berlebihan |
| Ikon | Outline icons 16–18px, ketebalan konsisten (mis. Lucide/Heroicons yang sudah dipakai) |

**Yang harus terasa saat dilihat:** struktur sidebar kuat, topbar tipis, ruang konten rapi, card dengan pemisah lembut, dan informasi sebagai fokus utama—**bukan** landing page, glassmorphism, gradient besar, atau card bulat berlebihan.

## 3. Design tokens

Gunakan satu sumber token warna/ukuran sehingga tema konsisten di semua halaman. Warna **baseline** mengikuti variabel CSS Gentelella v4; beberapa token tambahan (`--...-accessible`, `--topbar-bg`, dll.) adalah keputusan implementasi Tailwind pada proyek ini, **bukan klaim token asli template**.

### 3.1 Warna light mode

| Token | Nilai | Penggunaan |
| --- | --- | --- |
| `--sidebar-bg` | `#1A2332` | Background sidebar |
| `--sidebar-text` | `#7B8FA3` | Label menu |
| `--sidebar-text-hover` | `#C5D0DC` | Menu hover |
| `--sidebar-text-active` | `#FFFFFF` | Menu aktif |
| `--sidebar-hover` | `rgba(255,255,255,.04)` | Hover menu |
| `--sidebar-active` | `rgba(26,187,156,.08)` | Latar menu aktif |
| `--primary` | `#1ABB9C` | Accent utama teal |
| `--primary-dark` | `#169F85` | Hover/dekorasi teal lebih pekat |
| `--primary-soft` | `rgba(26,187,156,.06)` | Latar icon / highlight ringan |
| `--body-bg` | `#F5F7FB` | Latar halaman |
| `--surface` | `#FFFFFF` | Card, dropdown, panel |
| `--surface-secondary` | `#F9FAFB` | Header tabel, panel sekunder |
| `--border` | `#E6E7EB` | Border komponen |
| `--border-subtle` | `#EFF0F3` | Divider lembut |
| `--text` | `#1E2633` | Heading dan konten utama |
| `--text-secondary` | `#626D7D` | Deskripsi dan label sekunder |
| `--text-muted` | `#7E8896` | Metadata dan placeholder (perhatikan kontras) |
| `--success` | `#2FB344` | Sukses / status positif |
| `--info` | `#066FD1` | Informasi |
| `--warning` | `#F59F00` | Perhatian / pending |
| `--danger` | `#D63939` | Error / hapus / gagal |

**Kontras aksesibel:** Teal `#1ABB9C` dengan teks putih **tidak cukup kontras untuk teks tombol normal**. Untuk tombol solid berteks putih gunakan varian lebih gelap, misalnya `--primary-accessible: #087F69` (kontras sekitar 4,9:1), sementara `#1ABB9C` tetap digunakan sebagai aksen, chart, atau background dengan teks gelap. Jangan menyampaikan makna status hanya melalui warna.

### 3.2 Ukuran, spacing dan tipografi

| Item | Standar |
| --- | --- |
| Font | `Inter`, fallback system sans |
| Body | `14px`, line-height `1.4286` (~20px), regular 400 |
| Judul halaman | `18px`, weight 600, line-height 1.3 |
| Pretitle / label seksi | `10–11px`, weight 600, uppercase, letter-spacing `0.4–0.5px` |
| Judul card | `13–15px`, weight 600 |
| Caption / metadata | `11–12px` |
| Sidebar menu | `13px`; submenu `12–13px` |
| Spacing | Skala `4, 8, 12, 16, 24, 32, 48, 64px` |
| Sidebar normal | `252px` |
| Sidebar rail desktop | `64px` |
| Topbar | `56px` |
| Padding konten desktop | `20px 24px 40px` |
| Padding konten mobile | `12px` |
| Gap antarkolom card | `16px` |
| Radius dasar | `6px` |
| Radius kecil / besar | `4px` / `8px` |
| Shadow card | Tipis, tidak dominan |

### 3.3 CSS variables siap pakai

Tambahkan ke stylesheet global Tailwind aplikasi (misalnya `resources/css/app.css`, `src/styles.css`, atau lokasi existing yang setara). **Gabungkan** dengan style global yang sudah ada; jangan menimpa file tanpa pemeriksaan.

```css
:root {
  --sidebar-bg: #1a2332;
  --sidebar-text: #7b8fa3;
  --sidebar-text-hover: #c5d0dc;
  --sidebar-text-active: #ffffff;
  --sidebar-hover: rgba(255,255,255,.04);
  --sidebar-active: rgba(26,187,156,.08);
  --sidebar-border: rgba(255,255,255,.06);
  --sidebar-w: 252px;

  --primary: #1abb9c;
  --primary-dark: #169f85;
  --primary-accessible: #087f69;
  --primary-soft: rgba(26,187,156,.06);

  --body-bg: #f5f7fb;
  --surface: #ffffff;
  --surface-secondary: #f9fafb;
  --topbar-bg: rgba(255,255,255,.85);
  --border: #e6e7eb;
  --border-subtle: #eff0f3;
  --text: #1e2633;
  --text-secondary: #626d7d;
  --text-muted: #7e8896;

  --success: #2fb344;
  --info: #066fd1;
  --warning: #f59f00;
  --danger: #d63939;

  --radius-sm: 4px;
  --radius: 6px;
  --radius-lg: 8px;
  --shadow-panel: 0 0 0 1px rgba(4,32,69,.08),
                  0 2px 4px rgba(30,38,51,.04);
  --font-admin: 'Inter', system-ui, -apple-system, 'Segoe UI', sans-serif;
}

/* Opsional: tema gelap, aktif bila html[data-theme="dark"]. */
html[data-theme="dark"] {
  --body-bg: #0f1623;
  --surface: #1a2332;
  --surface-secondary: #141d2b;
  --topbar-bg: rgba(20,29,43,.85);
  --border: rgba(255,255,255,.08);
  --border-subtle: rgba(255,255,255,.05);
  --text: #e6ebf2;
  --text-secondary: #b3bccb;
  --text-muted: #8a93a3;
  --primary-soft: rgba(26,187,156,.14);
  --shadow-panel: 0 0 0 1px rgba(255,255,255,.08),
                  0 2px 4px rgba(0,0,0,.3);
}

/* Ditambahkan hanya ketika pengguna mengaktifkan rail mode pada desktop. */
@media (min-width: 769px) {
  body.sidebar-rail { --sidebar-w: 64px; }
}
```

**Cara menggunakan di Tailwind v3/v4 (lintas versi):**

```html
<div class="bg-[var(--body-bg)] text-[var(--text)] font-[var(--font-admin)]">
  <section class="rounded-[var(--radius)] bg-[var(--surface)] border border-[var(--border)] shadow-[var(--shadow-panel)]">
    <h2 class="text-sm font-semibold">Judul Panel</h2>
  </section>
</div>
```

Jika aplikasi memakai Tailwind v4, token bisa dipetakan dengan `@theme inline`; jika memakai v3, bisa dipetakan lewat `tailwind.config.js`. **Kedua cara opsional**; pemakaian `var(...)` langsung di arbitrary utilities di atas sudah cukup. Jangan mencampur dua sistem tema berbeda tanpa alasan.

## 4. Struktur halaman / app shell

```
┌───────────────────────┬──────────────────────────────────────────┐
│ SIDEBAR 252px         │ TOPBAR 56px                              │
│ navy #1A2332          │ toggle · breadcrumb · search · actions    │
│ ───────────────────── │──────────────────────────────────────────│
│ Logo + nama aplikasi  │ PRETITLE                                 │
│                       │ Judul Halaman              [Aksi utama]  │
│ GRUP MENU             │                                          │
│   Dashboard           │ [Stat Card] [Stat Card] [Stat Card]      │
│   Menu utama          │                                          │
│     Submenu           │ [Grafik / Tabel lebar] [Aktivitas]       │
│   Laporan             │                                          │
│   Pengaturan          │ [Tabel / Form / Widget sesuai halaman]   │
│                       │                                          │
│ ───────────────────── │──────────────────────────────────────────│
│ Avatar + nama + role  │ FOOTER                                   │
└───────────────────────┴──────────────────────────────────────────┘
```

### 4.1 Sidebar

- Desktop: tetap terlihat di kiri, `position: fixed`, lebar `252px`, tinggi viewport.
- Brand bar setinggi `56px`; logo kotak teal `28×28px`, nama aplikasi putih `15px` semibold.
- Area menu scroll sendiri. Ikon outline `18px`, teks `13px`, target baris minimal `32px` secara visual; target klik/tap sebaiknya diperluas hingga setidaknya `44px` jika memungkinkan pada touch device.
- Menu dikelompokkan oleh label uppercase yang kecil dan redup; hindari terlalu banyak grup kosong.
- Menu aktif memakai background teal transparan + teks putih; menu hover background putih transparan.
- Submenu menggunakan indentasi, panduan garis vertikal tipis, dan indikator aktif.
- Accordion: grup yang memuat halaman aktif boleh terbuka secara otomatis; item parent gunakan `<button>` dengan `aria-expanded` dan `aria-controls`.
- Bagian bawah menampilkan avatar, nama pengguna, dan role; jangan hardcode identitas admin contoh.
- Tombol hamburger desktop mengubah mode normal menjadi **rail `64px`** (icon-only), dengan tooltip yang dapat diakses keyboard; submenu dapat dibuka melalui flyout atau pola lain yang tetap navigable.
- Status rail dapat disimpan di localStorage bila aplikasinya sudah memiliki mekanisme preferensi UI; jangan membuat ketergantungan yang tidak diperlukan.

### 4.2 Topbar

- Tinggi `56px`, selalu di atas konten; background terang semi-transparan, blur halus, border bawah.
- Kiri: tombol buka/tutup sidebar (34×34) dan breadcrumb ringkas.
- Tengah/kanan jika relevan: pencarian global width sekitar `240px`, height `32px`; gunakan fungsi pencarian aplikasi yang nyata, jangan tombol palsu.
- Kanan: theme toggle bila tersedia, notifikasi, shortcut, avatar/profil.
- Kontrol ikon normal 32–36px; gunakan `aria-label` dan tooltip pada icon-only buttons.
- Isi notifikasi, profil, atau action berasal dari aplikasi; tidak perlu mengikuti data demo.

### 4.3 Content / page header

- Main mengikuti lebar sidebar desktop; tidak boleh tertimpa sidebar maupun topbar.
- Gunakan `min-height: 100vh` dan padding sesuai token.
- Header halaman: **pretitle** kecil (opsional), **judul 18px**, aksi di kanan. Deskripsi singkat boleh di bawah judul.
- Tampilkan breadcrumb di topbar; pada mobile, judul halaman menggantikan breadcrumb yang disembunyikan.
- Jarak header ke konten: sekitar `20px`.
- Hindari dua header dengan judul sama besar dalam satu halaman.

### 4.4 Footer

- Border top tipis, font 12px muted, padding sekitar 16×24px.
- Tampilkan informasi nyata: nama aplikasi, hak cipta, versi aplikasi jika tersedia.
- Jangan menyalin footer Gentelella jika tidak menggunakan branding tersebut.

## 5. Grid dan responsivitas

Gunakan **CSS Grid** dengan `minmax(0, 1fr)` agar tabel/grafik tidak mendorong layout melebar. Tailwind dapat memakai kelas `minmax(...)` melalui grid template arbitrer bila diperlukan.

| Lebar viewport | Perilaku |
| --- | --- |
| `>1200px` | Statistik 3–4 kolom; content chart/tabel 2:1 bila sesuai |
| `921–1200px` | Statistik empat kolom menjadi dua; split 2:1 boleh stack pada `≤1100px` |
| `769–920px` | Komponen tiga kolom turun menjadi dua; sidebar masih bisa memakai rail |
| `≤768px` | Sidebar berubah menjadi **off-canvas drawer** dengan backdrop, konten satu kolom |
| `320–390px` | Padding konten `12px`; kontrol penting tidak terpotong, tidak ada horizontal overflow seluruh halaman |

Detail mobile wajib:

- Sidebar tertutup secara default, tampil lewat hamburger; backdrop dapat diklik untuk menutup.
- Drawer dapat ditutup dengan `Escape`, menutup saat navigasi, dan tidak meninggalkan scroll body terkunci ketika drawer hilang.
- Sembunyikan breadcrumb panjang dan search box desktop jika ruang terbatas; sediakan akses cari pada halaman atau command palette **hanya jika fiturnya benar-benar tersedia**.
- Tabel lebar memakai wrapper `overflow-x-auto` di dalam card, **bukan** membuat seluruh halaman scroll horizontal.
- Form dua kolom berubah satu kolom; group tombol wrap atau stack tanpa mengecilkan teks.
- Tidak ada card, chart, dropdown, modal, atau toast yang keluar viewport.

## 6. Komponen UI standar

### 6.1 Card / panel

- Latar `--surface`; border `1px solid --border`; radius `6px`; shadow minimal.
- Padding isi umum `16px` (widget compact 12px, panel besar 20–24px bila perlu).
- Header berisi judul di kiri; alat (filter, refresh, menu tiga titik) di kanan.
- Divider header jika diperlukan; gunakan border tipis, bukan shadow besar.
- Status: default, hover ringan (hanya bila clickable), loading skeleton, empty, error.

### 6.2 KPI / statistic tiles

- Label `11px` muted/uppercase; angka utama lebih menonjol `20–24px`, weight 600.
- Trend naik/turun memiliki icon, angka perubahan, dan label periode; jangan gunakan warna tanpa teks.
- Ikon kecil di kotak latar pastel dan/atau sparkline di sisi kanan, **bila data ada**.
- Ukuran semua card dalam satu baris seimbang; minimalkan dekorasi.
- Dashboard yang cocok: 3 atau 6 card, mengikuti data aplikasi dan ruang viewport.

### 6.3 Buttons

| Jenis | Visual | Kegunaan |
| --- | --- | --- |
| Primary | Teal gelap yang kontras + teks putih | Aksi utama halaman, simpan, tambah |
| Secondary / outline | Putih, border tipis, teks gelap | Filter, batal, ekspor |
| Ghost | Background transparan; hover muted | Aksi pendukung, icon toolbar |
| Danger | Merah semantik | Hapus / destruktif |
| Disabled | Warna redup + atribut `disabled` | Aksi tidak tersedia |
| Loading | Spinner dan teks status, klik diblokir | Proses asinkron |

- Tinggi tombol umum `34–36px`; primary action konsisten pada semua halaman.
- Radius `4–6px`, padding horizontal `12–14px`, font `13px` weight 500/600.
- Gunakan fokus keyboard yang jelas (`focus-visible`), dan jangan meniadakan outline tanpa pengganti.
- Confirm destructive action bila relevan, bukan hanya mengandalkan toast setelah terhapus.

### 6.4 Forms

- Label `12–13px` semibold di atas input; tanda wajib di label, bukan placeholder saja.
- Input/select/textarea punya surface putih, border `--border`, radius `6px`, padding 10–12px.
- Height input `36px` untuk form biasa; textarea minimal 3–4 baris.
- Saat fokus, border teal dan ring lembut, bukan shadow tebal.
- Helper text `12px` muted; error text merah dan dijelaskan dengan kata-kata.
- Validasi server maupun client yang ada harus tetap berjalan.
- Checkbox/radio/switch, file upload, datepicker, searchable select menyesuaikan visual secara konsisten, **jangan mengganti library berfungsi hanya karena styling**.
- Untuk form panjang, kelompokkan field ke dalam panel berjudul; aksi `Simpan` / `Batal` konsisten posisinya.

### 6.5 Data tables

- Card dengan judul, jumlah data/penjelasan, toolbar pencarian/filter/tambah/ekspor (sesuai fitur yang sudah tersedia).
- Header tabel surface secondary, label `11–12px` uppercase atau semibold, border bawah lembut.
- Body `13px`, row height kurang lebih 44–48px, divider horizontal tipis; zebra opsional dan sangat lembut.
- Alignment: nama/teks ke kiri; angka dapat ke kanan; status center/left sesuai konteks.
- Status badge punya teks eksplisit; gunakan warna semantik pastel dan bukan solid neon.
- Baris terpilih dan hover ditandai halus; kolom aksi memiliki tombol jelas dan menu yang dapat diakses keyboard.
- Sorting, search, pagination, jumlah baris, bulk action, empty state, dan loading state **tetap berfungsi**.
- Sticky header hanya bila memang membantu; jangan membuat nested scroll yang membingungkan.

### 6.6 Badge, alert, dan feedback

- **Success:** hijau; **Info:** biru; **Warning:** kuning/oranye; **Danger:** merah; **Neutral:** abu.
- Badge: ukuran `10–12px`, radius kecil, background soft, bukan tombol bila tidak interaktif.
- Alert: border tipis, icon, judul opsional, teks penjelas, action bila diperlukan.
- Toast: muncul tidak menghalangi navigasi penting, durasi cukup terbaca, `role="status"` untuk non-kritis.
- Setiap operasi asinkron memiliki state loading, sukses/gagal yang nyata.

### 6.7 Modal, dropdown, tabs, pagination

- **Modal:** card putih/gelap sesuai tema, lebar responsif, overlay tenang, header dan footer action konsisten; mendukung fokus trap, Escape, dan restore fokus.
- **Dropdown:** radius 6px, border, shadow halus, jarak item compact, posisi tidak terpotong viewport, dapat digunakan keyboard.
- **Tabs:** indikator aktif teal; tab nonaktif muted; panel dan tab memiliki relasi ARIA.
- **Pagination:** Prev, nomor, Next, active teal, disabled jelas; jumlah total dan per-page hanya bila didukung backend.
- **Tooltip:** singkat, kontras, tidak wajib hover saja (support focus untuk icon-only controls).

### 6.8 Charts dan widgets

- Chart menggunakan aksen `--primary` sebagai seri utama; secondary series dapat memakai biru, cyan, atau palet semantik.
- Gridline tipis `--border-subtle`, label muted, legend compact, tooltip surface.
- Controls periode seperti `7 hari / 30 hari / 90 hari` disediakan jika datanya mendukung.
- Chart harus responsive; beri tinggi minimal eksplisit (contoh 260–320px), bukan mengandalkan konten kosong.
- Jangan menambah grafik, event, angka, atau fake data hanya agar mirip demo.

## 7. Contoh pola Tailwind siap adaptasi

Contoh berikut adalah **kerangka visual**, bukan pengganti komponen aplikasi. Ikon, state sidebar, routing, dan event harus dihubungkan ke implementasi yang sudah ada.

### 7.1 App shell (Blade/HTML/JSX dapat diadaptasi)

```html
<div class="min-h-screen bg-[var(--body-bg)] font-[var(--font-admin)] text-[var(--text)] text-sm">
  <!-- Desktop sidebar; pada mobile buat drawer dengan state dan backdrop nyata. -->
  <aside
    aria-label="Navigasi utama"
    class="fixed inset-y-0 left-0 z-50 hidden w-[var(--sidebar-w)] flex-col bg-[var(--sidebar-bg)] md:flex"
  >
    <div class="flex h-14 shrink-0 items-center gap-2.5 border-b border-[var(--sidebar-border)] px-4">
      <span class="flex size-7 items-center justify-center rounded-md bg-[var(--primary)] text-xs font-bold text-[#1A2332]">A</span>
      <span class="truncate text-[15px] font-semibold text-white">Nama Aplikasi</span>
    </div>

    <nav class="min-h-0 flex-1 overflow-y-auto px-2 py-2">
      <p class="px-3 pb-1 pt-4 text-[10px] font-semibold uppercase tracking-wide text-[var(--sidebar-text)]">
        Menu Utama
      </p>
      <a href="/dashboard" aria-current="page"
         class="flex min-h-9 items-center gap-2.5 rounded bg-[var(--sidebar-active)] px-3 text-[13px] font-medium text-white">
        <span aria-hidden="true" class="inline-flex w-[18px] justify-center">◫</span>
        <span>Dashboard</span>
      </a>
      <!-- Render item/grup menu existing secara dinamis. -->
    </nav>

    <div class="border-t border-[var(--sidebar-border)] p-2">
      <div class="flex items-center gap-2.5 rounded px-2 py-2 text-white">
        <span class="flex size-8 items-center justify-center rounded-full bg-[var(--primary)] text-xs">US</span>
        <div class="min-w-0">
          <p class="truncate text-xs font-medium">Nama Pengguna</p>
          <p class="truncate text-[11px] text-[var(--sidebar-text)]">Role pengguna</p>
        </div>
      </div>
    </div>
  </aside>

  <div class="min-w-0 md:pl-[var(--sidebar-w)]">
    <header class="sticky top-0 z-30 flex h-14 items-center justify-between gap-3 border-b border-[var(--border)] bg-[var(--topbar-bg)] px-3 backdrop-blur-xl md:px-6">
      <div class="flex min-w-0 items-center gap-3">
        <button type="button" aria-label="Buka atau tutup navigasi"
                class="inline-flex size-9 items-center justify-center rounded text-[var(--text-secondary)] hover:bg-[var(--body-bg)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--primary-accessible)]">
          ☰
        </button>
        <nav aria-label="Breadcrumb" class="hidden text-[13px] text-[var(--text-secondary)] md:block">
          Beranda / <span class="font-medium text-[var(--text)]">Dashboard</span>
        </nav>
      </div>
      <div class="flex items-center gap-2">
        <!-- Hubungkan ke pencarian, notifikasi, dan profil existing. -->
        <span class="flex size-8 items-center justify-center rounded-full bg-[var(--primary)] text-xs text-white">US</span>
      </div>
    </header>

    <main id="main-content" class="min-h-[calc(100vh-112px)] px-3 pb-10 pt-3 md:px-6 md:pt-5">
      <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
        <div>
          <p class="text-[11px] font-semibold uppercase tracking-wide text-[var(--text-muted)]">Overview</p>
          <h1 class="text-lg font-semibold leading-snug">Dashboard</h1>
        </div>
        <!-- Letakkan aksi halaman jika ada. -->
      </div>

      <!-- Tempatkan konten halaman existing di sini. -->
    </main>

    <footer class="flex flex-wrap justify-between gap-2 border-t border-[var(--border-subtle)] px-3 py-4 text-xs text-[var(--text-muted)] md:px-6">
      <span>© Nama Aplikasi</span>
      <span>Versi aplikasi (jika tersedia)</span>
    </footer>
  </div>
</div>
```

> **Penting:** Kelas `hidden md:flex` di atas hanya menunjukkan mode sidebar desktop. Implementasikan **drawer mobile** dan **rail desktop** menggunakan state aplikasinya, bukan sekadar menyalin snippet statis. Pastikan pada breakpoint proyek `md` selaras dengan batas `768px` yang dipilih; layout referensi berpindah pada `≤768px`, sedangkan default Tailwind `md` aktif mulai `768px`, sehingga ada perbedaan tepat di lebar itu jika tidak disesuaikan.

### 7.2 Card statistik

```html
<article class="min-w-0 rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] p-4 shadow-[var(--shadow-panel)]">
  <div class="flex items-start justify-between gap-3">
    <div class="min-w-0">
      <p class="text-[11px] font-medium uppercase tracking-wide text-[var(--text-muted)]">Total Data</p>
      <div class="mt-1 flex flex-wrap items-center gap-2">
        <p class="text-2xl font-semibold leading-none tabular-nums">2.500</p>
        <span class="rounded px-1.5 py-0.5 text-[11px] font-semibold text-[#08775F] bg-[#E5F7F0]">↑ 12%</span>
      </div>
      <p class="mt-2 text-xs text-[var(--text-secondary)]">Perubahan dibanding periode sebelumnya</p>
    </div>
    <span aria-hidden="true" class="rounded-md bg-[var(--primary-soft)] p-2 text-[var(--primary-accessible)]">▥</span>
  </div>
</article>
```

Angka di atas **sekadar ilustrasi markup**. Saat implementasi gunakan nilai asli dari aplikasi, atau tampilkan `—` / empty state bila data belum ada.

### 7.3 Tombol, form, dan tabel

```html
<!-- Tombol primary aksesibel -->
<button type="button"
  class="inline-flex min-h-9 items-center justify-center gap-2 rounded-[var(--radius-sm)] bg-[var(--primary)] px-3.5 text-[13px] font-semibold text-white transition-colors hover:brightness-95 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--primary-accessible)] disabled:cursor-not-allowed disabled:opacity-50">
  Simpan
</button>

<!-- Input -->
<div class="space-y-1.5">
  <label for="nama" class="block text-[13px] font-medium text-[var(--text)]">Nama</label>
  <input id="nama" name="nama" type="text"
    class="h-9 w-full rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] px-3 text-[13px] text-[var(--text)] placeholder:text-[var(--text-muted)] focus:border-[var(--primary-accessible)] focus:outline-none focus:ring-2 focus:ring-[var(--primary-soft)]"
    placeholder="Masukkan nama" />
</div>

<!-- Wrapper tabel agar overflow hanya terjadi di dalam panel -->
<section class="min-w-0 rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] shadow-[var(--shadow-panel)]">
  <div class="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--border-subtle)] px-4 py-3">
    <h2 class="text-sm font-semibold">Daftar Data</h2>
    <!-- Toolbar existing: search, filter, tambah, export -->
  </div>
  <div class="w-full overflow-x-auto">
    <table class="min-w-[640px] w-full text-left text-[13px]">
      <thead class="bg-[var(--surface-secondary)] text-xs font-semibold text-[var(--text-secondary)]">
        <tr>
          <th scope="col" class="px-4 py-3">Nama</th>
          <th scope="col" class="px-4 py-3">Status</th>
          <th scope="col" class="px-4 py-3 text-right">Aksi</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-[var(--border-subtle)]">
        <!-- Render baris data nyata dari aplikasi. -->
      </tbody>
    </table>
  </div>
  <!-- Footer pagination existing jika ada. -->
</section>
```

## 8. Standard layout berdasarkan jenis halaman

| Jenis halaman | Pola yang digunakan |
| --- | --- |
| Dashboard | Header + KPI grid + chart/tabel besar + feed aktivitas/widget |
| Daftar / CRUD index | Header + CTA + filter/search + tabel + pagination |
| Tambah / edit | Header + panel form berkelompok + validasi + tombol simpan/batal |
| Detail | Header + ringkasan objek + tabs/panel informasi + aksi konteks |
| Laporan | Header + filter periode + statistik/grafik/tabel + ekspor yang sudah ada |
| Pengaturan | Navigasi sub-seksi + formulir per panel + penyimpanan jelas |
| Profil | Identitas pengguna + detail data + perubahan profil/password bila tersedia |
| Login/auth | Halaman mandiri (tanpa sidebar admin), memakai token font/warna/form yang sama |
| Error / not found | Ringkas, ada pesan yang menjelaskan masalah dan navigasi pemulihan |

**Jangan menyamakan semua layar secara paksa:** karakter Gentelella harus terasa di shell, token, panel, dan komponen, sementara susunan halaman tetap mengikuti tugas dan data sebenarnya.

## 9. Dark mode (opsional, bukan kewajiban)

- Tambahkan hanya jika kompatibel dengan pola tema proyek saat ini.
- Gunakan atribut `html[data-theme="dark"]` dari token di atas atau ikuti dark-mode mechanism Tailwind yang **sudah digunakan**; jangan buat state tema saling bertabrakan.
- Sidebar tetap navy gelap; permukaan dark mengikuti `#1A2332`, latar dark `#0F1623`.
- Kontras teks, border, chart, badge, tooltip, dialog, dan form perlu diperiksa dalam dua tema.
- Simpan preferensi pengguna jika tersedia; inisialisasi tema sebelum UI tampil untuk menghindari kilatan tema yang salah.

## 10. Aksesibilitas dan kualitas UX

- Semua komponen interaktif dapat dioperasikan dengan keyboard.
- Gunakan HTML semantik (`aside`, `nav`, `main`, `header`, `button`, `table`, `label`).
- Pasang `aria-current="page"` pada navigasi aktif, `aria-expanded` pada menu accordion, `aria-label` pada tombol ikon.
- Hindari placeholder sebagai satu-satunya label field.
- Gunakan status teks/ikon bersama warna; pastikan kontras teks kecil sekurang-kurangnya sesuai WCAG AA bila memungkinkan.
- Area klik/tap pada mobile tidak terlalu kecil; beri jarak antar aksi destruktif.
- Hormati `prefers-reduced-motion` dan jangan buat animation yang menghalangi fungsi.
- Empty, loading, error, success, forbidden, dan no-search-results harus punya tampilan jelas.
- Dark-mode dan sidebar mobile tidak boleh menghalangi focus ring, dropdown, atau modal.

## 11. Strategi penerapan ke aplikasi existing

### Tahap A — Audit (tanpa mengubah fungsionalitas)

1. Identifikasi versi Tailwind, framework frontend, file layout global, struktur komponen, dan CSS yang sudah ada.
2. Catat halaman, navigasi, role/permission, serta komponen yang dipakai ulang.
3. Temukan state aktif menu, modal, dropdown, form submission, validasi, filter, data table, dan chart agar tidak hilang.
4. Ambil screenshot keadaan awal untuk perbandingan sebelum/sesudah.

### Tahap B — Fondasi global

1. Tambahkan token `:root` dan font.
2. Refactor **app shell**: sidebar, topbar, page content, footer.
3. Pastikan responsive: normal / rail desktop / drawer mobile.
4. Buat komponen dasar yang reusable tanpa duplikasi styling: Button, Card, FormField, Badge, TableShell, Modal, PageHeader (sesuaikan naming codebase).

### Tahap C — Migrasi per halaman

1. Mulai dari **dashboard** dan satu halaman **daftar data** untuk mengunci bahasa visual.
2. Lanjutkan ke **form tambah/edit** dan **halaman detail**.
3. Terapkan pola yang sama ke semua modul lainnya; jangan membuat gaya khusus per halaman tanpa alasan.
4. Pertahankan handler dan API; bila menemukan UI lama yang sudah berfungsi, pindahkan class/styling, bukan menulis ulang logika.

### Tahap D — QA dan review

1. Periksa desktop `1440px`, laptop `1024px`, tablet `768px`, mobile `390px`, `375px`, `320px`.
2. Uji buka/tutup sidebar, accordion, rail, drawer/backdrop, scroll, dropdown, modal, form, pencarian, pagination, chart resize.
3. Bandingkan screenshot dengan Gentelella: warna, density, grid, border, radius, topbar, sidebar, hierarchy.
4. Uji aksesibilitas keyboard dan kontras, serta light/dark bila ada.
5. Jalankan build/lint/test proyek; perbaiki error sebelum menganggap redesign selesai.

## 12. Do / Don't

| DO | DON'T |
| --- | --- |
| Gunakan Tailwind dan CSS vars global | Menambahkan Bootstrap karena referensi berasal dari template admin |
| Prioritaskan sidebar navy, teal, dan card putih | Mengganti desain menjadi gradient neon/glassmorphism |
| Gunakan radius 4–8px dan border lembut | Card `rounded-2xl` / `rounded-3xl` di seluruh halaman |
| Jaga tipografi compact dan jelas | Heading terlalu besar, jarak kosong berlebihan |
| Pertahankan fitur dan data nyata | Mengisi dashboard dengan data demo palsu |
| Reuse komponen dan token yang sama | Hardcode warna dan radius berbeda di setiap halaman |
| Responsif di 320px | Overflow seluruh halaman pada mobile |
| Pertahankan aksesibilitas | Toggle dengan div non-interaktif atau icon tanpa label |
| Adaptasi menu sesuai bisnis aplikasi | Memaksakan menu Forms/Charts/Apps dari demo |

## 13. Acceptance criteria (definition of done)

Redesign dinyatakan selesai jika **semua** poin berikut terpenuhi:

- [ ] Sidebar normal memiliki lebar sekitar `252px`, warna navy, group label, nav active, submenu, profil pengguna.
- [ ] Sidebar rail desktop `64px` dan off-canvas mobile berfungsi; tidak ada menu yang tidak dapat diakses.
- [ ] Topbar tinggi `56px`, terlihat ringkas; breadcrumb dan action tidak saling menimpa.
- [ ] Background, warna aksen, border, radius, shadow, dan font seragam pada seluruh modul.
- [ ] Dashboard menggunakan card KPI/panel/chart/tabel **berdasarkan data nyata yang tersedia**.
- [ ] Semua tabel, filter, form, modal, badge, dropdown, pagination, empty/loading/error state mengikuti visual yang sama.
- [ ] Tidak ada horizontal overflow halaman pada ukuran mobile yang diuji.
- [ ] Semua navigasi, endpoint, validasi, hak akses, state, dan fungsi existing tetap berjalan.
- [ ] Tidak ada penambahan Bootstrap/jQuery hanya untuk styling; Tailwind tetap menjadi sistem styling utama.
- [ ] Akses keyboard, focus-visible, label, dan kontras teks komponen penting layak digunakan.
- [ ] Build dan test yang tersedia di proyek lulus, dan screenshot review mendekati bahasa visual referensi.

## 14. Instruksi singkat untuk AI coding assistant

Gunakan prompt berikut jika dokumen ini dijadikan pedoman kerja coding agent:

> Baca `desian.md` sebagai **sumber utama aturan tampilan**. Audit codebase sebelum menulis kode. Redesign UI aplikasi yang ada agar mengadopsi visual Gentelella v4 pada URL referensi, **tetapi tetap menggunakan Tailwind CSS dan stack proyek yang sudah ada**. Prioritaskan global design tokens, sidebar, topbar, card, table, forms, dan responsive state; gunakan komponen reusable, tanpa mengganti routing, backend, database, API, autentikasi, permission, handler, atau fitur. Jangan menambah Bootstrap/jQuery atau memasukkan data demo. Implementasi secara bertahap: shell → komponen dasar → dashboard → halaman lain; jalankan test/build dan verifikasi tampilan desktop/mobile. Jika desain berbenturan dengan fungsi existing, pertahankan fungsi dan adaptasi styling.

---

## 15. Sumber referensi

1. **Demo yang diminta:** https://preview.colorlib.com/theme/gentelella/production/
2. **Repo resmi Gentelella:** https://github.com/ColorlibHQ/gentelella
3. **Token SCSS asli v4:** https://github.com/ColorlibHQ/gentelella/blob/master/src/scss/v4/_tokens.scss
4. **Layout / responsive SCSS asli v4:** https://github.com/ColorlibHQ/gentelella/blob/master/src/scss/v4/_layout.scss
5. **Dokumentasi theming:** https://gentelella.colorlib.com/docs/theming/

> **Hak penggunaan:** Dokumen ini merupakan spesifikasi desain turunan/inspirasi untuk implementasi Tailwind pada produk sendiri, bukan paket kode resmi atau salinan lengkap Gentelella. Bila memakai source code, asset, ikon, atau library dari repository aslinya, periksa ketentuan lisensinya dan atribusi yang berlaku.
