@extends('layouts.app')

@section('title', 'Peninjauan Unmapped')
@section('page-pretitle', 'Hardware & Integrasi')
@section('page-title', 'Peninjauan Log Mesin Belum Terpetakan')
@section('page-subtitle', 'Log tidak dikenal disimpan untuk pencocokan manual ke profil siswa')

@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="transition-colors hover:text-[var(--text)]">Beranda</a>
    <span aria-hidden="true">/</span>
    <span>Hardware &amp; Integrasi</span>
    <span aria-hidden="true">/</span>
    <span class="font-medium text-[var(--text)]" aria-current="page">Peninjauan Unmapped</span>
@endsection

@section('content')
<div class="space-y-4" x-data="{ mapModal: false, activeLog: {} }">
    <!-- Information Alert Card -->
    <div class="rounded-[var(--radius)] border border-amber-200/80 bg-amber-50/80 p-3.5 text-xs text-amber-900 flex items-start gap-3 shadow-xs">
        <span class="p-1 rounded bg-amber-200/80 text-amber-800 shrink-0 mt-0.5">
            <svg class="size-4" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>
        </span>
        <div>
            <h4 class="font-bold">Mekanisme Penanganan Log Tidak Dikenal:</h4>
            <p class="text-amber-800 text-[11px] mt-0.5 leading-relaxed">
                Ketika siswa baru melakukan scan di mesin fingerprint tetapi User ID / PIN mesin belum dipetakan, data <strong>tidak akan dibuang atau diabaikan</strong>.
                Begitu Anda memetakan PIN ke siswa di bawah, seluruh log historis terkait akan <strong>otomatis diproses</strong> menjadi absensi siswa yang valid!
            </p>
        </div>
    </div>

    {{-- Filter dipindah ke toolbar DataTables oleh tables.js: dropdown jumlah
         baris + filter di kiri, kotak pencarian bawaan DataTables di kanan.
         Tetap form GET biasa bila JS mati. --}}
    <form id="unmapped-filter" method="GET" action="{{ route('logs.unmapped') }}" class="flex flex-wrap items-center gap-2 text-xs">
        <select name="device_id" onchange="this.form.submit()" class="h-8.5 rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface-secondary)] px-2.5 text-xs text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none cursor-pointer">
            <option value="">Semua Perangkat</option>
            @foreach($devices as $d)
                <option value="{{ $d->id }}" {{ $deviceId == $d->id ? 'selected' : '' }}>{{ $d->name }}</option>
            @endforeach
        </select>

        @if($deviceId)
            <a href="{{ route('logs.unmapped') }}" class="inline-flex h-8.5 items-center justify-center rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface)] px-3 text-xs font-medium text-[var(--text-secondary)] hover:bg-[var(--surface-secondary)] transition-colors">
                Reset
            </a>
        @endif
    </form>

    <section class="min-w-0 rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] shadow-[var(--shadow)] overflow-hidden">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--border-subtle)] px-4 py-3">
            <h2 class="text-xs font-bold text-[var(--text)]">Daftar Scan Belum Terpetakan</h2>
        </div>

        <div>
            <table class="min-w-[650px] w-full text-left text-xs font-mono" data-datatable data-page-length="25" data-length-change="true" data-toolbar-filter="#unmapped-filter" data-empty-message="Semua scan mesin telah terpetakan">
                <thead class="bg-[var(--surface-secondary)] text-[11px] font-semibold text-[var(--text-secondary)] uppercase tracking-wider border-b border-[var(--border-subtle)] font-sans">
                    <tr>
                        <th scope="col" class="px-4 py-2.5">Waktu Scan Mesin</th>
                        <th scope="col" class="px-4 py-2.5">Perangkat</th>
                        <th scope="col" class="px-4 py-2.5">User ID / PIN Mesin</th>
                        <th scope="col" class="px-4 py-2.5">Source Key</th>
                        <th scope="col" data-orderable="false" class="px-4 py-2.5 text-right font-sans">Tindakan</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--border-subtle)] text-[13px]">
                    @foreach($unmappedLogs as $uLog)
                    <tr class="hover:bg-[var(--surface-secondary)]/50 transition-colors">
                        <td class="px-4 py-2.5 font-bold text-[var(--text)]">{{ $uLog->occurred_at->format('Y-m-d H:i:s') }}</td>
                        <td class="px-4 py-2.5 font-sans font-medium text-[var(--text)]">{{ $uLog->device->name ?? 'Mesin Fingerprint' }}</td>
                        <td class="px-4 py-2.5">
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-bold bg-amber-50 text-amber-800 border border-amber-200">
                                {{ $uLog->machine_user_id }}
                            </span>
                        </td>
                        <td class="px-4 py-2.5 text-[11px] text-[var(--text-muted)] truncate max-w-xs" title="{{ $uLog->source_key }}">{{ $uLog->source_key }}</td>
                        <td class="px-4 py-2.5 text-right font-sans">
                            <button type="button"
                                @click="activeLog = {
                                    device_id: '{{ $uLog->device_id }}',
                                    device_name: '{{ addslashes($uLog->device->name ?? 'Mesin Fingerprint') }}',
                                    machine_user_id: '{{ $uLog->machine_user_id }}'
                                }; mapModal = true;"
                                class="inline-flex items-center px-2.5 py-1 rounded-[var(--radius-sm)] bg-[var(--primary)] text-white text-xs font-semibold hover:brightness-95 transition-colors shadow-xs">
                                Petakan ke Siswa
                            </button>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <!-- Modal Petakan PIN ke Siswa -->
    <div x-cloak x-show="mapModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-[#0f1623]/60 backdrop-blur-xs">
        <div @click.away="mapModal = false" class="bg-[var(--surface)] rounded-[var(--radius-lg)] border border-[var(--border)] shadow-xl w-full max-w-md p-5">
            <h3 class="text-sm font-bold text-[var(--text)] mb-1">
                Petakan PIN Mesin <span class="font-mono text-[var(--primary-accessible)]" x-text="activeLog.machine_user_id"></span>
            </h3>
            <p class="text-[11px] text-[var(--text-secondary)] mb-4">
                Pilih profil siswa pemilik sidik jari ini. Seluruh log historis dengan PIN ini akan otomatis terproses menjadi kehadiran siswa.
            </p>

            <form action="{{ route('logs.unmapped.map') }}" method="POST" class="space-y-3 text-xs">
                @csrf
                <input type="hidden" name="device_id" :value="activeLog.device_id">
                <input type="hidden" name="machine_user_id" :value="activeLog.machine_user_id">

                <div>
                    <label class="block font-semibold text-[var(--text)] mb-1">Perangkat</label>
                    <input type="text" readonly :value="activeLog.device_name" class="h-9 w-full rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface-secondary)] text-[var(--text-secondary)] px-3 text-xs font-semibold">
                </div>

                <div>
                    <label class="block font-semibold text-[var(--text)] mb-1">Pilih Siswa yang Sesuai</label>
                    <select name="student_id" required class="h-9 w-full rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] px-3 text-xs text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none focus:ring-1 focus:ring-[var(--primary-accessible)]">
                        <option value="">-- Pilih Siswa --</option>
                        @foreach($students as $st)
                            <option value="{{ $st->id }}">{{ $st->name }} ({{ $st->schoolClass?->name ?? '-' }})</option>
                        @endforeach
                    </select>
                </div>

                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" @click="mapModal = false" class="px-3.5 py-1.5 rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface)] hover:bg-[var(--surface-secondary)] text-xs font-semibold text-[var(--text-secondary)] transition-colors">
                        Batal
                    </button>
                    <button type="submit" class="px-3.5 py-1.5 rounded-[var(--radius-sm)] bg-[var(--primary)] text-xs font-semibold text-white hover:brightness-95 transition-colors">
                        Proses &amp; Petakan Sekarang
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

