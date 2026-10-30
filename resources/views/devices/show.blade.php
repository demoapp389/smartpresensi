@extends('layouts.app')

@section('title', $device->name)
@section('page-pretitle', 'Mesin Fingerprint')
@section('page-title', $device->name)
@section('page-subtitle', 'Kelola sinkronisasi, konfigurasi, dan pemeliharaan mesin Solution X601')

@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="transition-colors hover:text-[var(--text)]">Beranda</a>
    <span aria-hidden="true">/</span>
    <span>Mesin Fingerprint</span>
    <span aria-hidden="true">/</span>
    <a href="{{ route('devices.index') }}" class="transition-colors hover:text-[var(--text)]">Daftar Mesin</a>
    <span aria-hidden="true">/</span>
    <span class="font-medium text-[var(--text)]" aria-current="page">{{ $device->name }}</span>
@endsection

@section('content')
<div class="w-full"
     data-device-status-url="{{ route('devices.status', $device) }}"
     x-data="{
         bulkCloneModal: false,
         deleteUserModal: false,
         deleteUserPin: '',
         deleteUserConfirm: '',
         hasOtherDevices: {{ $otherDevices->isNotEmpty() ? 'true' : 'false' }},
         ...taskRunner({ progressUrl: '{{ url('api/v1/progress') }}', reloadOnDone: true, reloadDelay: 3500 })
     }">

    {{-- Dua kolom 50:50 pada desktop, satu kolom pada mobile. Tiap card setinggi kontennya. --}}
    <div class="grid grid-cols-1 items-start gap-3 lg:grid-cols-2">

        {{-- ══════════ KOLOM KIRI ══════════ --}}
        <div class="space-y-3">
            {{-- Konfigurasi mesin --}}
            <section class="min-w-0 overflow-hidden rounded-[var(--radius-lg)] border border-[var(--border)] bg-[var(--surface)] shadow-[var(--shadow)]">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--border)] px-3.5 py-2">
                    <div class="min-w-0">
                        <div class="card-title text-sm font-semibold text-[var(--text)]">Konfigurasi Mesin</div>
                        <div class="card-subtitle mt-0.5 text-[11px] text-[var(--text-muted)]">Perubahan IP/Port mengembalikan status koneksi ke Offline sampai diverifikasi ulang.</div>
                    </div>
                    <div class="card-options flex flex-wrap items-center gap-2">
                        <span class="device-status-badge">
                            <x-device-status :status="$device->displayStatus()" />
                        </span>
                        <form action="{{ route('devices.test-connection', $device) }}" method="POST"
                            data-confirm="Uji koneksi LAN ke {{ $device->name }} ({{ $device->ip }}:{{ $device->port }})?"
                            data-confirm-title="Tes Koneksi"
                            data-confirm-label="Tes Sekarang"
                            data-confirm-variant="info">
                            @csrf
                            <button type="submit"
                                class="inline-flex h-8.5 items-center gap-1.5 rounded-[var(--radius-sm)] bg-[var(--info)] px-3 text-xs font-semibold text-white transition-colors hover:brightness-95">
                                <x-icon name="plug" class="size-3.5" />
                                <span>Tes Koneksi</span>
                            </button>
                        </form>
                    </div>
                </div>

                @php
                    $timezoneOptions = [
                        'Asia/Jakarta' => 'WIB - Asia/Jakarta (GMT+7)',
                        'Asia/Pontianak' => 'WIB - Asia/Pontianak (GMT+7)',
                        'Asia/Makassar' => 'WITA - Asia/Makassar (GMT+8)',
                        'Asia/Jayapura' => 'WIT - Asia/Jayapura (GMT+9)',
                    ];
                @endphp

                <form action="{{ route('devices.update', $device) }}" method="POST" class="grid grid-cols-1 gap-x-3 gap-y-2.5 p-3 text-xs sm:grid-cols-2">
                    @csrf
                    @method('PUT')

                    <div class="sm:col-span-2">
                        <label for="edit-name" class="mb-1 block font-semibold text-[var(--text)]">Nama Mesin</label>
                        <input id="edit-name" type="text" name="name" value="{{ old('name', $device->name) }}" required
                            class="h-8.5 w-full rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] px-3 text-xs text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none focus:ring-1 focus:ring-[var(--primary-accessible)]">
                    </div>

                    <div>
                        <label for="edit-ip" class="mb-1 block font-semibold text-[var(--text)]">IP Address LAN</label>
                        <input id="edit-ip" type="text" name="ip" value="{{ old('ip', $device->ip) }}" required
                            class="h-8.5 w-full rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] px-3 font-mono text-xs text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none focus:ring-1 focus:ring-[var(--primary-accessible)]">
                    </div>

                    <div>
                        <label for="edit-port" class="mb-1 block font-semibold text-[var(--text)]">Port</label>
                        <input id="edit-port" type="number" name="port" value="{{ old('port', $device->port) }}" required
                            class="h-8.5 w-full rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] px-3 font-mono text-xs text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none focus:ring-1 focus:ring-[var(--primary-accessible)]">
                    </div>

                    <div>
                        <label for="edit-key" class="mb-1 block font-semibold text-[var(--text)]">Comm Key</label>
                        <input id="edit-key" type="text" name="comm_key" value="{{ old('comm_key', $device->comm_key) }}"
                            class="h-8.5 w-full rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] px-3 font-mono text-xs text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none focus:ring-1 focus:ring-[var(--primary-accessible)]">
                    </div>

                    <div>
                        <label for="edit-tz" class="mb-1 block font-semibold text-[var(--text)]">Timezone</label>
                        <select id="edit-tz" name="timezone" required
                            class="h-8.5 w-full rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] px-3 text-xs text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none focus:ring-1 focus:ring-[var(--primary-accessible)]">
                            @foreach($timezoneOptions as $tzValue => $tzLabel)
                                <option value="{{ $tzValue }}" {{ old('timezone', $device->timezone) === $tzValue ? 'selected' : '' }}>{{ $tzLabel }}</option>
                            @endforeach
                            @unless(array_key_exists(old('timezone', $device->timezone), $timezoneOptions))
                                <option value="{{ old('timezone', $device->timezone) }}" selected>{{ old('timezone', $device->timezone) }}</option>
                            @endunless
                        </select>
                    </div>

                    <div class="sm:col-span-2">
                        <label for="edit-status" class="mb-1 block font-semibold text-[var(--text)]">Mode Operasional</label>
                        <select id="edit-status" name="status" required
                            class="h-8.5 w-full rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] px-3 text-xs text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none focus:ring-1 focus:ring-[var(--primary-accessible)]">
                            <option value="active" {{ $device->status !== 'maintenance' ? 'selected' : '' }}>Aktif Digunakan</option>
                            <option value="maintenance" {{ $device->status === 'maintenance' ? 'selected' : '' }}>Dalam Pemeliharaan / Nonaktif Sementara</option>
                        </select>
                    </div>

                    <div class="flex justify-end sm:col-span-2">
                        <button type="submit"
                            class="rounded-[var(--radius-sm)] bg-[var(--primary)] px-3.5 py-1.5 text-xs font-semibold text-white transition-colors hover:bg-[var(--primary-dark)]">
                            Simpan Perubahan
                        </button>
                    </div>
                </form>
            </section>

            {{-- Sinkronisasi: tarik data dari mesin dan samakan jam --}}
            <section class="min-w-0 overflow-hidden rounded-[var(--radius-lg)] border border-[var(--border)] bg-[var(--surface)] shadow-[var(--shadow)]">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--border)] px-3.5 py-2">
                    <div class="min-w-0">
                        <div class="card-title text-sm font-semibold text-[var(--text)]">Sinkronisasi</div>
                        <div class="card-subtitle mt-0.5 text-[11px] text-[var(--text-muted)]">Ambil data terbaru dari mesin dan samakan jamnya dengan server.</div>
                    </div>

                    <div class="card-options flex flex-wrap items-center gap-2">
                        <a href="{{ route('devices.sync-history', ['device_id' => $device->id]) }}"
                            class="inline-flex h-8.5 items-center gap-1.5 rounded-[var(--radius-sm)] bg-[var(--text-secondary)] px-3 text-xs font-semibold text-white transition-colors hover:bg-[var(--text)]">
                            <x-icon name="sync-history" class="size-3.5" />
                            <span>Riwayat Sinkronisasi</span>
                        </a>
                    </div>
                </div>

                <div class="flex flex-wrap gap-2 p-3">
                    <form action="{{ route('devices.sync-students', $device) }}" method="POST"
                        @submit.prevent="askConfirm({ title: 'Sinkronisasi Siswa', message: 'Ambil seluruh ID & nama siswa yang ada di {{ $device->name }}?', confirmLabel: 'Sinkronisasi', variant: 'primary' }).then(ok => { if (ok) { submit($el, 'Sinkronisasi Siswa', 'sync-students'); } })"
                        class="flex flex-wrap items-center gap-2">
                        @csrf
                        <label
                            class="inline-flex h-8.5 cursor-pointer items-center gap-1.5 rounded-[var(--radius-sm)] border border-[var(--border)] px-2.5 text-[11px] font-medium text-[var(--text-muted)]"
                            title="Buat siswa baru tanpa NIS dan petakan otomatis untuk PIN yang belum punya mapping. NIS dapat diisi kemudian lewat edit/import.">
                            <input type="checkbox" name="create_students" value="1"
                                class="size-3.5 accent-[var(--primary)]">
                            <span>Buat siswa otomatis</span>
                        </label>
                        <button type="submit" x-bind:disabled="running"
                            class="inline-flex h-8.5 items-center gap-1.5 rounded-[var(--radius-sm)] bg-[var(--primary)] px-3 text-xs font-semibold text-white transition-colors hover:bg-[var(--primary-dark)] disabled:cursor-not-allowed disabled:opacity-50">
                            <svg x-show="running && activeAction === 'sync-students'" class="size-3.5 animate-spin" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/>
                            </svg>
                            <x-icon name="mapping" class="size-3.5" x-show="! (running && activeAction === 'sync-students')" />
                            <span x-text="running && activeAction === 'sync-students' ? 'Menyinkronkan siswa...' : 'Sinkronisasi Siswa'"></span>
                        </button>
                    </form>

                    <form action="{{ route('devices.sync-attendances', $device) }}" method="POST"
                        @submit.prevent="askConfirm({ title: 'Sinkronisasi Absensi', message: 'Ambil riwayat absensi terbaru dari {{ $device->name }}?', confirmLabel: 'Sinkronisasi', variant: 'primary' }).then(ok => { if (ok) { submit($el, 'Sinkronisasi Absensi', 'sync-attendance'); } })">
                        @csrf
                        <button type="submit" x-bind:disabled="running"
                            title="Ambil absensi terbaru dari mesin"
                            class="inline-flex h-8.5 items-center gap-1.5 rounded-[var(--radius-sm)] bg-[var(--primary)] px-3 text-xs font-semibold text-white transition-colors hover:bg-[var(--primary-dark)] disabled:cursor-not-allowed disabled:opacity-50">
                            <svg x-show="running && activeAction === 'sync-attendance'" class="size-3.5 animate-spin" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/>
                            </svg>
                            <x-icon name="refresh" class="size-3.5" x-show="! (running && activeAction === 'sync-attendance')" />
                            <span x-text="running && activeAction === 'sync-attendance' ? 'Menyinkronkan absensi...' : 'Sinkronisasi Absensi'"></span>
                        </button>
                    </form>

                    <form action="{{ route('devices.sync-time', $device) }}" method="POST"
                        data-confirm="Samakan tanggal dan jam mesin {{ $device->name }} dengan jam server?"
                        data-confirm-title="Sinkronisasi Waktu"
                        data-confirm-label="Sinkronkan Jam"
                        data-confirm-variant="warning">
                        @csrf
                        <button type="submit" x-bind:disabled="running"
                            title="Samakan tanggal dan jam mesin agar cocok dengan server"
                            class="inline-flex h-8.5 items-center gap-1.5 rounded-[var(--radius-sm)] bg-[var(--primary)] px-3 text-xs font-semibold text-white transition-colors hover:bg-[var(--primary-dark)] disabled:cursor-not-allowed disabled:opacity-50">
                            <x-icon name="schedule" class="size-3.5" />
                            <span>Sinkronisasi Waktu</span>
                        </button>
                    </form>
                </div>
            </section>

            {{-- Pemeliharaan & tindakan operasional mesin --}}
            <section class="min-w-0 overflow-hidden rounded-[var(--radius-lg)] border border-[var(--border)] bg-[var(--surface)] shadow-[var(--shadow)]">
                <div class="border-b border-[var(--border)] px-3.5 py-2">
                    <div class="card-title text-sm font-semibold text-[var(--text)]">Pemeliharaan Mesin</div>
                    <div class="card-subtitle mt-0.5 text-[11px] text-[var(--text-muted)]">
                        Tindakan berikut langsung mengubah isi mesin dan dicatat pada Log Audit Sistem.
                    </div>
                </div>

                <div class="space-y-3 p-3">
                    <div class="flex items-start gap-2.5 rounded-[var(--radius)] border border-amber-200 bg-amber-50/60 p-2.5 text-xs text-amber-900">
                        <x-icon name="info" class="mt-0.5 size-4 shrink-0 text-amber-600" />
                        <p class="leading-relaxed">
                            Pastikan data sudah disinkronkan ke server sebelum menjalankan tindakan berisiko.
                        </p>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <form action="{{ route('devices.bulk-push-names', $device) }}" method="POST"
                            x-on:submit.prevent="askConfirm({ title: 'Kirim Nama Siswa', message: 'Kirim nama SELURUH siswa terpetakan ke layar mesin {{ $device->name }}?', confirmLabel: 'Kirim', variant: 'info' }).then(ok => { if (ok) { submit($el, 'Kirim Nama ke {{ $device->name }}', 'push-names'); } })">
                            @csrf
                            <button type="submit" x-bind:disabled="running"
                                class="inline-flex h-8.5 items-center gap-1.5 rounded-[var(--radius-sm)] bg-[var(--info)] px-3 text-xs font-semibold text-white transition-colors hover:brightness-95 disabled:opacity-50">
                                <svg x-show="running && activeAction === 'push-names'" class="size-3.5 animate-spin" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/>
                                </svg>
                                <x-icon name="send" class="size-3.5" x-show="! (running && activeAction === 'push-names')" />
                                <span x-text="running && activeAction === 'push-names' ? 'Mengirim nama ke mesin...' : 'Kirim Nama Siswa'"></span>
                            </button>
                        </form>

                        <form action="{{ route('devices.bulk-backup-fingerprints', $device) }}" method="POST"
                            x-on:submit.prevent="askConfirm({ title: 'Upload Sidik Jari', message: 'Upload SELURUH data sidik jari dari mesin {{ $device->name }} ke server?', confirmLabel: 'Upload', variant: 'primary' }).then(ok => { if (ok) { submit($el, 'Upload Sidik Jari {{ $device->name }}', 'backup-fingerprints'); } })">
                            @csrf
                            <button type="submit" x-bind:disabled="running"
                                class="inline-flex h-8.5 items-center gap-1.5 rounded-[var(--radius-sm)] bg-[var(--success)] px-3 text-xs font-semibold text-white transition-colors hover:brightness-95 disabled:opacity-50">
                                <svg x-show="running && activeAction === 'backup-fingerprints'" class="size-3.5 animate-spin" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/>
                                </svg>
                                <x-icon name="download" class="size-3.5" x-show="! (running && activeAction === 'backup-fingerprints')" />
                                <span x-text="running && activeAction === 'backup-fingerprints' ? 'Mengunggah sidik jari...' : 'Upload Sidik Jari'"></span>
                            </button>
                        </form>

                        <button type="button" x-on:click="bulkCloneModal = true"
                            @disabled($otherDevices->isEmpty())
                            x-bind:disabled="running || ! hasOtherDevices"
                            title="{{ $otherDevices->isEmpty() ? 'Perlu minimal 2 mesin terdaftar' : 'Salin seluruh data mesin ini ke mesin lain' }}"
                            class="inline-flex h-8.5 items-center gap-1.5 rounded-[var(--radius-sm)] bg-purple-700 px-3 text-xs font-semibold text-white transition-colors hover:bg-purple-800 disabled:cursor-not-allowed disabled:opacity-50">
                            <x-icon name="copy" class="size-3.5" />
                            <span>Salin Data ke Mesin Lain</span>
                        </button>
                    </div>

                    <div class="rounded-[var(--radius)] border border-amber-200 bg-amber-50/40 p-3">
                        <h3 class="text-xs font-bold text-[var(--text)]">Tindakan Berisiko Tinggi</h3>

                        <div class="mt-2 flex flex-wrap gap-2">
                            <form action="{{ route('devices.restart', $device) }}" method="POST"
                                data-confirm="Restart mesin {{ $device->name }} dari jarak jauh? Mesin akan berhenti melayani absensi selama beberapa detik."
                                data-confirm-title="Restart Mesin"
                                data-confirm-label="Restart Sekarang"
                                data-confirm-variant="warning">
                                @csrf
                                <button type="submit" x-bind:disabled="running"
                                    class="inline-flex h-8.5 items-center gap-1.5 rounded-[var(--radius-sm)] bg-[var(--warning)] px-3 text-xs font-semibold text-white transition-colors hover:brightness-95 disabled:cursor-not-allowed disabled:opacity-50">
                                    <x-icon name="refresh" class="size-3.5" />
                                    <span>Restart Mesin</span>
                                </button>
                            </form>

                            <form action="{{ route('devices.clear-logs', $device) }}" method="POST"
                                data-confirm="PERINGATAN: hapus riwayat log absensi di memori mesin {{ $device->name }}? Data yang belum ditarik ke server akan hilang permanen."
                                data-confirm-title="Hapus Log Absensi di Mesin"
                                data-confirm-label="Hapus Permanen"
                                data-confirm-variant="danger">
                                @csrf
                                <button type="submit" x-bind:disabled="running"
                                    class="inline-flex h-8.5 items-center gap-1.5 rounded-[var(--radius-sm)] bg-[var(--danger)] px-3 text-xs font-semibold text-white transition-colors hover:brightness-95 disabled:cursor-not-allowed disabled:opacity-50">
                                    <x-icon name="trash" class="size-3.5" />
                                    <span>Hapus Log Absensi di Mesin</span>
                                </button>
                            </form>
                        </div>

                        <p class="mt-2 text-[11px] text-[var(--text-muted)]">
                            Kedua aksi tersebut memerlukan konfirmasi eksplisit dan tercatat pada Log Audit Sistem.
                        </p>
                    </div>
                </div>
            </section>
        </div>

        {{-- ══════════ KOLOM KANAN ══════════ --}}
        <div class="space-y-3">
            {{-- Connector & jadwal --}}
            <section class="min-w-0 overflow-hidden rounded-[var(--radius-lg)] border border-[var(--border)] bg-[var(--surface)] shadow-[var(--shadow)]">
                <div class="border-b border-[var(--border)] px-3.5 py-2">
                    <div class="card-title text-sm font-semibold text-[var(--text)]">Connector &amp; Jadwal</div>
                    <div class="card-subtitle mt-0.5 text-[11px] text-[var(--text-muted)]">Berjalan lintas OS (Windows &amp; Linux) dengan Python.</div>
                </div>

                <div class="space-y-2.5 p-3 text-xs">
                    <p class="leading-relaxed text-[var(--text-secondary)]">
                        Token rahasia tidak ditampilkan di halaman ini. Unduh file konfigurasi siap pakai
                        (berisi IP, port, comm key, dan token) untuk dipasang di folder Connector.
                    </p>

                    <a href="{{ route('devices.connector-config', $device) }}"
                        class="inline-flex h-8.5 items-center gap-1.5 rounded-[var(--radius-sm)] bg-[var(--primary)] px-3 text-xs font-semibold text-white transition-colors hover:bg-[var(--primary-dark)]">
                        <x-icon name="download" class="size-3.5" />
                        <span>Unduh connector_config.ini</span>
                    </a>

                    <p class="text-[11px] text-[var(--text-muted)]">
                        Setiap unduh konfigurasi dicatat pada Log Audit Sistem.
                    </p>

                    <div class="border-t border-[var(--border-subtle)] pt-2.5">
                        <h3 class="mb-2 text-[11px] font-bold uppercase tracking-wide text-[var(--text-muted)]">Jadwal Otomatis</h3>
                        <dl class="space-y-1.5">
                            @foreach([
                                'Connector memantau mesin' => 'Setiap 5 detik',
                                'Cadangan sinkronisasi absensi' => '5 menit saat jam sekolah',
                                'Sinkron jam via Web SOAP' => 'Setiap hari 05.00',
                                'Health check konektivitas' => 'Setiap 1 menit',
                            ] as $label => $value)
                                <div class="flex items-start justify-between gap-3 text-[11px]">
                                    <dt class="text-[var(--text-secondary)]">{{ $label }}</dt>
                                    <dd class="shrink-0 font-semibold text-[var(--text)]">{{ $value }}</dd>
                                </div>
                            @endforeach
                        </dl>
                    </div>
                </div>
            </section>

            {{-- Zona berbahaya --}}
            <section class="rounded-[var(--radius-lg)] border border-rose-200 bg-rose-50/40 p-3">
                <h2 class="text-xs font-bold text-rose-800">Zona Berbahaya</h2>
                <p class="mt-0.5 text-[11px] text-rose-700">
                    Tindakan berikut tidak dapat dibatalkan atau berdampak pada keamanan.
                </p>

                {{-- Buat ulang token akses connector --}}
                <div class="mt-2.5 rounded-[var(--radius)] border border-rose-200 bg-[var(--surface)] p-3">
                    <h3 class="text-xs font-bold text-[var(--text)]">Buat Ulang Token Akses Connector</h3>
                    <p class="mt-0.5 text-[11px] text-[var(--text-muted)]">
                        Token akses Connector yang lama langsung tidak berlaku. Setelah dibuat ulang, unduh kembali file konfigurasi dan pasang pada Connector.
                    </p>
                    <form action="{{ route('devices.rotate-token', $device) }}" method="POST" class="mt-2"
                        data-confirm="Buat ulang token akses Connector? Token lama akan langsung tidak berlaku dan Connector harus dipasang ulang file konfigurasinya."
                        data-confirm-title="Buat Ulang Token Akses Connector"
                        data-confirm-label="Buat Ulang Token"
                        data-confirm-variant="warning">
                        @csrf
                        <button type="submit" x-bind:disabled="running"
                            class="inline-flex h-8.5 items-center gap-1.5 rounded-[var(--radius-sm)] bg-[var(--danger)] px-3 text-xs font-semibold text-white transition-colors hover:brightness-95 disabled:cursor-not-allowed disabled:opacity-50">
                            <x-icon name="refresh" class="size-3.5" />
                            <span>Buat Ulang Token</span>
                        </button>
                    </form>
                </div>

                {{-- Hapus pengguna di memori mesin --}}
                <div class="mt-2.5 rounded-[var(--radius)] border border-rose-200 bg-[var(--surface)] p-3">
                    <h3 class="text-xs font-bold text-[var(--text)]">Hapus Pengguna di Mesin</h3>
                    <p class="mt-0.5 text-[11px] text-[var(--text-muted)]">
                        Menghapus satu pengguna (PIN) beserta data sidik jari/wajah pada mesin {{ $device->name }}.
                        Data pada server tidak diubah. Tindakan ini tidak dapat dibatalkan.
                    </p>
                    <button type="button" x-on:click="deleteUserModal = true" x-bind:disabled="running"
                        class="mt-2 inline-flex h-8.5 items-center gap-1.5 rounded-[var(--radius-sm)] bg-[var(--danger)] px-3 text-xs font-semibold text-white transition-colors hover:brightness-95 disabled:cursor-not-allowed disabled:opacity-50">
                        <x-icon name="trash" class="size-3.5" />
                        <span>Hapus Pengguna di Mesin</span>
                    </button>
                </div>

                {{-- Hapus mesin --}}
                <div class="mt-2.5 rounded-[var(--radius)] border border-rose-200 bg-[var(--surface)] p-3">
                    <h3 class="text-xs font-bold text-[var(--text)]">Hapus Mesin</h3>
                    <p class="mt-0.5 text-[11px] text-[var(--text-muted)]">
                        Menghapus seluruh pemetaan PIN, riwayat sinkronisasi, dan log absensi dari mesin ini secara permanen.
                    </p>
                    <form action="{{ route('devices.destroy', $device) }}" method="POST" class="mt-2"
                        data-confirm="YAKIN hapus mesin {{ $device->name }}? Seluruh pemetaan PIN, riwayat sinkronisasi, dan log absensi mesin ini akan dihapus permanen."
                        data-confirm-title="Hapus Mesin"
                        data-confirm-label="Hapus Permanen"
                        data-confirm-variant="danger">
                        @csrf
                        @method('DELETE')
                        <button type="submit" x-bind:disabled="running"
                            class="inline-flex h-8.5 items-center gap-1.5 rounded-[var(--radius-sm)] bg-[var(--danger)] px-3 text-xs font-semibold text-white transition-colors hover:brightness-95 disabled:cursor-not-allowed disabled:opacity-50">
                            <x-icon name="trash" class="size-3.5" />
                            <span>Hapus Mesin</span>
                        </button>
                    </form>
                </div>
            </section>
        </div>
    </div>

    {{-- Modal kloning mesin --}}
    <div x-cloak x-show="bulkCloneModal" class="fixed inset-0 z-50 flex items-center justify-center bg-[#0f1623]/60 p-4 backdrop-blur-xs">
        <div x-on:click.away="bulkCloneModal = false"
            class="w-full max-w-md rounded-[var(--radius-lg)] border border-[var(--border)] bg-[var(--surface)] p-5 shadow-[var(--shadow)]">
            <h2 class="text-sm font-bold text-[var(--text)]">Salin Seluruh Data ke Mesin Lain</h2>
            <p class="mt-0.5 text-[11px] text-[var(--text-secondary)]">
                Salin semua nama siswa, PIN, dan sidik jari dari {{ $device->name }} ke mesin tujuan.
            </p>

            <form action="{{ route('devices.bulk-clone', $device) }}" method="POST" class="mt-3 space-y-3 text-xs"
                x-on:submit.prevent="askConfirm({ title: 'Salin Data ke Mesin Lain', message: 'Mulai salin seluruh data siswa & sidik jari ke mesin target?', confirmLabel: 'Mulai Salin', variant: 'primary' }).then(ok => { if (ok) { submit($el, 'Salin Data {{ $device->name }}', 'bulk-clone'); bulkCloneModal = false; } })">
                @csrf

                <div>
                    <label for="clone-device" class="mb-1 block font-semibold text-[var(--text)]">Pilih Mesin Target</label>
                    <select id="clone-device" name="target_device_id" required
                        class="h-9 w-full rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] px-3 text-xs text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none">
                        @foreach($otherDevices as $target)
                            <option value="{{ $target->id }}">{{ $target->name }} ({{ $target->ip }})</option>
                        @endforeach
                    </select>
                    <span class="mt-1 block text-[10px] text-[var(--text-muted)]">
                        Siswa tidak perlu melakukan perekaman jari ulang di mesin tujuan.
                    </span>
                </div>

                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" x-on:click="bulkCloneModal = false"
                        class="rounded-[var(--radius-sm)] bg-[var(--text-secondary)] px-3.5 py-1.5 text-xs font-semibold text-white transition-colors hover:bg-[var(--text)]">
                        Batal
                    </button>
                    <button type="submit" x-bind:disabled="running"
                        class="inline-flex items-center gap-1.5 rounded-[var(--radius-sm)] bg-purple-700 px-3.5 py-1.5 text-xs font-semibold text-white transition-colors hover:bg-purple-800 disabled:opacity-50">
                        <span x-text="running && activeAction === 'bulk-clone' ? 'Menyalin data...' : 'Mulai Salin'"></span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- Modal hapus pengguna di mesin (konfirmasi ketik) --}}
    <div x-cloak x-show="deleteUserModal" class="fixed inset-0 z-50 flex items-center justify-center bg-[#0f1623]/60 p-4 backdrop-blur-xs">
        <div x-on:click.away="deleteUserModal = false"
            class="w-full max-w-md rounded-[var(--radius-lg)] border border-rose-200 bg-[var(--surface)] p-5 shadow-[var(--shadow)]">
            <h2 class="text-sm font-bold text-rose-800">Hapus Pengguna di Mesin</h2>
            <p class="mt-0.5 text-[11px] text-[var(--text-secondary)]">
                Menghapus satu pengguna (PIN) beserta data sidik jari/wajah pada mesin
                <span class="font-semibold">{{ $device->name }}</span>. Data pada server tidak diubah.
                Tindakan ini <span class="font-semibold text-rose-700">tidak dapat dibatalkan</span>.
            </p>

            <form action="{{ route('devices.delete-user', $device) }}" method="POST" class="mt-3 space-y-3 text-xs">
                @csrf

                <div>
                    <label for="delete-user-pin" class="mb-1 block font-semibold text-[var(--text)]">PIN pengguna yang akan dihapus</label>
                    <input id="delete-user-pin" type="text" name="pin" x-model="deleteUserPin" required autocomplete="off"
                        inputmode="text" placeholder="mis. 101"
                        class="h-9 w-full rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] px-3 text-xs text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none">
                </div>

                <div>
                    <label for="delete-user-confirm" class="mb-1 block font-semibold text-[var(--text)]">
                        Ketik persis
                        <span class="font-mono text-rose-700" x-text="'HAPUS ' + (deleteUserPin.trim() || '…')"></span>
                        untuk mengonfirmasi
                    </label>
                    <input id="delete-user-confirm" type="text" name="confirm" x-model="deleteUserConfirm" required autocomplete="off"
                        x-bind:placeholder="'HAPUS ' + (deleteUserPin.trim() || '…')"
                        class="h-9 w-full rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] px-3 font-mono text-xs text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none">
                </div>

                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" x-on:click="deleteUserModal = false"
                        class="rounded-[var(--radius-sm)] bg-[var(--text-secondary)] px-3.5 py-1.5 text-xs font-semibold text-white transition-colors hover:bg-[var(--text)]">
                        Batal
                    </button>
                    <button type="submit"
                        x-bind:disabled="running || deleteUserPin.trim() === '' || deleteUserConfirm !== ('HAPUS ' + deleteUserPin.trim())"
                        class="inline-flex items-center gap-1.5 rounded-[var(--radius-sm)] bg-[var(--danger)] px-3.5 py-1.5 text-xs font-semibold text-white transition-colors hover:brightness-95 disabled:cursor-not-allowed disabled:opacity-50">
                        <x-icon name="trash" class="size-3.5" />
                        <span>Hapus Permanen</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
(() => {
    const container = document.querySelector('[data-device-status-url]');
    const badge = container?.querySelector('.device-status-badge');

    if (! container || ! badge) {
        return;
    }

    const refreshBadge = async () => {
        try {
            const response = await fetch(container.dataset.deviceStatusUrl, {
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                cache: 'no-store',
            });

            if (! response.ok) {
                return;
            }

            const data = await response.json();
            badge.innerHTML = data.html;
        } catch (error) {
            console.error('Gagal memperbarui status perangkat', error);
        }
    };

    setInterval(() => {
        if (! document.hidden) {
            refreshBadge();
        }
    }, 30000);
})();
</script>
@endsection
