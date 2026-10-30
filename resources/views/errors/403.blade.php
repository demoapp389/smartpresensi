@extends('layouts.app')

@section('title', 'Akses Dibatasi (403)')
@section('page-pretitle', 'Keamanan Sistem')
@section('page-title', 'Hak Akses Tidak Mencukupi')
@section('page-subtitle', 'Fitur atau konfigurasi ini dikhususkan untuk Administrator sekolah')

@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="transition-colors hover:text-[var(--text)]">Beranda</a>
    <span aria-hidden="true">/</span>
    <span>Keamanan Sistem</span>
    <span aria-hidden="true">/</span>
    <span class="font-medium text-[var(--text)]" aria-current="page">Akses Dibatasi</span>
@endsection

@section('content')
<div class="flex items-center justify-center min-h-[60vh]">
    <div class="max-w-md w-full rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] p-6 shadow-[var(--shadow)] text-center space-y-4">
        <!-- Shield Lock Icon -->
        <div class="mx-auto size-16 rounded-full bg-rose-50 border border-rose-200 flex items-center justify-center text-rose-600">
            <svg class="size-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
            </svg>
        </div>

        <div>
            <span class="inline-block px-2.5 py-0.5 rounded text-[11px] font-bold uppercase tracking-wider bg-rose-100 text-rose-800 border border-rose-200">
                HTTP 403 Forbidden
            </span>
            <h2 class="mt-2 text-lg font-bold text-[var(--text)]">Akses Ditolak (RBAC)</h2>
            <p class="mt-1 text-xs text-[var(--text-secondary)] leading-relaxed">
                {{ $exception->getMessage() ?: 'Halaman atau tindakan ini memerlukan hak akses Administrator sekolah.' }}
            </p>
        </div>

        <div class="p-3 rounded-[var(--radius-sm)] bg-[var(--surface-secondary)] border border-[var(--border)] text-left text-[11px] text-[var(--text-secondary)] space-y-1">
            <div class="flex items-center justify-between">
                <span class="font-medium text-[var(--text-muted)]">Peran Anda Saat Ini:</span>
                <span class="font-bold uppercase text-[var(--text)] px-1.5 py-0.5 rounded bg-[var(--surface)] border border-[var(--border)]">
                    {{ Auth::user()?->role === 'admin' ? 'Administrator' : 'Operator Absensi' }}
                </span>
            </div>
            <p class="text-[10px] text-[var(--text-muted)] mt-1">
                Operator hanya diperkenankan mengelola data siswa, memantau log, mereview unmapped, koreksi absensi, dan memantau notifikasi outbox.
            </p>
        </div>

        <div class="pt-2 flex items-center justify-center gap-2">
            <a href="{{ route('dashboard') }}" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-[var(--radius-sm)] bg-[var(--primary)] text-xs font-semibold text-white hover:brightness-95 transition-all shadow-xs">
                <svg class="size-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
                <span>Kembali ke Dashboard</span>
            </a>
            <a href="{{ route('attendances.index') }}" class="inline-flex items-center gap-1.5 px-3 py-2 rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface)] text-xs font-medium text-[var(--text)] hover:bg-[var(--surface-secondary)] transition-colors">
                <span>Daftar Kehadiran</span>
            </a>
        </div>
    </div>
</div>
@endsection

