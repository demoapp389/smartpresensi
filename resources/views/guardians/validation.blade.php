@extends('layouts.app')

@section('title', 'Validasi Nomor Wali')
@section('page-pretitle', 'WhatsApp')
@section('page-title', 'Validasi Nomor WhatsApp Wali')
@section('page-subtitle', 'Verifikasi nomor WhatsApp wali murid sebelum notifikasi absensi dikirim')

@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="transition-colors hover:text-[var(--text)]">Beranda</a>
    <span aria-hidden="true">/</span>
    <span>WhatsApp</span>
    <span aria-hidden="true">/</span>
    <span class="font-medium text-[var(--text)]" aria-current="page">Validasi Nomor Wali</span>
@endsection

@section('content')
<div class="space-y-4" x-data="taskRunner({ progressUrl: '{{ url('api/v1/progress') }}', reloadOnDone: true })">

    <div class="grid grid-cols-2 sm:grid-cols-5 gap-3 ">
        <x-stat-card label="Total Wali" :value="$stats['total']" hint="Wali aktif terdaftar" />
        <x-stat-card label="Valid" :value="$stats['valid']" tone="success" hint="Nomor terdaftar di WhatsApp" />
        <x-stat-card label="Pending" :value="$stats['pending']" tone="warning" hint="Belum pernah divalidasi" />
        <x-stat-card label="Invalid" :value="$stats['invalid']" tone="danger" hint="Tidak aktif / format salah" />
        <x-stat-card label="Unknown" :value="$stats['unknown']" tone="default" hint="Gagal menghubungi provider" />
    </div>

    {{-- Aksi validasi massal --}}
    <div class="rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] p-3 shadow-[var(--shadow)] flex flex-wrap items-center justify-between gap-3 text-xs">
        <div class="text-[11px] text-[var(--text-secondary)]">
            Validasi massal dijalankan di latar belakang. Notifikasi ke nomor yang tidak valid otomatis ditahan.
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <form action="{{ route('guardians.bulk-validate') }}" method="POST" class="inline"
                @submit.prevent="askConfirm({ title: 'Validasi Nomor Wali', message: 'Validasi nomor wali berstatus PENDING (maks 50 per klik)?', confirmLabel: 'Validasi', variant: 'primary' }).then(ok => { if (ok) { submit($el, 'Validasi Nomor Wali (Pending)'); } })">
                @csrf
                <input type="hidden" name="scope" value="pending">
                <input type="hidden" name="limit" value="50">
                <input type="hidden" name="async" value="1">
                <button type="submit" :disabled="running"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-[var(--radius-sm)] bg-[var(--primary)] text-xs font-semibold text-white hover:brightness-95 transition-colors disabled:opacity-50">
                    <svg x-show="running" class="size-3.5 animate-spin" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/>
                    </svg>
                    <x-icon name="phone-check" x-show="!running" class="size-3.5" />
                    <span x-text="running ? 'Memvalidasi...' : 'Validasi Pending (maks 50)'"></span>
                </button>
            </form>

            <form action="{{ route('guardians.bulk-validate') }}" method="POST" class="inline"
                @submit.prevent="askConfirm({ title: 'Validasi Ulang Nomor Wali', message: 'Validasi ulang seluruh nomor wali berstatus INVALID (maks 50 per klik)?', confirmLabel: 'Validasi Ulang', variant: 'primary' }).then(ok => { if (ok) { submit($el, 'Validasi Nomor Wali (Invalid)'); } })">
                @csrf
                <input type="hidden" name="scope" value="invalid">
                <input type="hidden" name="limit" value="50">
                <input type="hidden" name="async" value="1">
                <button type="submit" :disabled="running"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface)] text-xs font-semibold text-[var(--text)] hover:bg-[var(--surface-secondary)] transition-colors disabled:opacity-50">
                    <span>Validasi Ulang Invalid</span>
                </button>
            </form>
        </div>
    </div>

    {{-- Filter dipindah ke toolbar DataTables oleh tables.js: dropdown jumlah
         baris + filter di kiri, kotak pencarian bawaan DataTables di kanan
         (lengkap dengan ikon). Tetap form GET biasa bila JS mati. --}}
    <form id="guardian-filter" method="GET" action="{{ route('guardians.validation') }}" class="flex flex-wrap items-center gap-2 text-xs">
        <select name="status" onchange="this.form.submit()" class="h-8.5 rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface-secondary)] px-2.5 text-xs text-[var(--text)] focus:border-[var(--primary)] focus:outline-none cursor-pointer">
            <option value="">Semua Status</option>
            <option value="valid" {{ $status === 'valid' ? 'selected' : '' }}>Valid</option>
            <option value="pending" {{ $status === 'pending' ? 'selected' : '' }}>Pending</option>
            <option value="invalid" {{ $status === 'invalid' ? 'selected' : '' }}>Invalid</option>
            <option value="unknown" {{ $status === 'unknown' ? 'selected' : '' }}>Unknown</option>
        </select>

        @if($status)
            <a href="{{ route('guardians.validation') }}" class="inline-flex items-center justify-center h-8.5 rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface)] px-3 text-xs font-medium text-[var(--text-secondary)] hover:bg-[var(--surface-secondary)] transition-colors">
                Reset
            </a>
        @endif
    </form>

    {{-- Tabel --}}
    <section class="min-w-0 rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] shadow-[var(--shadow)] overflow-hidden">
        <div>
            <table class="min-w-[700px] w-full text-left text-xs" data-datatable data-page-length="10" data-length-change="true" data-toolbar-filter="#guardian-filter" data-empty-message="Belum ada data wali">
                <thead class="bg-[var(--surface-secondary)] text-[11px] font-semibold text-[var(--text-secondary)] uppercase tracking-wider border-b border-[var(--border-subtle)]">
                    <tr>
                        <th scope="col" class="px-4 py-2.5">Wali Murid</th>
                        <th scope="col" class="px-4 py-2.5">Siswa</th>
                        <th scope="col" class="px-4 py-2.5">Nomor WhatsApp</th>
                        <th scope="col" class="px-4 py-2.5">Status</th>
                        <th scope="col" class="px-4 py-2.5">Terakhir Divalidasi</th>
                        <th scope="col" data-orderable="false" class="px-4 py-2.5 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--border-subtle)] text-[13px]">
                    @foreach($guardians as $guardian)
                    <tr class="hover:bg-[var(--surface-secondary)]/50 transition-colors">
                        <td class="px-4 py-2.5">
                            <div class="font-semibold text-[var(--text)]">{{ $guardian->name }}</div>
                            <div class="text-[10px] text-[var(--text-muted)]">{{ $guardian->relationship }}</div>
                        </td>
                        <td class="px-4 py-2.5">
                            <div class="font-medium text-[var(--text)]">{{ $guardian->student?->name ?? '-' }}</div>
                            <div class="text-[10px] text-[var(--text-muted)]">{{ $guardian->student?->schoolClass?->name ?? 'Tanpa Kelas' }}</div>
                        </td>
                        <td class="px-4 py-2.5 font-mono text-xs">
                            @if($guardian->wa_number)
                                +{{ $guardian->wa_number }}
                            @else
                                <span class="text-[var(--text-muted)] italic">Tidak diisi</span>
                            @endif
                        </td>
                        <td class="px-4 py-2.5">
                            @php($waStatus = $guardian->wa_status ?? 'pending')
                            @if($waStatus === 'valid')
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">Valid</span>
                            @elseif($waStatus === 'invalid')
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold bg-rose-50 text-rose-700 border border-rose-200">Invalid</span>
                            @elseif($waStatus === 'unknown')
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold bg-slate-100 text-slate-700 border border-slate-200">Unknown</span>
                            @else
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold bg-amber-50 text-amber-700 border border-amber-200">Pending</span>
                            @endif
                        </td>
                        <td class="px-4 py-2.5 text-[var(--text-muted)] text-xs whitespace-nowrap">
                            {{ $guardian->wa_validated_at?->format('d/m/Y H:i') ?? '-' }}
                        </td>
                        <td class="px-4 py-2.5 text-right">
                            @if($guardian->wa_number)
                                <form action="{{ route('guardians.validate', $guardian) }}" method="POST" class="inline"
                                    data-confirm="Validasi nomor {{ $guardian->wa_number }} ({{ $guardian->name }}) sekarang?"
                                    data-confirm-title="Validasi Nomor Wali"
                                    data-confirm-label="Validasi"
                                    data-confirm-variant="primary">
                                    @csrf
                                    <button type="submit" title="Validasi nomor ini sekarang"
                                        class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[11px] font-semibold bg-sky-50 text-sky-800 border border-sky-200 hover:bg-sky-100 transition-colors cursor-pointer">
                                        <x-icon name="phone-check" class="size-3 text-sky-600" />
                                        <span>Validasi</span>
                                    </button>
                                </form>
                            @else
                                <span class="text-[11px] text-[var(--text-muted)]">Isi nomor di Data Siswa</span>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
</div>
@endsection
