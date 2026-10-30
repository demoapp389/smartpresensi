{{--
  Struktur navigasi sidebar.

  Definisi menu dipusatkan di sini agar hak akses, active state, dan urutan
  hanya ditentukan satu kali. Setiap kategori dirender lewat
  <x-sidebar-group>, dan grup yang punya anak sendiri (Machine Fingerprint /
  WhatsApp) dirender sebagai <x-sidebar-tree> supaya bisa dilipat.

  Bentuk data mengikuti template Gentelella:
    - grup  : { label, icon, items[] }  -> <x-sidebar-group> (kategori statis)
    - leaf  : { route, label, icon, active, badge }   -> <x-sidebar-link>
    - pohon : { label, icon, items[] }  -> <x-sidebar-tree> (submenu lipatan)

  Aktivasi item memakai request()->routeIs() sehingga active state mengikuti
  route secara otomatis.
--}}

@php
    $user = Auth::user();
    $isAdmin = $user?->isAdmin() ?? false;
    $isWali = $user?->isHomeroomTeacher() ?? false;

    // Wali kelas tidak mengakses modul operasional (log mentah, mesin, dsb).
    $canOperate = ! $isWali;

    $unmappedCount = $canOperate
        ? \App\Models\AttendanceLog::where('status', 'unmapped')->count()
        : 0;

    $link = fn (string $route, string $label, string $icon, array|string|null $active = null, ?int $badge = null, string $badgeTone = 'warning') => [
        'route' => $route,
        'label' => $label,
        'icon' => $icon,
        'active' => is_array($active) ? request()->routeIs(...$active) : request()->routeIs($active ?? $route),
        'badge' => $badge,
        'badgeTone' => $badgeTone,
    ];

    $categories = [
        [
            'storageKey' => 'dashboard',
            'label' => 'Monitoring',
            'icon' => 'dashboard',
            'items' => [
                $link('dashboard', 'Dashboard', 'dashboard', ['dashboard', 'home']),
            ],
        ],
        [
            'storageKey' => 'presensi',
            'label' => 'Presensi',
            'icon' => 'attendance',
            'items' => array_values(array_filter([
                $link('attendances.index', 'Riwayat Presensi', 'attendance'),
                $canOperate ? $link('logs.raw', 'Riwayat Scan', 'raw-log') : null,
                $canOperate ? $link('logs.unmapped', 'Perlu Peninjauan', 'review', null, $unmappedCount ?: null) : null,
                $link(
                    'attendances.correction',
                    $isWali ? 'Presensi Manual' : 'Koreksi Operator',
                    'edit'
                ),
                $link('attendances.reports.index', 'Laporan Presensi', 'report', ['attendances.reports.*']),
            ])),
        ],
        [
            'storageKey' => 'master-data',
            'label' => 'Master Data',
            'icon' => 'student',
            'items' => array_values(array_filter([
                $canOperate ? $link('students.index', 'Siswa & Wali', 'student') : null,
                $canOperate ? $link('classes.index', 'Kelas', 'classroom') : null,
                $isAdmin ? $link('schedules.index', 'Jadwal Absensi', 'schedule') : null,
            ])),
        ],
        [
            'storageKey' => 'perangkat',
            'label' => 'Perangkat & Integrasi',
            'icon' => 'device',
            'items' => array_values(array_filter([
                $isAdmin ? [
                    'label' => 'Mesin Fingerprint',
                    'icon' => 'device',
                    'items' => [
                        $link('devices.index', 'Daftar Mesin', 'device', 'devices.index'),
                        $link('devices.mappings', 'Pemetaan PIN Siswa', 'mapping'),
                        $link('devices.sync-history', 'Riwayat Sinkronisasi', 'sync-history'),
                    ],
                ] : null,
                $canOperate ? [
                    'label' => 'WhatsApp',
                    'icon' => 'whatsapp',
                    'items' => array_values(array_filter([
                        $link('notifications.index', 'Outbox Pesan', 'inbox'),
                        $link('whatsapp.broadcast.create', 'Siaran Pengumuman', 'send'),
                        $link('guardians.validation', 'Validasi Nomor Wali', 'phone-check'),
                        $isAdmin ? $link('whatsapp.templates.index', 'Template Pesan', 'edit') : null,
                        $isAdmin ? $link('notifications.gateway', 'Pengaturan Gateway', 'gateway') : null,
                    ])),
                ] : null,
            ])),
        ],
        [
            'storageKey' => 'administrasi',
            'label' => 'Administrasi Sistem',
            'icon' => 'system-control',
            'items' => array_values(array_filter([
                $isAdmin ? $link('users.index', 'Manajemen Pengguna', 'users') : null,
                $isAdmin ? $link('audit.index', 'Log Audit', 'audit') : null,
                $isAdmin ? $link('system.control', 'Pusat Kontrol Sistem', 'system-control') : null,
                $isAdmin ? $link('documentation.index', 'Dokumentasi', 'document') : null,
            ])),
        ],
    ];

    $categories = array_values(array_filter($categories, fn ($category) => $category['items'] !== []));
@endphp

@foreach($categories as $category)
    <x-sidebar-group
        :storage-key="$category['storageKey']"
        :label="$category['label']"
        :icon="$category['icon']"
        :items="$category['items']" />
@endforeach
