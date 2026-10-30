@extends('layouts.app')

@section('title', 'Mesin Fingerprint')
@section('page-pretitle', 'Mesin Fingerprint')
@section('page-title', 'Mesin Fingerprint')
@section('page-subtitle', 'Terminal Solution X601 yang terhubung ke jaringan sekolah')

@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="transition-colors hover:text-[var(--text)]">Beranda</a>
    <span aria-hidden="true">/</span>
    <span>Mesin Fingerprint</span>
    <span aria-hidden="true">/</span>
    <span class="font-medium text-[var(--text)]" aria-current="page">Daftar Mesin</span>
@endsection

@section('content')
<section
    class="min-w-0 rounded-[var(--radius-lg)] border border-[var(--border)] bg-[var(--surface)] shadow-[var(--shadow)] overflow-hidden"
    data-device-status-url-template="{{ route('devices.status', ['device' => '__ID__']) }}">

    {{-- Card header (.card-header Gentelella): judul + aksi, di atas baris pencarian --}}
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--border)] px-4 py-3">
        <div>
            <div class="card-title text-sm font-semibold text-[var(--text)]">Daftar Mesin Terdaftar</div>
            <div class="card-subtitle mt-0.5 text-xs text-[var(--text-muted)]">{{ $devices->count() }} mesin terdaftar</div>
        </div>

        <div class="card-options flex flex-wrap items-center gap-2">
            @if($search)
                <a href="{{ route('devices.index') }}"
                    class="inline-flex h-8.5 items-center justify-center gap-1.5 rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface)] px-3 text-xs font-medium text-[var(--text-secondary)] transition-colors hover:bg-[var(--surface-secondary)]">
                    <x-icon name="close" class="size-3" />
                    <span>Reset Pencarian</span>
                </a>
            @endif

            <button type="button" onclick="window.location.reload()" title="Muat ulang daftar mesin"
                class="inline-flex h-8.5 items-center justify-center gap-1.5 rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface)] px-3 text-xs font-semibold text-[var(--text-secondary)] transition-colors hover:bg-[var(--surface-secondary)]">
                <x-icon name="refresh" class="size-3.5" />
                <span>Refresh</span>
            </button>

            <button type="button" onclick="document.getElementById('modal-add-device').classList.remove('hidden')"
                class="inline-flex h-8.5 items-center justify-center gap-1.5 rounded-[var(--radius-sm)] bg-[var(--primary)] px-3 text-xs font-semibold text-white transition-colors hover:brightness-90">
                <svg class="size-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                <span>Tambah Mesin</span>
            </button>
        </div>
    </div>

    {{-- .table-responsive: hanya baris tabel yang scroll horizontal, sedangkan
         baris pencarian dan pagination tetap menempel di lebar card
         (lihat .dt-layout-row.dt-layout-table di resources/css/datatables.css) --}}
    <div>
        <table class="w-full text-left" data-datatable data-page-length="10" data-length-change="true">
            <thead class="bg-[var(--surface-secondary)]">
                <tr>
                    <th style="width:48px" class="px-4 py-2 text-[11px] font-bold tracking-wider text-[var(--text-muted)] uppercase">No</th>
                    <th class="px-4 py-2 text-[11px] font-bold tracking-wider text-[var(--text-muted)] uppercase">Mesin & Lokasi</th>
                    <th class="px-4 py-2 text-[11px] font-bold tracking-wider text-[var(--text-muted)] uppercase">IP Address</th>
                    <th class="px-4 py-2 text-[11px] font-bold tracking-wider text-[var(--text-muted)] uppercase">Port</th>
                    <th class="px-4 py-2 text-[11px] font-bold tracking-wider text-[var(--text-muted)] uppercase">Status</th>
                    <th class="px-4 py-2 text-right text-[11px] font-bold tracking-wider text-[var(--text-muted)] uppercase">Siswa Terpetakan</th>
                    <th class="px-4 py-2 text-right text-[11px] font-bold tracking-wider text-[var(--text-muted)] uppercase">Total Log</th>
                    <th class="px-4 py-2 text-[11px] font-bold tracking-wider text-[var(--text-muted)] uppercase">Sinkron Terakhir</th>
                    <th style="width:150px" class="px-4 py-2 text-right text-[11px] font-bold tracking-wider text-[var(--text-muted)] uppercase" data-orderable="false">Aksi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-[var(--border-subtle)] text-[13px]">
                @forelse($devices as $dev)
                    @php
                        $location = str_contains($dev->name, '-')
                            ? trim(Str::after($dev->name, '-'))
                            : null;
                    @endphp

                    <tr class="transition-colors hover:bg-[var(--surface-secondary)]">
                        <td class="px-4 py-2.5 text-center font-mono text-xs text-[var(--text-muted)]">
                            {{ $loop->iteration }}
                        </td>
                        <td class="min-w-[220px] px-4 py-2.5">
                            <div class="truncate font-medium text-[var(--text)]">{{ $dev->name }}</div>
                            @if($location)
                                <div class="mt-0.5 flex items-center gap-1 text-[11px] text-[var(--text-muted)]">
                                    <x-icon name="gateway" class="size-3 shrink-0" />
                                    <span class="truncate">{{ $location }}</span>
                                </div>
                            @endif
                        </td>

                        <td class="px-4 py-2.5 font-mono text-xs whitespace-nowrap text-[var(--text)]">{{ $dev->ip }}</td>
                        <td class="px-4 py-2.5 font-mono text-xs whitespace-nowrap text-[var(--text-secondary)]">{{ $dev->port }}</td>

                        <td class="px-4 py-2.5" data-device-id="{{ $dev->id }}">
                            <span class="device-status-badge">
                                <x-device-status :status="$dev->displayStatus()" />
                            </span>
                            <div class="mt-0.5 text-[10px] text-[var(--text-muted)] device-last-seen">
                                @if($dev->last_seen_at)
                                    Terakhir online {{ $dev->last_seen_at->format('d/m H:i') }}
                                @else
                                    Belum pernah online
                                @endif
                            </div>
                        </td>

                        <td class="px-4 py-2.5 text-right">
                            <a href="{{ route('devices.mappings', ['device_id' => $dev->id]) }}"
                                class="font-medium text-[var(--primary-accessible)] hover:underline">
                                {{ $dev->device_users_count }}
                            </a>
                        </td>

                        <td class="px-4 py-2.5 text-right font-mono text-xs text-[var(--text)]">{{ $dev->attendance_logs_count }}</td>

                        <td class="px-4 py-2.5 whitespace-nowrap">
                            @if($dev->latestSyncLog)
                                <span class="text-[var(--text-secondary)]">{{ $dev->latestSyncLog->created_at->format('d/m/Y H:i') }}</span>
                            @else
                                <span class="text-[var(--text-muted)] italic">Belum pernah</span>
                            @endif
                        </td>

                        <td class="px-4 py-2.5">
                            <div class="flex items-center justify-end gap-1.5">
                                <form action="{{ route('devices.test-connection', $dev) }}" method="POST"
                                    data-confirm="Uji koneksi LAN ke {{ $dev->name }} ({{ $dev->ip }}:{{ $dev->port }})?"
                                    data-confirm-title="Tes Koneksi"
                                    data-confirm-label="Tes Sekarang"
                                    data-confirm-variant="info">
                                    @csrf
                                    <input type="hidden" name="return_to" value="{{ route('devices.index') }}">
                                    <button type="submit"
                                        title="Tes Koneksi" aria-label="Tes Koneksi {{ $dev->name }}"
                                        class="inline-flex items-center gap-1 rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface)] px-2 py-1 text-xs font-medium whitespace-nowrap text-[var(--text-secondary)] transition-colors hover:bg-[var(--surface-secondary)]">
                                        <x-icon name="plug" class="size-3" />
                                        <span>Tes</span>
                                    </button>
                                </form>
                                <a href="{{ route('devices.show', $dev) }}"
                                    title="Kelola Mesin" aria-label="Kelola Mesin {{ $dev->name }}"
                                    class="inline-flex items-center gap-1 rounded-[var(--radius-sm)] bg-[var(--primary)] px-2 py-1 text-xs font-medium whitespace-nowrap text-white transition-colors hover:brightness-90">
                                    <x-icon name="settings" class="size-3" />
                                    <span>Kelola</span>
                                </a>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" class="px-4 py-10 text-center">
                            @if($search)
                                <div class="text-xs text-[var(--text-muted)]">
                                    Tidak ada mesin yang cocok dengan pencarian
                                    <span class="font-mono text-[var(--text)]">"{{ $search }}"</span>.
                                    <a href="{{ route('devices.index') }}" class="font-medium text-[var(--primary-accessible)] hover:underline">Reset pencarian</a>
                                </div>
                            @else
                                <x-empty-state icon="device"
                                    title="Belum ada mesin fingerprint terdaftar"
                                    message="Daftarkan terminal Solution X601 agar absensi biometrik dapat tercatat.">
                                    <x-slot:action>
                                        <button type="button" onclick="document.getElementById('modal-add-device').classList.remove('hidden')"
                                            class="inline-flex items-center gap-1.5 rounded-[var(--radius-sm)] bg-[var(--primary)] px-3 py-1.5 text-xs font-semibold text-white transition-colors hover:brightness-95">
                                            <span>Tambah Mesin</span>
                                        </button>
                                    </x-slot:action>
                                </x-empty-state>
                            @endif
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const container = document.querySelector('[data-device-status-url-template]');
    const urlTemplate = container?.dataset.deviceStatusUrlTemplate;

    if (!urlTemplate) {
        return;
    }

    const formatSeen = (value) => {
        if (!value) {
            return 'Belum pernah online';
        }

        const date = new Date(value);
        const pad = (number) => String(number).padStart(2, '0');

        return `Terakhir online ${pad(date.getDate())}/${pad(date.getMonth() + 1)} ${pad(date.getHours())}:${pad(date.getMinutes())}`;
    };

    const pollDeviceStatus = async (deviceId) => {
        try {
            const response = await fetch(urlTemplate.replace('__ID__', encodeURIComponent(deviceId)), {
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                cache: 'no-store',
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const data = await response.json();
            const cell = document.querySelector(`[data-device-id="${deviceId}"]`);

            if (!cell) {
                return;
            }

            const badge = cell.querySelector('.device-status-badge');
            if (badge) {
                badge.innerHTML = data.html;
            }

            const lastSeen = cell.querySelector('.device-last-seen');
            if (lastSeen) {
                lastSeen.textContent = formatSeen(data.last_seen_at);
            }
        } catch (error) {
            console.error('Gagal mempolling status perangkat', error);
        }
    };

    document.querySelectorAll('[data-device-id]').forEach((cell) => {
        const deviceId = cell.dataset.deviceId;

        if (!deviceId) {
            return;
        }

        // Badge awal sudah dirender server; hindari request tambahan saat load
        // agar navigasi tidak mengantre di belakang polling status.
        setInterval(() => {
            if (! document.hidden) {
                pollDeviceStatus(deviceId);
            }
        }, 30000);
    });
});
</script>

@endsection

@push('modals')
<div id="modal-add-device" class="fixed inset-0 z-50 hidden flex items-center justify-center bg-[#0f1623]/60 backdrop-blur-xs" style="align-items: center; justify-content: center;">
    <div class="w-full max-w-md rounded-[var(--radius-lg)] border border-[var(--border)] bg-[var(--surface)] p-5 shadow-[var(--shadow)]" style="margin: auto;">
        <div class="mb-4 flex items-start justify-between">
            <div>
                <h2 class="text-sm font-bold text-[var(--text)]">Daftarkan Mesin Fingerprint</h2>
                <p class="mt-0.5 text-[11px] text-[var(--text-secondary)]">Konfigurasi alamat IP LAN mesin Solution X601.</p>
            </div>
            <button type="button" onclick="document.getElementById('modal-add-device').classList.add('hidden')"
                aria-label="Tutup" class="text-[var(--text-muted)] hover:text-[var(--text)]">
                <x-icon name="close" class="size-4" />
            </button>
        </div>

        <form action="{{ route('devices.store') }}" method="POST" class="space-y-3 text-xs">
            @csrf

            <div>
                <label for="add-name" class="mb-1 block font-semibold text-[var(--text)]">Nama Mesin</label>
                <input id="add-name" type="text" name="name" required placeholder="Contoh: Solution X601 - Gerbang Depan"
                    class="h-9 w-full rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] px-3 text-xs text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none focus:ring-1 focus:ring-[var(--primary-accessible)]">
                <span class="mt-1 block text-[10px] text-[var(--text-muted)]">Gunakan pola "Tipe - Lokasi" agar lokasi tampil di kolom Lokasi.</span>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label for="add-ip" class="mb-1 block font-semibold text-[var(--text)]">IP Address LAN</label>
                    <input id="add-ip" type="text" name="ip" required placeholder="192.168.1.201"
                        class="h-9 w-full rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] px-3 font-mono text-xs text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none focus:ring-1 focus:ring-[var(--primary-accessible)]">
                </div>
                <div>
                    <label for="add-port" class="mb-1 block font-semibold text-[var(--text)]">Port</label>
                    <input id="add-port" type="number" name="port" required value="4370"
                        class="h-9 w-full rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] px-3 font-mono text-xs text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none focus:ring-1 focus:ring-[var(--primary-accessible)]">
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label for="add-key" class="mb-1 block font-semibold text-[var(--text)]">Comm Key</label>
                    <input id="add-key" type="text" name="comm_key" value="0"
                        class="h-9 w-full rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] px-3 font-mono text-xs text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none focus:ring-1 focus:ring-[var(--primary-accessible)]">
                </div>
                <div>
                    <label for="add-tz" class="mb-1 block font-semibold text-[var(--text)]">Timezone</label>
                    <select id="add-tz" name="timezone" required
                        class="h-9 w-full rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] px-3 text-xs text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none focus:ring-1 focus:ring-[var(--primary-accessible)]">
                        @foreach([
                            'Asia/Jakarta' => 'WIB - Asia/Jakarta (GMT+7)',
                            'Asia/Pontianak' => 'WIB - Asia/Pontianak (GMT+7)',
                            'Asia/Makassar' => 'WITA - Asia/Makassar (GMT+8)',
                            'Asia/Jayapura' => 'WIT - Asia/Jayapura (GMT+9)',
                        ] as $tzValue => $tzLabel)
                            <option value="{{ $tzValue }}" {{ old('timezone', 'Asia/Jakarta') === $tzValue ? 'selected' : '' }}>{{ $tzLabel }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="flex justify-end gap-2 pt-2">
                <button type="button" onclick="document.getElementById('modal-add-device').classList.add('hidden')"
                    class="rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface)] px-3.5 py-1.5 text-xs font-semibold text-[var(--text-secondary)] transition-colors hover:bg-[var(--surface-secondary)]">
                    Batal
                </button>
                <button type="submit"
                    class="rounded-[var(--radius-sm)] bg-[var(--primary)] px-3.5 py-1.5 text-xs font-semibold text-white transition-colors hover:brightness-95">
                    Daftarkan
                </button>
            </div>
        </form>
    </div>
</div>
@endpush
