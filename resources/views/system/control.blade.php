@extends('layouts.app')

@section('title', 'Kontrol Sistem')
@section('page-pretitle', 'Operasional & Layanan')
@section('page-title', 'Pusat Kontrol Sistem & Background Services')
@section('page-subtitle', 'Monitoring terpadu Windows Service, C# Connector Mesin, Laravel Queue Worker, Scheduler, dan Heartbeat')

@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="transition-colors hover:text-[var(--text)]">Beranda</a>
    <span aria-hidden="true">/</span>
    <span>Operasional &amp; Layanan</span>
    <span aria-hidden="true">/</span>
    <span class="font-medium text-[var(--text)]" aria-current="page">Kontrol Sistem</span>
@endsection

@section('content')
<div class="space-y-4" x-data="{
    activeTab: 'connector',
    logs: @js($logsConnector),
    loadingLogs: false,
    fetchLogs(service) {
        this.activeTab = service;
        this.loadingLogs = true;
        fetch('{{ route('system.logs') }}?service=' + service)
            .then(res => res.json())
            .then(data => {
                this.logs = data.logs;
                this.loadingLogs = false;
            })
            .catch(() => { this.loadingLogs = false; });
    }
}">

    <!-- Quick Actions Card -->
    <div class="space-y-3" x-data="taskRunner({ progressUrl: '{{ url('api/v1/progress') }}', reloadOnDone: true, reloadDelay: 3500 })">
        <section class="rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] shadow-[var(--shadow)] overflow-hidden">
            <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                <div class="min-w-0">
                    <h2 class="text-xs font-bold text-[var(--text)]">Aksi Layanan Cepat</h2>
                    <p class="text-[11px] text-[var(--text-muted)]">Sinkronisasi massal, restart background service, dan restart supervisor Windows</p>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <form action="{{ route('system.sync-all-devices') }}" method="POST"
                        @submit.prevent="askConfirm({ title: 'Sinkronisasi Semua Mesin', message: 'Jalankan sinkronisasi absensi ke seluruh mesin aktif?', confirmLabel: 'Jalankan', variant: 'primary' }).then(ok => { if (ok) { submit($el, 'Sinkronisasi Semua Mesin'); } })">
                        @csrf
                        <button type="submit" :disabled="running" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-[var(--radius-sm)] border border-[var(--primary)]/30 bg-[var(--primary-soft)] text-xs font-semibold text-[var(--primary-accessible)] hover:brightness-95 transition-colors shadow-xs disabled:opacity-50">
                            <svg x-show="running" class="size-3.5 animate-spin" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/>
                            </svg>
                            <svg x-show="!running" class="size-3.5 text-[var(--primary-accessible)]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                            <span x-text="running ? 'Sinkronisasi...' : 'Sinkron Massal'"></span>
                        </button>
                    </form>

                    <form action="{{ route('system.restart-all') }}" method="POST"
                        data-confirm="Restart seluruh background service (Connector, Queue Worker, & Scheduler)?"
                        data-confirm-title="Restart Semua Service"
                        data-confirm-label="Restart"
                        data-confirm-variant="warning">
                        @csrf
                        <button type="submit" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-[var(--radius-sm)] bg-[var(--sidebar-bg)] text-xs font-semibold text-white hover:bg-slate-800 transition-colors shadow-xs">
                            <svg class="size-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                            <span>Restart Semua Layanan</span>
                        </button>
                    </form>

                    <form action="{{ route('system.restart-supervisor') }}" method="POST"
                        data-confirm="Restart Windows Service SmartPresensiSupervisor? Koneksi web akan terputus beberapa detik saat layanan dimulai ulang."
                        data-confirm-title="Restart Supervisor"
                        data-confirm-label="Restart Supervisor"
                        data-confirm-variant="warning">
                        @csrf
                        <button type="submit" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-[var(--radius-sm)] border border-[var(--danger)]/30 bg-[var(--danger)]/10 text-xs font-semibold text-[var(--danger)] hover:bg-[var(--danger)]/15 transition-colors">
                            <svg class="size-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v4m0 4h.01M5.07 19H19a2 2 0 001.73-3L13.73 4a2 2 0 00-3.46 0L3.34 16A2 2 0 005.07 19z"/></svg>
                            <span>Restart Supervisor</span>
                        </button>
                    </form>
                </div>
            </div>
        </section>

    </div>

    <!-- System Health Banner Card -->
    <div class="rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] p-4 shadow-[var(--shadow)] flex flex-wrap items-center justify-between gap-4">
        <div class="flex items-center gap-3">
            <div class="size-10 rounded-[var(--radius)] flex items-center justify-center shrink-0
                {{ $status['overall_status'] === 'healthy' ? 'bg-emerald-50 text-emerald-600 border border-emerald-200' : ($status['overall_status'] === 'degraded' ? 'bg-amber-50 text-amber-600 border border-amber-200' : 'bg-rose-50 text-rose-600 border border-rose-200') }}">
                @if($status['overall_status'] === 'healthy')
                    <svg class="size-5 animate-pulse" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                @elseif($status['overall_status'] === 'degraded')
                    <svg class="size-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                @else
                    <svg class="size-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/></svg>
                @endif
            </div>
            <div>
                <div class="flex items-center gap-2">
                    <h3 class="text-sm font-bold text-[var(--text)]">
                        @if($status['overall_status'] === 'healthy')
                            Status Sistem: Berjalan Normal
                        @elseif($status['overall_status'] === 'degraded')
                            Status Sistem: Perlu Perhatian
                        @else
                            Status Sistem: Layanan Background Nonaktif
                        @endif
                    </h3>
                    <span class="px-1.5 py-0.2 rounded text-[10px] font-bold uppercase
                        {{ $status['overall_status'] === 'healthy' ? 'bg-emerald-50 text-emerald-800 border border-emerald-200' : ($status['overall_status'] === 'degraded' ? 'bg-amber-50 text-amber-800 border border-amber-200' : 'bg-rose-50 text-rose-800 border border-rose-200') }}">
                        {{ $status['overall_status'] }}
                    </span>
                </div>
                <div class="text-xs text-[var(--text-secondary)] mt-0.5 flex items-center gap-2">
                    <span>Heartbeat: <strong class="{{ $status['heartbeat']['fresh'] ? 'text-emerald-700' : 'text-rose-700' }}">{{ $status['heartbeat']['last_time'] }}</strong></span>
                    <span class="text-[var(--text-muted)]">&bull;</span>
                    <span>Mode: {{ $status['is_live_api'] ? 'Real-Time API (Port 4372)' : 'File Heartbeat Observer' }}</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Core Service Status Cards -->
    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4">
        <!-- 1. Connector Service Card -->
        <div class="rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] p-4 shadow-[var(--shadow)] flex flex-col justify-between space-y-3">
            <div>
                <div class="flex items-center justify-between">
                    <span class="text-[10px] font-bold uppercase tracking-wider text-[var(--text-muted)]">Background Service 1</span>
                    @if($status['connector']['running'])
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                            <span class="size-1.5 rounded-full bg-[var(--success)] animate-pulse"></span>
                            <span>Running</span>
                        </span>
                    @else
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-bold bg-rose-50 text-rose-700 border border-rose-200">
                            <span class="size-1.5 rounded-full bg-[var(--danger)]"></span>
                            <span>Stopped</span>
                        </span>
                    @endif
                </div>

                <h4 class="text-sm font-bold text-[var(--text)] mt-1.5">C# Fingerprint Connector</h4>
                <p class="text-[11px] text-[var(--text-secondary)]">Membaca scan mesin fingerprint via LAN TCP/IP &amp; ZKEM SDK</p>

                <div class="mt-3 p-2.5 bg-[var(--surface-secondary)] rounded-[var(--radius-sm)] border border-[var(--border-subtle)] space-y-1.5 text-xs">
                    <div class="flex justify-between">
                        <span class="text-[var(--text-secondary)] text-[11px]">Process ID (PID):</span>
                        <span class="font-mono font-semibold text-[var(--text)] text-[11px]">{{ $status['connector']['pid'] ?? '-' }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-[var(--text-secondary)] text-[11px]">Auto-Restart:</span>
                        <span class="font-mono font-semibold text-[var(--text)] text-[11px]">{{ $status['connector']['restarts'] }}x</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-[var(--text-secondary)] text-[11px]">Local API Port:</span>
                        <span class="font-mono font-semibold text-[var(--text)] text-[11px]">127.0.0.1:4371</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-[var(--text-secondary)] text-[11px]">Waktu Mulai:</span>
                        <span class="text-[var(--text)] text-[11px]">{{ $status['connector']['started_at'] ?: '-' }}</span>
                    </div>
                </div>
            </div>

            <form action="{{ route('system.restart-connector') }}" method="POST"
                data-confirm="Restart service C# Connector?"
                data-confirm-title="Restart Connector"
                data-confirm-label="Restart"
                data-confirm-variant="warning">
                @csrf
                <button type="submit" class="w-full py-1.5 px-3 rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface)] hover:bg-[var(--surface-secondary)] text-[var(--text)] font-semibold text-xs transition-colors flex items-center justify-center gap-1.5">
                    <svg class="size-3.5 text-[var(--text-secondary)]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                    <span>Restart Connector</span>
                </button>
            </form>
        </div>

        <!-- 2. Queue Worker Card -->
        <div class="rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] p-4 shadow-[var(--shadow)] flex flex-col justify-between space-y-3">
            <div>
                <div class="flex items-center justify-between">
                    <span class="text-[10px] font-bold uppercase tracking-wider text-[var(--text-muted)]">Background Service 2</span>
                    @if($status['worker']['running'])
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                            <span class="size-1.5 rounded-full bg-[var(--success)] animate-pulse"></span>
                            <span>Running</span>
                        </span>
                    @else
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-bold bg-rose-50 text-rose-700 border border-rose-200">
                            <span class="size-1.5 rounded-full bg-[var(--danger)]"></span>
                            <span>Stopped</span>
                        </span>
                    @endif
                </div>

                <h4 class="text-sm font-bold text-[var(--text)] mt-1.5">Laravel Queue Worker</h4>
                <p class="text-[11px] text-[var(--text-secondary)]">Memproses antrean kirim notifikasi WhatsApp ke wali siswa</p>

                <div class="mt-3 p-2.5 bg-[var(--surface-secondary)] rounded-[var(--radius-sm)] border border-[var(--border-subtle)] space-y-1.5 text-xs">
                    <div class="flex justify-between">
                        <span class="text-[var(--text-secondary)] text-[11px]">Process ID (PID):</span>
                        <span class="font-mono font-semibold text-[var(--text)] text-[11px]">{{ $status['worker']['pid'] ?? '-' }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-[var(--text-secondary)] text-[11px]">Auto-Restart:</span>
                        <span class="font-mono font-semibold text-[var(--text)] text-[11px]">{{ $status['worker']['restarts'] }}x</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-[var(--text-secondary)] text-[11px]">Pending Jobs:</span>
                        <span class="font-bold text-amber-700 text-[11px]">{{ $status['worker']['pending_jobs'] }} Pesan</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-[var(--text-secondary)] text-[11px]">Failed Jobs:</span>
                        <span class="font-bold text-rose-700 text-[11px]">{{ $status['worker']['failed_jobs'] }} Pesan</span>
                    </div>
                </div>
            </div>

            <form action="{{ route('system.restart-worker') }}" method="POST"
                data-confirm="Restart service Laravel Queue Worker?"
                data-confirm-title="Restart Queue Worker"
                data-confirm-label="Restart"
                data-confirm-variant="warning">
                @csrf
                <button type="submit" class="w-full py-1.5 px-3 rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface)] hover:bg-[var(--surface-secondary)] text-[var(--text)] font-semibold text-xs transition-colors flex items-center justify-center gap-1.5">
                    <svg class="size-3.5 text-[var(--text-secondary)]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                    <span>Restart Queue Worker</span>
                </button>
            </form>
        </div>

        <!-- 3. Laravel Scheduler Card -->
        <div class="rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] p-4 shadow-[var(--shadow)] flex flex-col justify-between space-y-3">
            <div>
                <div class="flex items-center justify-between">
                    <span class="text-[10px] font-bold uppercase tracking-wider text-[var(--text-muted)]">Background Service 3</span>
                    @if(! $status['scheduler']['enabled'])
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-bold bg-slate-50 text-slate-700 border border-slate-200">
                            <span class="size-1.5 rounded-full bg-slate-400"></span>
                            <span>Disabled</span>
                        </span>
                    @elseif($status['scheduler']['running'])
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                            <span class="size-1.5 rounded-full bg-[var(--success)] animate-pulse"></span>
                            <span>Running</span>
                        </span>
                    @else
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-bold bg-rose-50 text-rose-700 border border-rose-200">
                            <span class="size-1.5 rounded-full bg-[var(--danger)]"></span>
                            <span>Stopped</span>
                        </span>
                    @endif
                </div>

                <h4 class="text-sm font-bold text-[var(--text)] mt-1.5">Laravel Scheduler</h4>
                <p class="text-[11px] text-[var(--text-secondary)]">Menjalankan health check, rekonsiliasi, prune resource, dan kedaluwarsa notifikasi</p>

                <div class="mt-3 p-2.5 bg-[var(--surface-secondary)] rounded-[var(--radius-sm)] border border-[var(--border-subtle)] space-y-1.5 text-xs">
                    <div class="flex justify-between">
                        <span class="text-[var(--text-secondary)] text-[11px]">Process ID (PID):</span>
                        <span class="font-mono font-semibold text-[var(--text)] text-[11px]">{{ $status['scheduler']['pid'] ?? '-' }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-[var(--text-secondary)] text-[11px]">Auto-Restart:</span>
                        <span class="font-mono font-semibold text-[var(--text)] text-[11px]">{{ $status['scheduler']['restarts'] }}x</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-[var(--text-secondary)] text-[11px]">Waktu Mulai:</span>
                        <span class="text-[var(--text)] text-[11px]">{{ $status['scheduler']['started_at'] ?: '-' }}</span>
                    </div>
                </div>
            </div>

            <p class="text-[10px] text-[var(--text-muted)] leading-relaxed">Dikelola otomatis oleh Windows Service Supervisor.</p>
        </div>

        <!-- 4. Terminal Mesin Fingerprint Status Card -->
        <div class="rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] p-4 shadow-[var(--shadow)] flex flex-col justify-between space-y-3">
            <div>
                <div class="flex items-center justify-between">
                    <span class="text-[10px] font-bold uppercase tracking-wider text-[var(--text-muted)]">Terminal Hardware</span>
                    <span class="text-xs font-semibold text-[var(--text)]">
                        {{ $status['devices']['online'] }} / {{ $status['devices']['total'] }} Online
                    </span>
                </div>

                <h4 class="text-sm font-bold text-[var(--text)] mt-1.5">Mesin Fingerprint (LAN)</h4>
                <p class="text-[11px] text-[var(--text-secondary)]">Monitoring konektivitas fisik perangkat fingerprint</p>

                <div class="mt-3 space-y-1.5 max-h-36 overflow-y-auto">
                    @forelse($status['devices']['list'] as $dev)
                    <div class="p-2 rounded-[var(--radius-sm)] bg-[var(--surface-secondary)] border border-[var(--border-subtle)] flex items-center justify-between text-xs">
                        <div>
                            <div class="font-semibold text-[var(--text)] text-xs">{{ $dev->name }}</div>
                            <div class="text-[10px] font-mono text-[var(--text-muted)]">{{ $dev->ip }}:{{ $dev->port }}</div>
                        </div>
                        <div class="flex items-center gap-1.5">
                            @php($deviceStatus = $dev->displayStatus())
                            @if($deviceStatus === 'active')
                                <span class="px-1.5 py-0.2 rounded text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">Online</span>
                            @elseif($deviceStatus === 'maintenance')
                                <span class="px-1.5 py-0.2 rounded text-[10px] font-bold bg-amber-50 text-amber-700 border border-amber-200">Maintenance</span>
                            @else
                                <span class="px-1.5 py-0.2 rounded text-[10px] font-bold bg-rose-50 text-rose-700 border border-rose-200">Offline</span>
                            @endif
                            <form action="{{ route('devices.test-connection', $dev) }}" method="POST" class="inline">
                                @csrf
                                <button type="submit" title="Tes Koneksi Cepat" class="p-1 hover:bg-[var(--border-subtle)] rounded text-[var(--text-secondary)] transition-colors">
                                    <svg class="size-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                                </button>
                            </form>
                        </div>
                    </div>
                    @empty
                    <div class="text-xs text-[var(--text-muted)] text-center py-4">Belum ada mesin didaftarkan.</div>
                    @endforelse
                </div>
            </div>

            <a href="{{ route('devices.index') }}" class="w-full py-1.5 px-3 rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface-secondary)] hover:bg-[var(--border-subtle)] text-[var(--text)] font-semibold text-xs transition-colors flex items-center justify-center gap-1.5">
                <span>Buka Pengaturan Mesin</span>
                <span>&rarr;</span>
            </a>
        </div>
    </div>

    <!-- Live Terminal / Log Viewer Panel (Gentelella Navy Console) -->
    <div class="bg-[var(--sidebar-bg)] rounded-[var(--radius)] border border-[var(--sidebar-border)] shadow-[var(--shadow)] overflow-hidden">
        <div class="p-3 border-b border-[var(--sidebar-border)] flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                <span class="size-2.5 rounded-full bg-rose-500 inline-block"></span>
                <span class="size-2.5 rounded-full bg-amber-500 inline-block"></span>
                <span class="size-2.5 rounded-full bg-emerald-500 inline-block"></span>
                <span class="text-xs font-mono font-semibold text-white ml-2">Live Service Diagnostic Logs</span>
            </div>

            <!-- Tab Switcher -->
            <div class="flex items-center gap-1 bg-[#141d2b] p-1 rounded border border-[var(--sidebar-border)] text-xs">
                <button type="button" @click="fetchLogs('connector')"
                    :class="activeTab === 'connector' ? 'bg-[var(--primary)] text-white font-semibold' : 'text-[var(--sidebar-text)] hover:text-white'"
                    class="px-2.5 py-1 rounded text-xs transition-colors">
                    Connector Mesin Fingerprint
                </button>
                <button type="button" @click="fetchLogs('worker')"
                    :class="activeTab === 'worker' ? 'bg-[var(--primary)] text-white font-semibold' : 'text-[var(--sidebar-text)] hover:text-white'"
                    class="px-2.5 py-1 rounded text-xs transition-colors">
                    Queue Worker WhatsApp
                </button>
                <button type="button" @click="fetchLogs(activeTab)"
                    class="p-1 text-[var(--sidebar-text)] hover:text-white transition-colors" title="Muat Ulang Log">
                    <svg class="size-3.5" :class="loadingLogs ? 'animate-spin' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                </button>
            </div>
        </div>

        <!-- Terminal Output Box -->
        <div data-autohide-scrollbar class="on-dark p-4 font-mono text-xs text-emerald-400 bg-[#0f1623] max-h-72 overflow-y-auto space-y-0.5">
            <template x-for="(line, idx) in logs" :key="idx">
                <div class="leading-relaxed hover:bg-white/5 px-1 rounded transition-colors select-all" x-text="line"></div>
            </template>
            <div x-show="logs.length === 0" class="text-[var(--sidebar-text)] py-4 text-center">
                Belum ada rekaman log untuk service ini.
            </div>
        </div>
    </div>

    <!-- Database Backup Card (Dump .sql Langsung dari Browser) -->
    <div class="rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] p-4 shadow-[var(--shadow)] space-y-3">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-2.5">
                <span class="size-8 rounded-[var(--radius-sm)] bg-emerald-50 text-emerald-600 border border-emerald-200 flex items-center justify-center shrink-0">
                    <svg class="size-4.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"/></svg>
                </span>
                <div>
                    <h4 class="font-bold text-xs text-[var(--text)]">Pencadangan Database (.sql Dump)</h4>
                    <p class="text-[11px] text-[var(--text-secondary)]">Unduh salinan penuh basis data sekolah (Data Siswa, Mesin, Presensi, Log Mentah, dan Audit) langsung dari browser</p>
                </div>
            </div>

            <a href="{{ route('system.backup.database') }}"
               class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-[var(--radius-sm)] bg-[var(--primary)] text-xs font-semibold text-white hover:brightness-90 transition-colors shadow-xs">
                <svg class="size-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                <span>Unduh Dump SQL Sekarang</span>
            </a>
        </div>

        <div class="p-3 rounded-[var(--radius-sm)] bg-[var(--surface-secondary)] border border-[var(--border-subtle)] text-xs text-[var(--text-secondary)] grid grid-cols-1 sm:grid-cols-3 gap-2.5">
            <div>
                <span class="text-[10px] text-[var(--text-muted)] uppercase font-semibold block">Koneksi Aktif</span>
                <span class="font-mono text-xs font-semibold text-[var(--text)] uppercase">{{ config('database.default') }}</span>
            </div>
            <div>
                <span class="text-[10px] text-[var(--text-muted)] uppercase font-semibold block">Nama Database</span>
                <span class="font-mono text-xs font-semibold text-[var(--text)]">{{ config('database.connections.'.config('database.default').'.database') }}</span>
            </div>
            <div>
                <span class="text-[10px] text-[var(--text-muted)] uppercase font-semibold block">Keamanan Ekspor</span>
                <span class="text-xs text-emerald-600 font-medium flex items-center gap-1">
                    <svg class="size-3" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                    Tercatat di Audit Log
                </span>
            </div>
        </div>
    </div>

    <!-- Windows Service Auto-Start Setup Info Card -->
    <div class="rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] p-4 shadow-[var(--shadow)] space-y-2">
        <div class="flex items-center gap-2">
            <span class="size-6 rounded bg-[var(--primary-soft)] text-[var(--primary-accessible)] flex items-center justify-center font-bold text-xs">
                i
            </span>
            <div>
                <h4 class="font-bold text-xs text-[var(--text)]">Pemasangan Otomatis Saat Komputer Menyala (Windows Service)</h4>
                <p class="text-[11px] text-[var(--text-secondary)]">Agar sistem langsung bekerja saat PC dinyalakan tanpa perlu login akun Windows</p>
            </div>
        </div>

        <div class="p-3 rounded-[var(--radius-sm)] bg-[var(--surface-secondary)] border border-[var(--border-subtle)] text-xs text-[var(--text-secondary)] space-y-1.5 leading-relaxed">
            <p>
                Teknisi / Administrator komputer sekolah cukup menjalankan skrip instalasi Windows Service <strong>satu kali saja</strong>:
            </p>
            <div class="font-mono bg-[var(--sidebar-bg)] text-emerald-400 p-2 rounded text-xs select-all border border-[var(--sidebar-border)]">
                Kontrol\Mulai SmartPresensi.cmd
            </div>
            <p class="text-[11px] text-[var(--text-muted)]">
                Setelah dipasang, Windows Service <code>SmartPresensiSupervisor</code> akan otomatis berjalan di latar belakang saat komputer menyala. Operator sekolah cukup membuka browser untuk memantau kehadiran dan mengontrol sistem melalui dashboard ini.
            </p>
        </div>
    </div>
</div>
@endsection

