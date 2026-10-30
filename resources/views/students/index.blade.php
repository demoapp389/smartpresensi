@extends('layouts.app')

@section('title', 'Data Siswa & Wali')
@section('page-pretitle', 'Master Data')
@section('page-title', 'Master Data Siswa & Wali Murid')
@section('page-subtitle', 'Kelola informasi siswa, nomor WhatsApp orang tua (E.164), dan mapping PIN fingerprint')

@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="transition-colors hover:text-[var(--text)]">Beranda</a>
    <span aria-hidden="true">/</span>
    <span>Master Data</span>
    <span aria-hidden="true">/</span>
    <span class="font-medium text-[var(--text)]" aria-current="page">Data Siswa &amp; Wali</span>
@endsection



@section('content')
<div class="space-y-4"
     x-data="{
         addModal: false,
         editModal: false,
         importModal: false,
         deleteModal: false,
         bulkDeleteModal: false,
         importResult: null,
         studentToDelete: {},
         activeStudent: {},
         selected: [],
         get importResultEntries() {
             const labels = {
                 created: 'Siswa baru',
                 updated: 'Diperbarui',
                 mapped: 'Pemetaan ID Mesin',
                 skipped: 'Dilewati'
             };
             if (!this.importResult) return [];
             return Object.entries(this.importResult)
                 .filter(([key, value]) => key !== 'errors' && typeof value === 'number')
                 .map(([key, value]) => ({ label: labels[key] || key, value }));
         }
     }"
     @open-add-student.window="addModal = true"
     @open-import-student.window="importResult = null; importModal = true"
     @progress:completed.window="if ($event.detail.type === 'students.import') { importResult = $event.detail.results ?? null }">


    <!-- Status Tabs (Gentelella tabs-pill dalam Card bg seperti Jadwal) -->
    <div class="rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] p-2.5 shadow-[var(--shadow)] flex flex-wrap items-center justify-between gap-3 text-xs">
        <div class="overflow-x-auto w-full sm:w-auto">
            <div class="tabs-pill" role="tablist" aria-label="Status data siswa">
                <a role="tab" aria-selected="{{ $status !== 'trashed' ? 'true' : 'false' }}"
                    href="{{ route('students.index') }}"
                    class="tab inline-flex items-center gap-2 {{ $status !== 'trashed' ? 'active' : '' }}">
                    <svg class="size-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                    <span>Siswa Aktif</span>
                    <span class="px-1.5 py-0.2 rounded-full text-[10px] {{ $status !== 'trashed' ? 'bg-[var(--primary)] text-white' : 'bg-[var(--surface-secondary)] text-[var(--text-secondary)]' }} font-mono font-bold">{{ $activeCount }}</span>
                </a>
                <a role="tab" aria-selected="{{ $status === 'trashed' ? 'true' : 'false' }}"
                    href="{{ route('students.index', ['status' => 'trashed']) }}"
                    class="tab inline-flex items-center gap-2 {{ $status === 'trashed' ? 'active' : '' }}">
                    <svg class="size-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                    <span>Sampah / Terhapus</span>
                    <span class="px-1.5 py-0.2 rounded-full text-[10px] {{ $status === 'trashed' ? 'bg-rose-500 text-white font-bold' : 'bg-[var(--surface-secondary)] text-[var(--text-secondary)]' }} font-mono">{{ $trashedCount }}</span>
                </a>
            </div>
        </div>
    </div>

    {{-- Hasil import terakhir ditampilkan di dalam modal import. --}}

    @php
        $pendingWaCount = \App\Models\Guardian::where('active', true)
            ->where(function ($query) {
                $query->where('wa_status', 'pending')->orWhereNull('wa_status');
            })
            ->count();
    @endphp

    <!-- Table Card -->
    <section id="table-container" class="min-w-0 rounded-[var(--radius-lg)] border border-[var(--border)] bg-[var(--surface)] shadow-[var(--shadow)] overflow-hidden">
        {{-- Card header (.card-header Gentelella): judul + aksi --}}
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--border)] px-4 py-3">
            <div>
                <div class="card-title text-sm font-semibold text-[var(--text)]">
                    {{ $status === 'trashed' ? 'Daftar Siswa di Sampah (Soft-Deleted)' : 'Daftar Siswa Terdaftar' }}
                </div>
                <div class="card-subtitle mt-0.5 text-xs text-[var(--text-muted)]">{{ $students->count() }} siswa terdaftar</div>
            </div>

            <div class="card-options flex flex-wrap items-center gap-2">
                @if($status !== 'trashed')
                    <a href="{{ route('guardians.validation') }}"
                        class="inline-flex h-8.5 items-center justify-center gap-1.5 px-3 rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface)] text-xs font-semibold text-[var(--text-secondary)] hover:bg-[var(--surface-secondary)] hover:text-[var(--text)] transition-colors shadow-2xs whitespace-nowrap"
                        title="Buka halaman Validasi Nomor Wali untuk memvalidasi nomor wali berstatus pending">
                        <x-icon name="phone-check" class="size-3.5 text-sky-600 dark:text-sky-400" />
                        <span>Validasi WA</span>
                        @if($pendingWaCount > 0)
                            <span class="ml-0.5 rounded bg-amber-500/20 px-1.5 py-0.2 text-[10px] font-bold text-amber-700 dark:text-amber-300">
                                {{ $pendingWaCount }}
                            </span>
                        @endif
                    </a>

                    <button type="button" @click="$dispatch('open-import-student')" onclick="window.dispatchEvent(new CustomEvent('open-import-student'))"
                        class="inline-flex h-8.5 items-center justify-center gap-1.5 px-3 rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface)] text-xs font-semibold text-[var(--text-secondary)] hover:bg-[var(--surface-secondary)] hover:text-[var(--text)] transition-colors shadow-2xs cursor-pointer whitespace-nowrap">
                        <svg class="size-3.5 text-emerald-600 dark:text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
                        </svg>
                        <span>Import Siswa (.xlsx)</span>
                    </button>

                    <button type="button" @click="$dispatch('open-add-student')" onclick="window.dispatchEvent(new CustomEvent('open-add-student'))"
                        class="inline-flex h-8.5 items-center justify-center gap-1.5 px-3 rounded-[var(--radius-sm)] bg-[var(--primary)] text-xs font-semibold text-white whitespace-nowrap hover:brightness-95 transition-colors shadow-2xs cursor-pointer">
                        <svg class="size-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                        <span>Tambah Siswa</span>
                    </button>
                @endif
            </div>
        </div>

        {{-- Filter toolbar dipindah ke dalam card agar tidak pernah tampil di luar card
             sebelum DataTables siap; tables.js membuka `hidden` setelah tabel diinisialisasi. --}}
        <form id="student-class-filter" hidden method="GET" action="{{ route('students.index') }}" class="flex items-center gap-1.5 text-xs">
            @if($status === 'trashed')
                <input type="hidden" name="status" value="trashed">
            @endif
            <select name="class_id" onchange="this.form.submit()" class="h-8.5 rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface-secondary)] px-2.5 text-xs text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none cursor-pointer">
                <option value="">Semua Kelas</option>
                @foreach($classes as $c)
                    <option value="{{ $c->id }}" {{ $classId == $c->id ? 'selected' : '' }}>{{ $c->name }}</option>
                @endforeach
            </select>
            @if($classId)
                <a href="{{ route('students.index', $status === 'trashed' ? ['status' => 'trashed'] : []) }}"
                    class="inline-flex h-8.5 items-center justify-center rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface)] px-2.5 text-xs font-medium text-[var(--text-secondary)] hover:bg-[var(--surface-secondary)] transition-colors">
                    Reset
                </a>
            @endif
        </form>

        <!-- Bulk Action Bar -->
        <div x-cloak x-show="selected.length > 0" x-transition
             class="flex flex-wrap items-center justify-between gap-3 border-b border-indigo-200 bg-indigo-50/80 dark:bg-indigo-950/40 dark:border-indigo-800/60 px-4 py-2.5">
            <div class="flex items-center gap-3">
                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold bg-indigo-100 text-indigo-800 dark:bg-indigo-900/60 dark:text-indigo-200 border border-indigo-300 dark:border-indigo-700">
                    <span x-text="selected.length"></span> siswa dipilih
                </span>
                <button type="button" @click="selected = []"
                    class="inline-flex items-center px-2.5 py-1 rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface)] hover:bg-[var(--surface-secondary)] text-xs font-medium text-[var(--text-secondary)] transition-colors">
                    Batal Pilih
                </button>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                @if($status === 'trashed')
                    <!-- Bulk Restore -->
                    <form action="{{ route('students.bulk-restore') }}" method="POST" class="inline"
                          @submit.prevent="askConfirm({ title: 'Pulihkan Siswa', message: 'Pulihkan ' + selected.length + ' data siswa dari Sampah?', confirmLabel: 'Pulihkan', variant: 'primary' }).then(ok => ok && $el.submit())">
                        @csrf
                        <template x-for="id in selected" :key="id">
                            <input type="hidden" name="ids[]" :value="id">
                        </template>
                        <button type="submit"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-[var(--radius-sm)] bg-emerald-600 hover:bg-emerald-700 text-xs font-semibold text-white transition-colors shadow-xs">
                            <svg class="size-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                            <span>Pulihkan Terpilih</span>
                        </button>
                    </form>

                    <div class="hidden sm:block h-5 w-px bg-indigo-200 dark:bg-indigo-800"></div>

                    <!-- Bulk Force Delete -->
                    <form action="{{ route('students.bulk-force-delete') }}" method="POST" class="inline"
                          @submit.prevent="askConfirm({ title: 'Hapus Permanen', message: 'PERINGATAN: ' + selected.length + ' data siswa akan DIHAPUS PERMANEN beserta seluruh relasi presensi dan walinya. Lanjutkan?', confirmLabel: 'Hapus Permanen', variant: 'danger' }).then(ok => ok && $el.submit())">
                        @csrf
                        @method('DELETE')
                        <template x-for="id in selected" :key="id">
                            <input type="hidden" name="ids[]" :value="id">
                        </template>
                        <button type="submit"
                            class="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-[var(--radius-sm)] bg-rose-600 hover:bg-rose-700 text-xs font-semibold text-white transition-colors shadow-xs">
                            <svg class="size-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                            <span>Hapus Permanen</span>
                        </button>
                    </form>
                @else
                    <!-- Pemetaan Kelas Massal -->
                    <form action="{{ route('students.bulk-assign-class') }}" method="POST" class="flex flex-wrap items-center gap-2"
                          @submit.prevent="! $el.querySelector('select[name=class_id]').value ? window.toast('Silakan pilih kelas tujuan terlebih dahulu.', 'warning') : askConfirm({ title: 'Petakan ke Kelas', message: 'Petakan ' + selected.length + ' siswa ke kelas yang dipilih?', confirmLabel: 'Terapkan', variant: 'primary' }).then(ok => ok && $el.submit())">
                        @csrf
                        <template x-for="id in selected" :key="id">
                            <input type="hidden" name="ids[]" :value="id">
                        </template>
                        <label for="bulk_target_class" class="text-xs font-medium text-[var(--text-secondary)] flex items-center gap-1">
                            <svg class="size-3.5 text-indigo-600 dark:text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
                            <span>Pindah/Petakan ke Kelas:</span>
                        </label>
                        <select id="bulk_target_class" name="class_id" required class="h-8 rounded-[var(--radius-sm)] border border-indigo-200 dark:border-indigo-800 bg-[var(--surface)] px-2.5 text-xs text-[var(--text)] focus:border-indigo-500 focus:outline-none cursor-pointer">
                            <option value="">-- Pilih Kelas Tujuan --</option>
                            @foreach($classes as $c)
                                <option value="{{ $c->id }}">{{ $c->name }} ({{ $c->academic_year }})</option>
                            @endforeach
                        </select>
                        <button type="submit"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-[var(--radius-sm)] bg-indigo-600 hover:bg-indigo-700 text-xs font-semibold text-white transition-colors shadow-xs">
                            <svg class="size-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/></svg>
                            <span>Terapkan Kelas</span>
                        </button>
                    </form>

                    <div class="hidden sm:block h-5 w-px bg-indigo-200 dark:bg-indigo-800"></div>

                    <!-- Pindahkan ke Sampah (Soft Delete dengan Konfirmasi Modal) -->
                    <button type="button" @click="bulkDeleteModal = true;"
                        class="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-[var(--radius-sm)] bg-rose-600 hover:bg-rose-700 text-xs font-semibold text-white transition-colors shadow-xs"
                        title="Pindahkan siswa terpilih ke sampah">
                        <svg class="size-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                        <span>Hapus Terpilih</span>
                    </button>
                @endif
            </div>
        </div>

        <div>
            <table class="w-full text-left" data-datatable data-page-length="10" data-length-change="true" data-toolbar-filter="#student-class-filter" data-empty-message="Belum ada data siswa yang terdaftar.">
                <thead class="bg-[var(--surface-secondary)]">
                    <tr>
                        <th style="width:40px" class="px-4 py-2" data-orderable="false">
                            <input type="checkbox"
                                :checked="selected.length > 0 && selected.length === $root.querySelectorAll('tbody input[type=checkbox]').length"
                                @change="selected = $event.target.checked ? Array.from($root.querySelectorAll('tbody input[type=checkbox]')).map(cb => cb.value) : []"
                                class="size-3.5 rounded border-[var(--border)] text-[var(--primary-accessible)] focus:ring-[var(--primary-accessible)] cursor-pointer"
                                title="Pilih semua siswa di halaman ini">
                        </th>
                        <th class="px-4 py-2 text-[11px] font-bold tracking-wider text-[var(--text-muted)] uppercase">Nama Siswa</th>
                        <th class="px-4 py-2 text-[11px] font-bold tracking-wider text-[var(--text-muted)] uppercase">Kelas</th>
                        <th class="px-4 py-2 text-[11px] font-bold tracking-wider text-[var(--text-muted)] uppercase">ID Mesin</th>
                        <th class="px-4 py-2 text-[11px] font-bold tracking-wider text-[var(--text-muted)] uppercase">Wali Murid</th>
                        <th class="px-4 py-2 text-[11px] font-bold tracking-wider text-[var(--text-muted)] uppercase">WhatsApp Wali</th>
                        <th class="px-4 py-2 text-[11px] font-bold tracking-wider text-[var(--text-muted)] uppercase">Status Validasi</th>
                        <th class="px-4 py-2 text-[11px] font-bold tracking-wider text-[var(--text-muted)] uppercase">Consent WA</th>
                        <th style="width:140px" class="px-4 py-2 text-right text-[11px] font-bold tracking-wider text-[var(--text-muted)] uppercase" data-orderable="false">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--border-subtle)] text-[13px]">
                    @foreach($students as $s)
                    @php
                        $guardian = $s->guardians->first();
                        $deviceUser = $s->deviceUsers->first();
                    @endphp
                    <tr class="hover:bg-[var(--surface-secondary)]/50 transition-colors" :class="{ 'bg-indigo-50/40 dark:bg-indigo-950/20': selected.includes('{{ $s->id }}') }">
                        <!-- Checkbox -->
                        <td class="px-4 py-2.5">
                            <input type="checkbox" x-model="selected" value="{{ $s->id }}"
                                class="size-3.5 rounded border-[var(--border)] text-[var(--primary-accessible)] focus:ring-[var(--primary-accessible)] cursor-pointer">
                        </td>
                        <!-- Siswa -->
                        <td class="px-4 py-2.5">
                            <div class="font-semibold text-[var(--text)]">{{ $s->name }}</div>
                            <div class="text-[11px] text-[var(--text-muted)] font-mono">NIS: {{ $s->nis ?? '-' }} &bull; {{ $s->gender == 'L' ? 'Laki-laki' : 'Perempuan' }}</div>
                        </td>

                        <!-- Kelas -->
                        <td class="px-4 py-2.5 text-xs font-medium">
                            @if($s->schoolClass)
                                <span class="text-[var(--text-secondary)] font-semibold">{{ $s->schoolClass->name }}</span>
                            @else
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[11px] font-medium bg-amber-50 text-amber-700 border border-amber-200 dark:bg-amber-950/40 dark:text-amber-300 dark:border-amber-800/60" title="Belum dipetakan ke kelas manapun">
                                    <svg class="size-3 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                                    <span>Belum Ada Kelas</span>
                                </span>
                            @endif
                        </td>

                        <!-- ID Mesin Fingerprint -->
                        <td class="px-4 py-2.5 font-mono text-xs">
                            @if($deviceUser)
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-bold bg-[var(--primary-soft)] text-[var(--primary-accessible)] border border-[var(--primary)]/20">
                                     {{ $deviceUser->machine_user_id }}
                                </span>
                            @else
                                <span class="text-[var(--text-muted)] italic text-[11px]">Belum dipetakan</span>
                            @endif
                        </td>

                        <!-- Wali Murid -->
                        <td class="px-4 py-2.5 text-xs">
                            @if($guardian)
                                <div class="font-medium text-[var(--text)]">{{ $guardian->name }}</div>
                                <div class="text-[11px] text-[var(--text-muted)]">{{ $guardian->relationship }}</div>
                            @else
                                <span class="text-[var(--text-muted)] italic">-</span>
                            @endif
                        </td>

                        <!-- No WA -->
                        <td class="px-4 py-2.5 font-mono text-xs text-[var(--text)]">
                            @if($guardian && filled($guardian->wa_number))
                                +{{ $guardian->wa_number }}
                            @else
                                <span class="text-[var(--text-muted)] font-sans italic">-</span>
                            @endif
                        </td>

                        <!-- Status Validasi WA (Fonnte Optimization) -->
                        <td class="px-4 py-2.5">
                            @if($guardian)
                                @if($guardian->wa_status === 'valid')
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200" title="Valid: {{ $guardian->wa_validated_at?->translatedFormat('d/m/Y H:i') }}">
                                        <span class="size-1.5 rounded-full bg-emerald-500"></span> Valid (WA Aktif)
                                    </span>
                                @elseif($guardian->wa_status === 'invalid')
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-bold bg-rose-50 text-rose-700 border border-rose-200" title="{{ $guardian->wa_validation_error ?? 'Nomor tidak terdaftar di WhatsApp' }}">
                                        <span class="size-1.5 rounded-full bg-rose-500"></span> Invalid / Tidak Ada
                                    </span>
                                @elseif($guardian->wa_status === 'unknown')
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-bold bg-slate-100 text-slate-700 border border-slate-300" title="{{ $guardian->wa_validation_error ?? 'Gagal cek provider' }}">
                                        <span class="size-1.5 rounded-full bg-slate-400"></span> Unknown
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-bold bg-amber-50 text-amber-700 border border-amber-200">
                                        <span class="size-1.5 rounded-full bg-amber-500 animate-pulse"></span> Pending
                                    </span>
                                @endif
                            @else
                                <span class="text-[var(--text-muted)] italic">-</span>
                            @endif
                        </td>

                        <!-- Consent WA -->
                        <td class="px-4 py-2.5">
                            @if($guardian && $guardian->consent_at)
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                    <span class="size-1.5 rounded-full bg-[var(--success)] mr-1"></span> Disetujui
                                </span>
                            @else
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold bg-slate-100 text-slate-600 border border-slate-200">
                                    Belum Opt-in
                                </span>
                            @endif
                        </td>

                        <!-- Aksi -->
                        <td class="px-4 py-2.5 text-right space-x-1 whitespace-nowrap">
                            @if($status === 'trashed')
                                <form action="{{ route('students.restore', $s->id) }}" method="POST" class="inline"
                                      data-confirm="Pulihkan siswa {{ addslashes($s->name) }} dari Sampah?"
                                      data-confirm-title="Pulihkan Siswa"
                                      data-confirm-label="Pulihkan"
                                      data-confirm-variant="primary">
                                    @csrf
                                    <button type="submit" class="inline-flex items-center gap-1 px-2.5 py-1 rounded-[var(--radius-sm)] border border-emerald-200 bg-emerald-50 hover:bg-emerald-100 text-xs font-semibold text-emerald-700 transition-colors shadow-2xs">
                                        <svg class="size-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                                        <span>Pulihkan</span>
                                    </button>
                                </form>
                                <form action="{{ route('students.force-delete', $s->id) }}" method="POST" class="inline"
                                      data-confirm="PERINGATAN: Data siswa {{ addslashes($s->name) }} akan DIHAPUS PERMANEN beserta data presensinya. Lanjutkan?"
                                      data-confirm-title="Hapus Permanen"
                                      data-confirm-label="Hapus Permanen"
                                      data-confirm-variant="danger">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="inline-flex items-center gap-1 px-2.5 py-1 rounded-[var(--radius-sm)] border border-rose-200 bg-rose-50 hover:bg-rose-100 text-xs font-semibold text-rose-700 transition-colors shadow-2xs">
                                        <svg class="size-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                        <span>Hapus Permanen</span>
                                    </button>
                                </form>
                            @else
                                <button type="button"
                                    @click="activeStudent = {
                                        id: '{{ $s->id }}',
                                        name: '{{ addslashes($s->name) }}',
                                        nis: '{{ $s->nis }}',
                                        nisn: '{{ $s->nisn }}',
                                        gender: '{{ $s->gender }}',
                                        class_id: '{{ $s->class_id }}',
                                        guardian_name: '{{ addslashes($guardian?->name ?? '') }}',
                                        relationship: '{{ $guardian?->relationship ?? 'Wali' }}',
                                        wa_number: '{{ $guardian?->wa_number ?? '' }}',
                                        consent_given: {{ $guardian && $guardian->consent_at ? 'true' : 'false' }}
                                    }; editModal = true;"
                                    class="inline-flex items-center px-2.5 py-1 rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface)] hover:bg-[var(--surface-secondary)] text-xs font-semibold text-[var(--text)] transition-colors shadow-2xs cursor-pointer">
                                    Edit
                                </button>
                                <button type="button"
                                    @click="studentToDelete = { id: '{{ $s->id }}', name: '{{ addslashes($s->name) }}' }; deleteModal = true;"
                                    class="inline-flex items-center px-2.5 py-1 rounded-[var(--radius-sm)] border border-rose-200 dark:border-rose-900/60 bg-rose-50 dark:bg-rose-950/40 hover:bg-rose-100 dark:hover:bg-rose-900/50 text-xs font-semibold text-rose-700 dark:text-rose-300 transition-colors shadow-2xs cursor-pointer"
                                    title="Pindahkan ke Sampah">
                                    Hapus
                                </button>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <!-- Modal Tambah Siswa -->
    <div x-cloak x-show="addModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-[#0f1623]/60 backdrop-blur-xs overflow-y-auto">
        <div @click.away="addModal = false" class="bg-[var(--surface)] rounded-[var(--radius-lg)] border border-[var(--border)] shadow-xl w-full max-w-lg p-5 my-auto max-h-[90vh] overflow-y-auto">
            <h3 class="text-sm font-bold text-[var(--text)] mb-1">Tambah Data Siswa &amp; Wali</h3>
            <p class="text-[11px] text-[var(--text-secondary)] mb-4">Lengkapi profil siswa dan nomor WA wali</p>

            <form action="{{ route('students.store') }}" method="POST" class="space-y-3 text-xs">
                @csrf
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block font-semibold text-[var(--text)] mb-1">NIS (Nomor Induk Siswa)</label>
                        <input type="text" name="nis" placeholder="20260101"
                            class="h-9 w-full rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] px-3 text-xs text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none focus:ring-1 focus:ring-[var(--primary-accessible)]">
                    </div>
                    <div>
                        <label class="block font-semibold text-[var(--text)] mb-1">NISN (Opsional)</label>
                        <input type="text" name="nisn" placeholder="0071234567"
                            class="h-9 w-full rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] px-3 text-xs text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none focus:ring-1 focus:ring-[var(--primary-accessible)]">
                    </div>
                </div>

                <div>
                    <label class="block font-semibold text-[var(--text)] mb-1">Nama Lengkap Siswa</label>
                    <input type="text" name="name" required placeholder="Nama lengkap siswa"
                        class="h-9 w-full rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] px-3 text-xs text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none focus:ring-1 focus:ring-[var(--primary-accessible)]">
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block font-semibold text-[var(--text)] mb-1">Jenis Kelamin</label>
                        <select name="gender" required class="h-9 w-full rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] px-3 text-xs text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none focus:ring-1 focus:ring-[var(--primary-accessible)]">
                            <option value="L">Laki-laki</option>
                            <option value="P">Perempuan</option>
                        </select>
                    </div>
                    <div>
                        <label class="block font-semibold text-[var(--text)] mb-1">Kelas</label>
                        <select name="class_id" required class="h-9 w-full rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] px-3 text-xs text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none focus:ring-1 focus:ring-[var(--primary-accessible)]">
                            @foreach($classes as $c)
                                <option value="{{ $c->id }}">{{ $c->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="pt-2 border-t border-[var(--border-subtle)]">
                    <span class="font-bold text-[var(--text)] text-xs">Data Wali Murid &amp; Notifikasi WhatsApp</span>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block font-semibold text-[var(--text)] mb-1">Nama Wali</label>
                        <input type="text" name="guardian_name" required placeholder="Nama orang tua/wali"
                            class="h-9 w-full rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] px-3 text-xs text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none focus:ring-1 focus:ring-[var(--primary-accessible)]">
                    </div>
                    <div>
                        <label class="block font-semibold text-[var(--text)] mb-1">Hubungan</label>
                        <select name="relationship" class="h-9 w-full rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] px-3 text-xs text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none focus:ring-1 focus:ring-[var(--primary-accessible)]">
                            <option value="Ayah">Ayah</option>
                            <option value="Ibu">Ibu</option>
                            <option value="Wali">Wali</option>
                        </select>
                    </div>
                </div>

                <div>
                    <label class="block font-semibold text-[var(--text)] mb-1">No. WhatsApp Wali (Format E.164 / 628...)</label>
                    <input type="text" name="wa_number" placeholder="08123456789 atau 628123456789 (boleh dikosongkan)"
                        class="h-9 w-full rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] px-3 text-xs font-mono text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none focus:ring-1 focus:ring-[var(--primary-accessible)]">
                </div>

                <div class="flex items-center gap-2 pt-1">
                    <input type="checkbox" id="consent_add" name="consent_given" value="1" class="size-4 rounded border-[var(--border)] text-[var(--primary-accessible)] focus:ring-[var(--primary-accessible)]">
                    <label for="consent_add" class="text-[var(--text-secondary)] text-[11px]">Wali telah menyetujui penerimaan notifikasi presensi via WhatsApp</label>
                </div>

                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" @click="addModal = false" class="px-3.5 py-1.5 rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface)] hover:bg-[var(--surface-secondary)] text-xs font-semibold text-[var(--text-secondary)] transition-colors">
                        Batal
                    </button>
                    <button type="submit" class="px-3.5 py-1.5 rounded-[var(--radius-sm)] bg-[var(--primary)] text-xs font-semibold text-white hover:brightness-95 transition-colors shadow-2xs cursor-pointer">
                        Simpan Siswa
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Edit Siswa -->
    <div x-cloak x-show="editModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-[#0f1623]/60 backdrop-blur-xs overflow-y-auto">
        <div @click.away="editModal = false" class="bg-[var(--surface)] rounded-[var(--radius-lg)] border border-[var(--border)] shadow-xl w-full max-w-lg p-5 my-auto max-h-[90vh] overflow-y-auto">
            <h3 class="text-sm font-bold text-[var(--text)] mb-1">Edit Data Siswa</h3>
            <p class="text-[11px] text-[var(--text-secondary)] mb-4">Perbarui informasi siswa dan nomor WA wali</p>

            <form :action="'{{ url('students') }}/' + activeStudent.id" method="POST" class="space-y-3 text-xs">
                @csrf
                @method('PUT')
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block font-semibold text-[var(--text)] mb-1">NIS</label>
                        <input type="text" name="nis" x-model="activeStudent.nis"
                            class="h-9 w-full rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] px-3 text-xs text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none focus:ring-1 focus:ring-[var(--primary-accessible)]">
                    </div>
                    <div>
                        <label class="block font-semibold text-[var(--text)] mb-1">NISN</label>
                        <input type="text" name="nisn" x-model="activeStudent.nisn"
                            class="h-9 w-full rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] px-3 text-xs text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none focus:ring-1 focus:ring-[var(--primary-accessible)]">
                    </div>
                </div>

                <div>
                    <label class="block font-semibold text-[var(--text)] mb-1">Nama Lengkap Siswa</label>
                    <input type="text" name="name" x-model="activeStudent.name" required
                        class="h-9 w-full rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] px-3 text-xs text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none focus:ring-1 focus:ring-[var(--primary-accessible)]">
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block font-semibold text-[var(--text)] mb-1">Jenis Kelamin</label>
                        <select name="gender" x-model="activeStudent.gender" required class="h-9 w-full rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] px-3 text-xs text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none focus:ring-1 focus:ring-[var(--primary-accessible)]">
                            <option value="L">Laki-laki</option>
                            <option value="P">Perempuan</option>
                        </select>
                    </div>
                    <div>
                        <label class="block font-semibold text-[var(--text)] mb-1">Kelas</label>
                        <select name="class_id" x-model="activeStudent.class_id" required class="h-9 w-full rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] px-3 text-xs text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none focus:ring-1 focus:ring-[var(--primary-accessible)]">
                            @foreach($classes as $c)
                                <option value="{{ $c->id }}">{{ $c->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="pt-2 border-t border-[var(--border-subtle)]">
                    <span class="font-bold text-[var(--text)] text-xs">Data Wali Murid</span>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block font-semibold text-[var(--text)] mb-1">Nama Wali</label>
                        <input type="text" name="guardian_name" x-model="activeStudent.guardian_name" required
                            class="h-9 w-full rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] px-3 text-xs text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none focus:ring-1 focus:ring-[var(--primary-accessible)]">
                    </div>
                    <div>
                        <label class="block font-semibold text-[var(--text)] mb-1">Hubungan</label>
                        <select name="relationship" x-model="activeStudent.relationship" class="h-9 w-full rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] px-3 text-xs text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none focus:ring-1 focus:ring-[var(--primary-accessible)]">
                            <option value="Ayah">Ayah</option>
                            <option value="Ibu">Ibu</option>
                            <option value="Wali">Wali</option>
                        </select>
                    </div>
                </div>

                <div>
                    <label class="block font-semibold text-[var(--text)] mb-1">No. WhatsApp Wali</label>
                    <input type="text" name="wa_number" x-model="activeStudent.wa_number"
                        placeholder="08123456789 (boleh dikosongkan)"
                        class="h-9 w-full rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] px-3 text-xs font-mono text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none focus:ring-1 focus:ring-[var(--primary-accessible)]">
                </div>

                <div class="flex items-center gap-2 pt-1">
                    <input type="checkbox" id="consent_edit" name="consent_given" value="1" x-model="activeStudent.consent_given" class="size-4 rounded border-[var(--border)] text-[var(--primary-accessible)] focus:ring-[var(--primary-accessible)]">
                    <label for="consent_edit" class="text-[var(--text-secondary)] text-[11px]">Wali telah menyetujui penerimaan notifikasi presensi via WhatsApp</label>
                </div>

                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" @click="editModal = false" class="px-3.5 py-1.5 rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface)] hover:bg-[var(--surface-secondary)] text-xs font-semibold text-[var(--text-secondary)] transition-colors">
                        Batal
                    </button>
                    <button type="submit" class="px-3.5 py-1.5 rounded-[var(--radius-sm)] bg-[var(--primary)] text-xs font-semibold text-white hover:brightness-95 transition-colors shadow-2xs cursor-pointer">
                        Perbarui Siswa
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Import Siswa (.xlsx) -->
    <div x-cloak x-show="importModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-[#0f1623]/60 backdrop-blur-xs overflow-y-auto">
        <div @click.away="importModal = false" class="bg-[var(--surface)] rounded-[var(--radius-lg)] border border-[var(--border)] shadow-xl w-full max-w-lg p-5 my-auto max-h-[90vh] overflow-y-auto">
            <div class="flex items-start justify-between gap-3 mb-2">
                <div>
                    <h3 class="text-sm font-bold text-[var(--text)] flex items-center gap-1.5">
                        <svg class="size-4 text-emerald-600 dark:text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                        </svg>
                        <span>Import Data Siswa (.xlsx)</span>
                    </h3>
                    <p class="text-[11px] text-[var(--text-secondary)] mt-0.5">Unggah file spreadsheet Excel (.xlsx) untuk menambah atau memperbarui data siswa secara massal</p>
                </div>
                <button type="button" @click="importModal = false" class="text-[var(--text-muted)] hover:text-[var(--text)]">
                    <svg class="size-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>

            <!-- Petunjuk & Unduh Template -->
            <div class="my-3 rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface-secondary)] p-3 text-xs space-y-2">
                <div class="flex items-center justify-between gap-2">
                    <div>
                        <span class="font-semibold text-[var(--text)]">Format File Spreadsheet:</span>
                        <p class="text-[11px] text-[var(--text-muted)] mt-0.5">Gunakan format template resmi agar kolom sesuai.</p>
                    </div>
                    <a href="{{ route('students.template') }}" class="inline-flex shrink-0 items-center gap-1.5 px-2.5 py-1.5 rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface)] hover:bg-[var(--surface-secondary)] text-[var(--text)] font-semibold text-[11px] transition-colors shadow-2xs">
                        <svg class="size-3.5 text-[var(--primary-accessible)]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                        </svg>
                        <span>Unduh Template .xlsx</span>
                    </a>
                </div>

                <details class="group pt-1.5 border-t border-[var(--border-subtle)] text-[11px]">
                    <summary class="cursor-pointer font-medium text-[var(--primary-accessible)] hover:underline flex items-center justify-between py-0.5 list-none">
                        <span>Lihat panduan pengisian & format kolom</span>
                        <svg class="size-3.5 text-[var(--text-muted)] transition-transform duration-200 group-open:rotate-180" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                        </svg>
                    </summary>
                    <ul class="text-[11px] text-[var(--text-secondary)] list-disc list-inside space-y-1 mt-1.5 pl-0.5 leading-relaxed">
                        <li>Kolom wajib: <strong class="text-[var(--text)]">Nama Siswa</strong>; <strong class="text-[var(--text)]">NIS</strong> wajib untuk siswa baru. Siswa dengan NIS yang sudah ada akan diperbarui.</li>
                        <li><strong class="text-[var(--text)]">NIS</strong> adalah identitas sekolah. <strong class="text-[var(--text)]">ID Mesin</strong> (opsional) adalah nomor pendek yang terdaftar di perangkat; keduanya tidak harus sama.</li>
                        <li>Untuk sekaligus memetakan PIN, pilih <strong class="text-[var(--text)]">Mesin Fingerprint</strong> dan isi kolom <strong class="text-[var(--text)]">ID Mesin</strong> pada file.</li>
                        <li>Nomor WhatsApp otomatis dinormalisasi ke format internasional (<strong class="text-[var(--text)]">628...</strong>).</li>
                        <li>Kolom <strong class="text-[var(--text)]">Persetujuan WA</strong> diisi <strong class="text-[var(--text)]">Ya</strong> atau <strong class="text-[var(--text)]">Tidak</strong>. Kosong berarti mempertahankan persetujuan lama; jika nomor WhatsApp berubah, persetujuan akan direset sampai diisi <strong class="text-[var(--text)]">Ya</strong> kembali.</li>
                        <li>Isi <strong class="text-[var(--text)]">Ya</strong> hanya jika wali sudah menyetujui notifikasi WhatsApp. Template lama tanpa kolom ini tetap dapat diimpor.</li>
                        <li>Kelas yang belum ada di master akan dibuat otomatis.</li>
                    </ul>
                </details>
            </div>

            <div x-data="taskRunner({
                    progressUrl: '{{ url('api/v1/progress') }}',
                    label: 'Import Data Siswa',
                    reloadOnDone: false,
                    inline: true
                })">

            <form action="{{ route('students.import') }}" method="POST" enctype="multipart/form-data" class="space-y-3 text-xs"
                @submit.prevent="importResult = null; submit($el)">
                @csrf
                <div>
                    <label class="block font-semibold text-[var(--text)] mb-1">Pilih File Excel (.xlsx)</label>
                    <input type="file" name="file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required
                        class="block w-full text-xs text-[var(--text)] file:mr-3 file:py-1.5 file:px-3 file:rounded-[var(--radius-sm)] file:border-0 file:text-xs file:font-semibold file:bg-[var(--surface-secondary)] file:text-[var(--text)] hover:file:bg-[var(--border)] cursor-pointer border border-[var(--border)] rounded-[var(--radius-sm)] p-1 bg-[var(--surface-secondary)]/50 focus:outline-none">
                </div>

                @if($devices->count() > 0)
                <div>
                    <label class="block font-semibold text-[var(--text)] mb-1">Pilih Mesin Fingerprint <span class="text-[var(--text-muted)]">(Opsional)</span></label>
                    <select name="device_id" class="h-9 w-full rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] px-3 text-xs text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none focus:ring-1 focus:ring-[var(--primary-accessible)]">
                        <option value="">-- Tanpa pemetaan mesin --</option>
                        @foreach($devices as $d)
                            <option value="{{ $d->id }}">{{ $d->name }} ({{ $d->ip }})</option>
                        @endforeach
                    </select>
                    <p class="text-[11px] text-[var(--text-muted)] mt-1">Pilih mesin jika file memuat kolom <em>ID Mesin</em> yang ingin dipetakan. Baris dengan ID Mesin tapi tanpa mesin terpilih akan dicatat sebagai error.</p>
                </div>
                @endif

                <div class="flex justify-end gap-2 pt-3 border-t border-[var(--border-subtle)]">
                    <button type="button" @click="importModal = false" :disabled="running" class="px-3.5 py-1.5 rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface)] hover:bg-[var(--surface-secondary)] text-xs font-semibold text-[var(--text-secondary)] transition-colors disabled:opacity-50 disabled:cursor-not-allowed">
                        Batal
                    </button>
                    <button type="submit" :disabled="running" class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-[var(--radius-sm)] bg-[var(--primary)] text-xs font-semibold text-white hover:brightness-95 transition-colors shadow-2xs disabled:opacity-50 disabled:cursor-not-allowed">
                        <svg class="size-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
                        </svg>
                        <span x-text="running ? 'Memproses...' : 'Mulai Import'"></span>
                    </button>
                </div>
            </form>

            <div x-show="running || finished || failed" x-cloak class="mt-3 pt-3 border-t border-[var(--border-subtle)]">
                @include('partials.progress-task')
            </div>

            <div x-cloak x-show="importResult" x-transition class="mt-4 space-y-2 border-t border-[var(--border-subtle)] pt-3">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h3 class="text-xs font-bold text-[var(--text)]">Hasil Import Terakhir</h3>
                        <p class="text-[11px] text-[var(--text-muted)]">Ringkasan dan baris yang perlu diperiksa.</p>
                    </div>
                    <button type="button" onclick="window.location.reload()"
                        class="shrink-0 rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface)] px-2.5 py-1 text-[10px] font-semibold text-[var(--text-secondary)] hover:bg-[var(--surface-secondary)]">
                        Muat ulang data
                    </button>
                </div>

                <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                    <template x-for="entry in importResultEntries" :key="entry.label">
                        <div class="rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface-secondary)] px-2.5 py-2">
                            <div class="text-[10px] text-[var(--text-muted)]" x-text="entry.label"></div>
                            <div class="text-sm font-bold text-[var(--text)]" x-text="entry.value"></div>
                        </div>
                    </template>
                </div>

                <div x-show="importResult && importResult.errors && importResult.errors.length" class="max-h-40 overflow-y-auto rounded-[var(--radius-sm)] border border-amber-300 bg-amber-50 px-2.5 py-2 text-[11px] text-amber-800 dark:border-amber-700 dark:bg-amber-950/40 dark:text-amber-200">
                    <div class="font-semibold">Baris bermasalah (maksimal 20):</div>
                    <ul class="mt-1 list-disc list-inside space-y-0.5">
                        <template x-for="(error, index) in (importResult && importResult.errors ? importResult.errors : [])" :key="index">
                            <li x-text="error"></li>
                        </template>
                    </ul>
                </div>

                <p x-show="importResult && (!importResult.errors || importResult.errors.length === 0)" class="text-[11px] text-[var(--success)]">
                    Tidak ada baris gagal.
                </p>
            </div>
            </div>
        </div>
    </div>

    <!-- Modal Konfirmasi Hapus Siswa (Soft Delete) Tunggal -->
    <div x-cloak x-show="deleteModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-[#0f1623]/60 backdrop-blur-xs overflow-y-auto">
        <div @click.away="deleteModal = false" class="bg-[var(--surface)] rounded-[var(--radius-lg)] border border-[var(--border)] shadow-xl w-full max-w-md p-5 my-auto max-h-[90vh] overflow-y-auto">
            <div class="flex items-center gap-3 mb-3">
                <div class="p-2.5 rounded-full bg-rose-100 text-rose-600 dark:bg-rose-950/60 dark:text-rose-400">
                    <svg class="size-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                </div>
                <div>
                    <h3 class="text-sm font-bold text-[var(--text)]">Pindahkan ke Sampah?</h3>
                    <p class="text-xs text-[var(--text-secondary)]">Siswa: <strong class="text-[var(--text)]" x-text="studentToDelete.name"></strong></p>
                </div>
            </div>

            <div class="rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface-secondary)] p-3 text-xs text-[var(--text)] mb-3 space-y-1">
                <div class="font-semibold flex items-center gap-1.5 text-[var(--text)]">
                    <svg class="size-4 text-[var(--primary-accessible)]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                    <span>Mode Aman (Soft Delete) Aktif</span>
                </div>
                <p class="text-[11px] leading-relaxed text-[var(--text-secondary)]">
                    Data siswa dan seluruh riwayat presensinya <strong class="text-[var(--text)]">tidak akan hilang</strong>. Siswa dipindahkan ke tab <em>Sampah</em> dan dapat dipulihkan kapan saja dengan 1 klik.
                </p>
            </div>

            <form :action="'{{ url('/students') }}/' + studentToDelete.id" method="POST" class="space-y-3 text-xs">
                @csrf
                @method('DELETE')

                <div class="rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface-secondary)] p-3 text-xs text-[var(--text-secondary)]">
                    <p class="text-[11px] leading-relaxed">
                        Penghapusan di aplikasi <strong class="text-[var(--text)]">tidak menyentuh mesin fingerprint</strong>. PIN dan sidik jari siswa tetap tersimpan di perangkat.
                        Untuk membersihkannya, hapus pemetaan lewat menu <em>Perangkat</em>.
                    </p>
                </div>

                <div class="flex justify-end gap-2 pt-3 border-t border-[var(--border-subtle)]">
                    <button type="button" @click="deleteModal = false" class="px-3.5 py-1.5 rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface)] hover:bg-[var(--surface-secondary)] text-xs font-semibold text-[var(--text-secondary)] transition-colors">
                        Batal
                    </button>
                    <button type="submit" class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-[var(--radius-sm)] bg-rose-600 hover:bg-rose-700 text-xs font-semibold text-white transition-colors shadow-xs">
                        <svg class="size-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                        <span>Pindahkan ke Sampah</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Konfirmasi Hapus Massal (Bulk Soft Delete) -->
    <div x-cloak x-show="bulkDeleteModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-[#0f1623]/60 backdrop-blur-xs overflow-y-auto">
        <div @click.away="bulkDeleteModal = false" class="bg-[var(--surface)] rounded-[var(--radius-lg)] border border-[var(--border)] shadow-xl w-full max-w-md p-5 my-auto max-h-[90vh] overflow-y-auto">
            <div class="flex items-center gap-3 mb-3">
                <div class="p-2.5 rounded-full bg-rose-100 text-rose-600 dark:bg-rose-950/60 dark:text-rose-400">
                    <svg class="size-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                </div>
                <div>
                    <h3 class="text-sm font-bold text-[var(--text)]">Hapus Massal Siswa?</h3>
                    <p class="text-xs text-[var(--text-secondary)]"><strong class="text-[var(--text)]" x-text="selected.length"></strong> siswa terpilih akan dipindahkan ke Sampah.</p>
                </div>
            </div>

            <div class="rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface-secondary)] p-3 text-xs text-[var(--text)] mb-3 space-y-1">
                <div class="font-semibold flex items-center gap-1.5 text-[var(--text)]">
                    <svg class="size-4 text-[var(--primary-accessible)]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                    <span>Mode Aman (Soft Delete) Aktif</span>
                </div>
                <p class="text-[11px] leading-relaxed text-[var(--text-secondary)]">
                    Siswa tidak akan terhapus permanen. Seluruh data dapat dipulihkan kapan saja dari tab <em>Sampah</em>.
                </p>
            </div>

            <form action="{{ route('students.bulk-destroy') }}" method="POST" class="space-y-3 text-xs">
                @csrf
                @method('DELETE')
                <template x-for="id in selected" :key="id">
                    <input type="hidden" name="ids[]" :value="id">
                </template>

                <div class="rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface-secondary)] p-3 text-xs text-[var(--text-secondary)]">
                    <p class="text-[11px] leading-relaxed">
                        Penghapusan di aplikasi <strong class="text-[var(--text)]">tidak menyentuh mesin fingerprint</strong>. PIN dan sidik jari siswa tetap tersimpan di perangkat.
                        Untuk membersihkannya, hapus pemetaan lewat menu <em>Perangkat</em>.
                    </p>
                </div>

                <div class="flex justify-end gap-2 pt-3 border-t border-[var(--border-subtle)]">
                    <button type="button" @click="bulkDeleteModal = false" class="px-3.5 py-1.5 rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface)] hover:bg-[var(--surface-secondary)] text-xs font-semibold text-[var(--text-secondary)] transition-colors">
                        Batal
                    </button>
                    <button type="submit" class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-[var(--radius-sm)] bg-rose-600 hover:bg-rose-700 text-xs font-semibold text-white transition-colors shadow-xs">
                        <svg class="size-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                        <span>Pindahkan Terpilih ke Sampah</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

