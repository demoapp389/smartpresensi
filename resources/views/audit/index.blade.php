@extends('layouts.app')

@section('title', 'Log Audit Sistem')
@section('page-pretitle', 'Keamanan & Kepatuhan')
@section('page-title', 'Log Audit Sistem')
@section('page-subtitle', 'Pencatatan riwayat perubahan data sensitif, konfigurasi jadwal, dan koreksi manual operator')

@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="transition-colors hover:text-[var(--text)]">Beranda</a>
    <span aria-hidden="true">/</span>
    <span>Keamanan &amp; Kepatuhan</span>
    <span aria-hidden="true">/</span>
    <span class="font-medium text-[var(--text)]" aria-current="page">Log Audit Sistem</span>
@endsection

@section('content')
<div class="space-y-4">
    <!-- Table Card -->
    <section class="min-w-0 rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] shadow-[var(--shadow)] overflow-hidden">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--border-subtle)] px-4 py-3">
            <h2 class="text-xs font-bold text-[var(--text)]">Catatan Jejak Audit Sistem</h2>
            <div class="text-[11px] text-[var(--text-muted)]">Audit Log Immutable</div>
        </div>

        <div>
            <table class="min-w-[700px] w-full text-left text-xs font-mono" data-datatable data-page-length="25" data-length-change="true" data-empty-message="Belum ada riwayat audit log sistem">
                <thead class="bg-[var(--surface-secondary)] text-[11px] font-semibold text-[var(--text-secondary)] uppercase tracking-wider border-b border-[var(--border-subtle)] font-sans">
                    <tr>
                        <th scope="col" class="px-4 py-2.5">Waktu Kejadian</th>
                        <th scope="col" class="px-4 py-2.5">Aktor (Pengguna)</th>
                        <th scope="col" class="px-4 py-2.5">Aksi</th>
                        <th scope="col" class="px-4 py-2.5">Entitas</th>
                        <th scope="col" class="px-4 py-2.5">IP Address</th>
                        <th scope="col" class="px-4 py-2.5">Detail Perubahan</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--border-subtle)] text-[13px]">
                    @foreach($logs as $log)
                    <tr class="hover:bg-[var(--surface-secondary)]/50 transition-colors">
                        <td class="px-4 py-2.5 text-[var(--text)] font-semibold whitespace-nowrap">
                            {{ $log->created_at->format('Y-m-d H:i:s') }}
                        </td>
                        <td class="px-4 py-2.5 font-sans font-medium text-[var(--text)]">
                            {{ $log->actor->name ?? 'System' }}
                        </td>
                        <td class="px-4 py-2.5">
                            <span class="inline-flex items-center px-1.5 py-0.2 rounded bg-[var(--surface-secondary)] text-[var(--text)] border border-[var(--border)] text-[11px] font-semibold">
                                {{ $log->action }}
                            </span>
                        </td>
                        <td class="px-4 py-2.5 font-sans text-xs text-[var(--text-secondary)]">
                            {{ $log->entity_type }} #{{ $log->entity_id }}
                        </td>
                        <td class="px-4 py-2.5 text-[var(--text-muted)] text-[11px]">
                            {{ $log->ip_address ?? '127.0.0.1' }}
                        </td>
                        <td class="px-4 py-2.5 text-[11px] max-w-sm truncate text-[var(--text-secondary)]">
                            @if($log->after)
                                <span class="text-emerald-700 font-semibold">After:</span> {{ json_encode($log->after) }}
                            @elseif($log->before)
                                <span class="text-rose-700 font-semibold">Before:</span> {{ json_encode($log->before) }}
                            @else
                                -
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

