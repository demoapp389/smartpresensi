@extends('layouts.app')

@section('title', 'Riwayat Sinkronisasi')
@section('page-pretitle', 'Mesin Fingerprint')
@section('page-title', 'Riwayat Sinkronisasi Mesin')
@section('page-subtitle', 'Log pengujian koneksi, sinkronisasi siswa, dan penarikan absensi seluruh mesin')

@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="transition-colors hover:text-[var(--text)]">Beranda</a>
    <span aria-hidden="true">/</span>
    <a href="{{ route('devices.index') }}" class="transition-colors hover:text-[var(--text)]">Mesin Fingerprint</a>
    <span aria-hidden="true">/</span>
    <span class="font-medium text-[var(--text)]" aria-current="page">Riwayat Sinkronisasi</span>
@endsection

@section('content')
@php
    $syncTypeLabels = [
        'test_connection' => 'Tes Koneksi',
        'sync_students' => 'Sinkron Siswa',
        'sync_attendances' => 'Sinkron Absensi',
        'sync_time' => 'Sinkron Waktu',
        'push_user' => 'Kirim User',
        'push_template' => 'Kirim Template',
        'delete_user' => 'Hapus User',
        'restart_device' => 'Restart Mesin',
        'clear_data' => 'Hapus Log',
    ];
@endphp
<div class="space-y-4">
    <section class="min-w-0 rounded-[var(--radius-lg)] border border-[var(--border)] bg-[var(--surface)] shadow-[var(--shadow)] overflow-hidden">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--border)] px-4 py-3 text-xs">
            <div class="card-title text-sm font-semibold text-[var(--text)]">Riwayat Sinkronisasi</div>

            <form method="GET" action="{{ route('devices.sync-history') }}" class="flex flex-wrap items-center gap-2">
                <select name="device_id" onchange="this.form.submit()" class="h-8.5 rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface-secondary)] px-2.5 text-xs text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none cursor-pointer">
                    <option value="">Semua Mesin</option>
                    @foreach($devices as $dev)
                        <option value="{{ $dev->id }}" {{ (string) $selectedDeviceId === (string) $dev->id ? 'selected' : '' }}>
                            {{ $dev->name }}
                        </option>
                    @endforeach
                </select>

                <select name="sync_type" onchange="this.form.submit()" class="h-8.5 rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface-secondary)] px-2.5 text-xs text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none cursor-pointer">
                    <option value="">Semua Jenis</option>
                    @foreach($syncTypeLabels as $typeValue => $typeLabel)
                        <option value="{{ $typeValue }}" {{ $syncType === $typeValue ? 'selected' : '' }}>{{ $typeLabel }}</option>
                    @endforeach
                </select>

                <select name="status" onchange="this.form.submit()" class="h-8.5 rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface-secondary)] px-2.5 text-xs text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none cursor-pointer">
                    <option value="">Semua Status</option>
                    <option value="success" {{ $status === 'success' ? 'selected' : '' }}>Berhasil</option>
                    <option value="failed" {{ $status === 'failed' ? 'selected' : '' }}>Gagal</option>
                </select>

                @if($selectedDeviceId || $syncType || $status)
                    <a href="{{ route('devices.sync-history') }}" class="inline-flex items-center justify-center h-8.5 rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface)] px-3 text-xs font-medium text-[var(--text-secondary)] hover:bg-[var(--surface-secondary)] transition-colors">
                        Reset
                    </a>
                @endif
            </form>
        </div>

        <div>
            <table class="w-full text-left">
                <thead class="bg-[var(--surface-secondary)]">
                    <tr>
                        <th class="px-4 py-2 text-[11px] font-bold uppercase tracking-wider text-[var(--text-muted)]">Waktu</th>
                        <th class="px-4 py-2 text-[11px] font-bold uppercase tracking-wider text-[var(--text-muted)]">Mesin</th>
                        <th class="px-4 py-2 text-[11px] font-bold uppercase tracking-wider text-[var(--text-muted)]">Jenis Sinkronisasi</th>
                        <th class="px-4 py-2 text-[11px] font-bold uppercase tracking-wider text-[var(--text-muted)]">Status</th>
                        <th class="px-4 py-2 text-[11px] font-bold uppercase tracking-wider text-[var(--text-muted)]">Record</th>
                        <th class="px-4 py-2 text-[11px] font-bold uppercase tracking-wider text-[var(--text-muted)]">Keterangan / Hasil</th>
                        <th class="px-4 py-2 text-[11px] font-bold uppercase tracking-wider text-[var(--text-muted)]">Operator</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--border-subtle)] text-[13px]">
                    @foreach($syncLogs as $sLog)
                    <tr class="hover:bg-[var(--surface-secondary)] transition-colors">
                        <td class="px-4 py-2.5 font-mono text-xs text-[var(--text-secondary)] whitespace-nowrap">
                            <div>{{ $sLog->created_at->format('H:i:s') }}</div>
                            <div class="text-[10px] text-[var(--text-muted)]">{{ $sLog->created_at->format('d/m/Y') }}</div>
                        </td>
                        <td class="px-4 py-2.5">
                            @if($sLog->device)
                                <div>
                                    <a href="{{ route('devices.show', $sLog->device) }}" class="font-medium text-[var(--primary-accessible)] hover:underline">{{ $sLog->device->name }}</a>
                                    <div class="text-[10px] font-mono text-[var(--text-muted)]">{{ $sLog->device->ip }}</div>
                                </div>
                            @else
                                <span class="text-[var(--text-muted)]">Perangkat #{{ $sLog->device_id }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-2.5">
                            @if($sLog->sync_type === 'sync_students')
                                <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-[var(--radius-sm)] text-[11px] font-semibold border bg-[var(--primary-soft)] text-[var(--primary-accessible)] border-[var(--primary)]/30">
                                    <svg class="size-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                                    Sinkron Siswa
                                </span>
                            @elseif($sLog->sync_type === 'sync_attendances')
                                <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-[var(--radius-sm)] text-[11px] font-semibold border bg-emerald-50 text-emerald-700 border-emerald-200">
                                    <svg class="size-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                    Sinkron Absensi
                                </span>
                            @else
                                <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-[var(--radius-sm)] text-[11px] font-semibold border bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 border-slate-200 dark:border-slate-700">
                                    <svg class="size-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                                    {{ $syncTypeLabels[$sLog->sync_type] ?? $sLog->sync_type }}
                                </span>
                            @endif
                        </td>
                        <td class="px-4 py-2.5">
                            @if($sLog->status === 'success')
                                <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-[var(--radius-sm)] text-[11px] font-semibold border bg-emerald-50 text-emerald-700 border-emerald-200">
                                    <span class="size-1.5 rounded-full bg-[var(--success)] animate-pulse"></span>
                                    <span>Berhasil</span>
                                </span>
                            @else
                                <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-[var(--radius-sm)] text-[11px] font-semibold border bg-rose-50 text-rose-700 border-rose-200">
                                    <span class="size-1.5 rounded-full bg-[var(--danger)]"></span>
                                    <span>Gagal</span>
                                </span>
                            @endif
                        </td>
                        <td class="px-4 py-2.5 font-semibold text-[var(--text)] text-xs">{{ $sLog->records_count }}</td>
                        <td class="px-4 py-2.5 text-[var(--text-secondary)] align-top">
                            <div class="max-w-md whitespace-normal break-words text-[11px]">{{ $sLog->message }}</div>
                        </td>
                        <td class="px-4 py-2.5 text-[var(--text-muted)] text-xs whitespace-nowrap">{{ $sLog->operator?->name ?? 'Sistem (Otomatis)' }}</td>
                    </tr>
                    @endforeach

                    @if($syncLogs->isEmpty())
                        <tr>
                            <td colspan="7" class="px-4 py-8 text-center text-sm text-[var(--text-muted)]">
                                Belum ada riwayat sinkronisasi
                            </td>
                        </tr>
                    @endif
                </tbody>
            </table>
        </div>

        @if($syncLogs->hasPages())
            <div class="border-t border-[var(--border)] px-4 py-3">
                {{ $syncLogs->links() }}
            </div>
        @endif
    </section>
</div>
@endsection
