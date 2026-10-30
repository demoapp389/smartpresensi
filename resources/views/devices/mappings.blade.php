@extends('layouts.app')

@section('title', 'Pemetaan PIN Siswa')
@section('page-pretitle', 'Mesin Fingerprint')
@section('page-title', 'Pemetaan PIN Siswa')
@section('page-subtitle', 'Manajemen pemetaan ID mesin fingerprint ke siswa (ID mesin boleh berbeda dari NIS)')

@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="transition-colors hover:text-[var(--text)]">Beranda</a>
    <span aria-hidden="true">/</span>
    <span>Mesin Fingerprint</span>
    <span aria-hidden="true">/</span>
    <span class="font-medium text-[var(--text)]" aria-current="page">Pemetaan PIN Siswa</span>
@endsection

@section('content')
<div class="space-y-4"
     x-data="{
         mapModal: false,
         reassignModal: false,
         deleteModal: false,
         active: {},
         openMap(prefill = {}) {
             this.active = { device_id: '{{ $selectedDeviceId }}', machine_user_id: '', machine_name: '', ...prefill };
             this.$refs.mapPicker?.clear();
             this.mapModal = true;
         },
         openReassign(mapping) {
             this.active = mapping;
             this.$refs.reassignPicker?.setSelected({
                 id: mapping.student_id,
                 name: mapping.student_name,
                 nis: mapping.student_nis,
                 class: mapping.student_class
             });
             this.reassignModal = true;
         },
         openDelete(mapping) {
             this.active = mapping;
             this.deleteModal = true;
         }
     }">


    {{-- .card-header Gentelella: filter mesin + tabs (kiri) + aksi (kanan) di atas tabel. --}}
    <section class="min-w-0 rounded-[var(--radius-lg)] border border-[var(--border)] bg-[var(--surface)] shadow-[var(--shadow)] overflow-hidden">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--border)] px-4 py-3">
            <div class="flex flex-wrap items-center gap-3">
                <form method="GET" action="{{ route('devices.mappings') }}" class="flex items-center">
                    <input type="hidden" name="tab" value="{{ $tab }}">
                    <select name="device_id"
                        onchange="this.form.submit()"
                        class="h-8.5 rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface-secondary)] px-2.5 text-xs text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none cursor-pointer min-w-[180px]">
                        <option value="">Semua Mesin</option>
                        @foreach($devices as $dev)
                            <option value="{{ $dev->id }}" {{ (string) $selectedDeviceId === (string) $dev->id ? 'selected' : '' }}>{{ $dev->name }}</option>
                        @endforeach
                    </select>
                </form>

                <div class="tabs-pill" role="tablist" aria-label="Status pemetaan">
                    <a role="tab" aria-selected="{{ $tab === 'unmapped' ? 'true' : 'false' }}"
                        href="{{ route('devices.mappings', array_merge(request()->query(), ['tab' => 'unmapped'])) }}"
                        class="tab {{ $tab === 'unmapped' ? 'active' : '' }}">
                        Belum Dipetakan ({{ $summary['unmapped'] }})
                    </a>
                    <a role="tab" aria-selected="{{ $tab === 'mapped' ? 'true' : 'false' }}"
                        href="{{ route('devices.mappings', array_merge(request()->query(), ['tab' => 'mapped'])) }}"
                        class="tab {{ $tab === 'mapped' ? 'active' : '' }}">
                        Sudah Dipetakan ({{ $summary['mapped'] }})
                    </a>
                </div>
            </div>

            <div class="card-options">
                <button type="button" @click="openMap()"
                    class="inline-flex h-8.5 items-center gap-1.5 rounded-[var(--radius-sm)] bg-[var(--primary)] px-3 text-xs font-semibold text-white transition-colors hover:brightness-90">
                    <svg class="size-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    <span>Buat Pemetaan Baru</span>
                </button>
            </div>
        </div>

        <div>
            @if($tab === 'mapped')
                <table class="w-full text-left" data-datatable data-page-length="10" data-length-change="true" data-empty-message="Tidak ada pemetaan yang ditemukan">
                    <thead class="bg-[var(--surface-secondary)]">
                        <tr>
                            <th class="px-4 py-2 text-[11px] font-bold uppercase tracking-wider text-[var(--text-muted)]">ID Mesin</th>
                            <th class="px-4 py-2 text-[11px] font-bold uppercase tracking-wider text-[var(--text-muted)]">Siswa</th>
                            <th class="px-4 py-2 text-[11px] font-bold uppercase tracking-wider text-[var(--text-muted)]">NIS</th>
                            <th class="px-4 py-2 text-[11px] font-bold uppercase tracking-wider text-[var(--text-muted)]">Kelas</th>
                            <th class="px-4 py-2 text-[11px] font-bold uppercase tracking-wider text-[var(--text-muted)]">Tanggal Pemetaan</th>
                            <th data-orderable="false" style="width:96px" class="px-4 py-2 text-[11px] font-bold uppercase tracking-wider text-[var(--text-muted)] text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--border-subtle)] text-[13px]">
                        @foreach($mappings as $mapping)
                        <tr class="hover:bg-[var(--surface-secondary)] transition-colors">
                            <td class="px-4 py-2.5 font-mono text-xs text-[var(--primary)]">{{ $mapping->machine_user_id }}</td>
                            <td class="px-4 py-2.5 font-medium text-[var(--text)]">
                                {{ $mapping->student->name ?? 'N/A' }}
                            </td>
                            <td class="px-4 py-2.5">{{ $mapping->student->nis ?? 'N/A' }}</td>
                            <td class="px-4 py-2.5">{{ $mapping->student->schoolClass->name ?? 'N/A' }}</td>
                            <td class="px-4 py-2.5 text-[var(--text-muted)] whitespace-nowrap">{{ $mapping->created_at->format('d/m/Y') }}</td>
                            <td class="px-4 py-2.5">
                                <div class="flex items-center justify-end gap-1.5">
                                    <button type="button"
                                        @click="openReassign({
                                            id: {{ $mapping->id }},
                                            student_id: {{ $mapping->student_id }},
                                            student_name: {{ Js::from($mapping->student->name ?? '') }},
                                            student_nis: {{ Js::from($mapping->student->nis ?? '') }},
                                            student_class: {{ Js::from($mapping->student->schoolClass?->name ?? 'Tanpa Kelas') }}
                                        })"
                                        title="Ganti Siswa" aria-label="Ganti Siswa"
                                        class="inline-flex items-center justify-center w-6 h-6 rounded-[var(--radius-sm)] text-[var(--text-muted)] hover:text-[var(--text)] hover:bg-[var(--surface-secondary)] transition-colors">
                                        <svg class="size-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                    </button>
                                    <button type="button"
                                        @click="openDelete({ id: {{ $mapping->id }} })"
                                        title="Hapus" aria-label="Hapus"
                                        class="inline-flex items-center justify-center w-6 h-6 rounded-[var(--radius-sm)] text-[var(--text-muted)] hover:text-[var(--danger)] hover:bg-[var(--danger)]/10 transition-colors">
                                        <svg class="size-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <table class="w-full text-left" data-datatable data-page-length="10" data-length-change="true" data-empty-message="Tidak ada machine users yang ditemukan">
                    <thead class="bg-[var(--surface-secondary)]">
                        <tr>
                            <th class="px-4 py-2 text-[11px] font-bold uppercase tracking-wider text-[var(--text-muted)]">ID Mesin</th>
                            <th class="px-4 py-2 text-[11px] font-bold uppercase tracking-wider text-[var(--text-muted)]">Nama Mesin</th>
                            <th class="px-4 py-2 text-[11px] font-bold uppercase tracking-wider text-[var(--text-muted)]">Status Pemetaan</th>
                            <th data-orderable="false" style="width:96px" class="px-4 py-2 text-[11px] font-bold uppercase tracking-wider text-[var(--text-muted)] text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--border-subtle)] text-[13px]">
                        @foreach($machineUsers as $machineUser)
                        <tr class="hover:bg-[var(--surface-secondary)] transition-colors">
                            <td class="px-4 py-2.5 font-mono text-xs text-[var(--primary)]">{{ $machineUser->fingerprint_user_id }}</td>
                            <td class="px-4 py-2.5">{{ $machineUser->machine_name }}</td>
                            <td class="px-4 py-2.5">
                                @if($machineUser->mapping)
                                    <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-[var(--radius-sm)] text-[11px] font-semibold border bg-emerald-50 text-emerald-700 border-emerald-200">
                                        <svg class="size-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                        {{ $machineUser->mapping->student->name }}
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-[var(--radius-sm)] text-[11px] font-semibold border bg-amber-50 text-amber-700 border-amber-200">
                                        <svg class="size-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                        Belum Dipetakan
                                    </span>
                                @endif
                            </td>
                            <td class="px-4 py-2.5">
                                <div class="flex items-center justify-end gap-1.5">
                                    <button type="button"
                                        @click="openMap({ device_id: '{{ $machineUser->device_id }}', machine_user_id: '{{ $machineUser->fingerprint_user_id }}', machine_name: '{{ addslashes($machineUser->machine_name ?? '') }}' })"
                                        title="Map ke Siswa" aria-label="Map ke Siswa"
                                        class="inline-flex items-center justify-center w-6 h-6 rounded-[var(--radius-sm)] text-[var(--text-muted)] hover:text-[var(--text)] hover:bg-[var(--surface-secondary)] transition-colors">
                                        <svg class="size-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0L6 12"/></svg>
                                    </button>
                                    @if($machineUser->mapping)
                                    <button type="button"
                                        @click="openReassign({
                                            id: {{ $machineUser->mapping->id }},
                                            student_id: {{ $machineUser->mapping->student_id }},
                                            student_name: {{ Js::from($machineUser->mapping->student->name ?? '') }},
                                            student_nis: {{ Js::from($machineUser->mapping->student->nis ?? '') }},
                                            student_class: {{ Js::from($machineUser->mapping->student->schoolClass?->name ?? 'Tanpa Kelas') }}
                                        })"
                                        title="Ganti Siswa" aria-label="Ganti Siswa"
                                        class="inline-flex items-center justify-center w-6 h-6 rounded-[var(--radius-sm)] text-[var(--text-muted)] hover:text-[var(--text)] hover:bg-[var(--surface-secondary)] transition-colors">
                                        <svg class="size-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                    </button>
                                    <button type="button"
                                        @click="openDelete({ id: {{ $machineUser->mapping->id }} })"
                                        title="Hapus Pemetaan" aria-label="Hapus Pemetaan"
                                        class="inline-flex items-center justify-center w-6 h-6 rounded-[var(--radius-sm)] text-[var(--text-muted)] hover:text-[var(--danger)] hover:bg-[var(--danger)]/10 transition-colors">
                                        <svg class="size-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                    </button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </section>

    {{-- ───────────── Modal: Buat / Petakan PIN ke Siswa ───────────── --}}
    <div x-cloak x-show="mapModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-[#0f1623]/60 backdrop-blur-xs">
        <div @click.away="mapModal = false" class="w-full max-w-md rounded-[var(--radius-lg)] border border-[var(--border)] bg-[var(--surface)] p-5 shadow-[var(--shadow)]">
            <div class="mb-3 flex items-start justify-between">
                <div>
                    <h2 class="text-sm font-bold text-[var(--text)]">Petakan PIN ke Siswa</h2>
                    <p class="mt-0.5 text-[11px] text-[var(--text-secondary)]">Hubungkan ID Mesin dengan satu siswa. ID yang sama tidak boleh dipetakan dua kali.</p>
                </div>
                <button type="button" @click="mapModal = false" aria-label="Tutup" class="text-[var(--text-muted)] hover:text-[var(--text)]">
                    <x-icon name="close" class="size-4" />
                </button>
            </div>

            <form action="{{ route('devices.mapping.store') }}" method="POST" class="space-y-3 text-xs">
                @csrf
                <input type="hidden" name="return_to" value="{{ url()->full() }}">

                <div>
                    <label for="map-device" class="mb-1 block font-semibold text-[var(--text)]">Mesin</label>
                    <select id="map-device" name="device_id" required x-model="active.device_id"
                        class="h-9 w-full rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] px-3 text-xs text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none">
                        <option value="">-- Pilih Mesin --</option>
                        @foreach($devices as $dev)
                            <option value="{{ $dev->id }}">{{ $dev->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="map-pin" class="mb-1 block font-semibold text-[var(--text)]">ID Mesin (bukan NIS)</label>
                    <input id="map-pin" type="text" name="machine_user_id" required x-model="active.machine_user_id"
                        maxlength="50" placeholder="Contoh: 1001" autocomplete="off"
                        class="h-9 w-full rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] px-3 font-mono text-xs text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none">
                    <span class="mt-1 block text-[10px] text-[var(--text-muted)]" x-show="active.machine_name" x-text="'Dari mesin: ' + active.machine_name"></span>
                    <span class="mt-1 block text-[10px] text-[var(--text-muted)]">ID pendek khusus mesin; harus terdaftar dan belum dipakai siswa lain.</span>
                </div>

                <div>
                    <label for="map-student" class="mb-1 block font-semibold text-[var(--text)]">Siswa</label>
                    <x-student-picker id="map-student" name="student_id" x-ref="mapPicker" />
                </div>

                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" @click="mapModal = false"
                        class="rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface)] px-3.5 py-1.5 text-xs font-semibold text-[var(--text-secondary)] transition-colors hover:bg-[var(--surface-secondary)]">
                        Batal
                    </button>
                    <button type="submit"
                        class="rounded-[var(--radius-sm)] bg-[var(--primary)] px-3.5 py-1.5 text-xs font-semibold text-white transition-colors hover:bg-[var(--primary-dark)]">
                        Simpan Pemetaan
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- ───────────── Modal: Ganti Siswa (reassign) ───────────── --}}
    <div x-cloak x-show="reassignModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-[#0f1623]/60 backdrop-blur-xs">
        <div @click.away="reassignModal = false" class="w-full max-w-md rounded-[var(--radius-lg)] border border-[var(--border)] bg-[var(--surface)] p-5 shadow-[var(--shadow)]">
            <div class="mb-3 flex items-start justify-between">
                <div>
                    <h2 class="text-sm font-bold text-[var(--text)]">Ganti Siswa Pemetaan</h2>
                    <p class="mt-0.5 text-[11px] text-[var(--text-secondary)]">
                        PIN tetap sama; hanya profil siswa yang terhubung yang diganti.
                        <span class="block mt-0.5">Saat ini: <strong class="text-[var(--text)]" x-text="active.student_name || '-'"></strong></span>
                    </p>
                </div>
                <button type="button" @click="reassignModal = false" aria-label="Tutup" class="text-[var(--text-muted)] hover:text-[var(--text)]">
                    <x-icon name="close" class="size-4" />
                </button>
            </div>

            <form :action="'{{ url('devices/mapping') }}' + '/' + active.id" method="POST" class="space-y-3 text-xs">
                @csrf
                @method('PUT')
                <input type="hidden" name="return_to" value="{{ url()->full() }}">

                <div>
                    <label for="reassign-student" class="mb-1 block font-semibold text-[var(--text)]">Siswa Baru</label>
                    <x-student-picker id="reassign-student" name="student_id" x-ref="reassignPicker" />
                </div>

                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" @click="reassignModal = false"
                        class="rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface)] px-3.5 py-1.5 text-xs font-semibold text-[var(--text-secondary)] transition-colors hover:bg-[var(--surface-secondary)]">
                        Batal
                    </button>
                    <button type="submit"
                        class="rounded-[var(--radius-sm)] bg-[var(--primary)] px-3.5 py-1.5 text-xs font-semibold text-white transition-colors hover:bg-[var(--primary-dark)]">
                        Simpan Perubahan
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- ───────────── Modal: Hapus pemetaan ───────────── --}}
    <div x-cloak x-show="deleteModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-[#0f1623]/60 backdrop-blur-xs">
        <div @click.away="deleteModal = false" class="w-full max-w-sm rounded-[var(--radius-lg)] border border-[var(--border)] bg-[var(--surface)] p-5 shadow-[var(--shadow)]">
            <h2 class="text-sm font-bold text-[var(--text)]">Lepas Pemetaan</h2>
            <p class="mt-1 text-[11px] text-[var(--text-secondary)]">
                Pemetaan PIN ini dilepas dari server. Data pengguna pada mesin (sidik jari &amp; wajah) tidak diubah, sehingga dapat dipetakan ulang tanpa perekaman ulang.
            </p>

            <form :action="'{{ url('devices/mapping') }}' + '/' + active.id" method="POST" class="mt-3 flex justify-end gap-2 text-xs">
                @csrf
                @method('DELETE')
                <input type="hidden" name="return_to" value="{{ url()->full() }}">
                <button type="button" @click="deleteModal = false"
                    class="rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface)] px-3.5 py-1.5 text-xs font-semibold text-[var(--text-secondary)] transition-colors hover:bg-[var(--surface-secondary)]">
                    Batal
                </button>
                <button type="submit"
                    class="rounded-[var(--radius-sm)] bg-[var(--danger)] px-3.5 py-1.5 text-xs font-semibold text-white transition-colors hover:brightness-95">
                    Lepas Pemetaan
                </button>
            </form>
        </div>
    </div>
</div>
@endsection
