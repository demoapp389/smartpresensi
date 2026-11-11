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
    <form id="audit-filter" method="GET" action="{{ route('audit.index') }}" class="flex flex-wrap items-end gap-2 text-xs">
        <div class="flex h-8.5 items-center gap-1.5 rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface-secondary)] px-2.5">
            <label for="date_from" class="text-[var(--text-secondary)] font-medium whitespace-nowrap">Dari:</label>
            <input type="date" id="date_from" name="date_from" value="{{ $dateFrom }}" class="bg-transparent font-medium text-[var(--text)] focus:outline-none cursor-pointer text-xs">
        </div>

        <div class="flex h-8.5 items-center gap-1.5 rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface-secondary)] px-2.5">
            <label for="date_to" class="text-[var(--text-secondary)] font-medium whitespace-nowrap">Sampai:</label>
            <input type="date" id="date_to" name="date_to" value="{{ $dateTo }}" class="bg-transparent font-medium text-[var(--text)] focus:outline-none cursor-pointer text-xs">
        </div>

        <select name="action" onchange="this.form.submit()" class="h-8.5 rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface-secondary)] px-2.5 text-xs text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none cursor-pointer">
            <option value="">Semua Aksi</option>
            @foreach($actions as $actionOption)
                <option value="{{ $actionOption }}" {{ $action === $actionOption ? 'selected' : '' }}>{{ $actionOption }}</option>
            @endforeach
        </select>

        <select name="entity_type" onchange="this.form.submit()" class="h-8.5 rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface-secondary)] px-2.5 text-xs text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none cursor-pointer">
            <option value="">Semua Entitas</option>
            @foreach($entityTypes as $entityOption)
                <option value="{{ $entityOption }}" {{ $entityType === $entityOption ? 'selected' : '' }}>{{ $entityOption }}</option>
            @endforeach
        </select>

        <button type="submit" class="inline-flex h-8.5 items-center justify-center rounded-[var(--radius-sm)] bg-[var(--primary)] px-3 text-xs font-semibold text-white hover:brightness-90 transition-colors">
            Terapkan
        </button>

        <label class="inline-flex h-8.5 items-center gap-1.5 rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface-secondary)] px-2.5 text-xs font-medium text-[var(--text-secondary)] cursor-pointer">
            <input type="checkbox" name="show_system" value="1" {{ $showSystem ? 'checked' : '' }}
                   onchange="this.form.submit()"
                   class="size-3.5 rounded border-[var(--border)] text-[var(--primary)] focus:ring-[var(--primary-accessible)] cursor-pointer">
            Tampilkan aksi sistem
        </label>

        @if($action !== '' || $entityType !== '' || $dateFrom !== '' || $dateTo !== '' || $showSystem)
            <a href="{{ route('audit.index') }}" class="inline-flex h-8.5 items-center justify-center rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface)] px-3 text-xs font-medium text-[var(--text-secondary)] hover:bg-[var(--surface-secondary)] transition-colors">
                Reset
            </a>
        @endif
    </form>

    <!-- Table Card -->
    <section class="min-w-0 rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] shadow-[var(--shadow)] overflow-hidden">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--border-subtle)] px-4 py-3">
            <h2 class="text-xs font-bold text-[var(--text)]">Catatan Jejak Audit Sistem</h2>
            <div class="text-[11px] text-[var(--text-muted)]">Audit Log Immutable</div>
        </div>

        <div>
            <table class="min-w-[700px] w-full text-left text-xs font-mono">
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
                    @forelse($logs as $log)
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
                    @empty
                    <tr>
                        <td colspan="6" class="px-4 py-8 text-center text-sm text-[var(--text-muted)] font-sans">
                            Belum ada riwayat audit log sistem sesuai filter.
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-[var(--border-subtle)] px-4 py-3 text-xs">
            <div class="flex items-center gap-3">
                <span class="text-[12px] text-[var(--text-muted)]">
                    Menampilkan {{ $logs->firstItem() ?? 0 }}–{{ $logs->lastItem() ?? 0 }} dari {{ $logs->total() }} catatan
                </span>
                @include('partials.per-page', ['paginator' => $logs])
            </div>
            <div>
                {{ $logs->links() }}
            </div>
        </div>
    </section>
</div>
@endsection
