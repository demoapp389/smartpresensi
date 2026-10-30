@extends('layouts.app')

@section('title', 'Dashboard Presensi')
@section('page-pretitle', 'Overview & Monitoring')
@section('page-title', 'Monitoring Presensi Realtime')
@section('page-subtitle', 'Data sinkronisasi dari mesin fingerprint dan antrean notifikasi WhatsApp wali murid')

@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="transition-colors hover:text-[var(--text)]">Beranda</a>
    <span aria-hidden="true">/</span>
    <span>Overview &amp; Monitoring</span>
    <span aria-hidden="true">/</span>
    <span class="font-medium text-[var(--text)]" aria-current="page">Dashboard Presensi</span>
@endsection


@section('content')
<div class="space-y-4">
    <!-- Top Filter Bar -->
    <div class="rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] p-3 shadow-[var(--shadow)] flex flex-col md:flex-row items-start md:items-center justify-between gap-3 text-xs">
        <div class="flex flex-wrap items-center gap-2.5">
            <div class="flex items-center gap-2">
                <span class="size-2 rounded-full {{ $isNonEffectiveDay ? 'bg-amber-500' : 'bg-[var(--success)] animate-pulse' }}"></span>
                <span class="font-semibold text-[var(--text)]">Status Kehadiran Hari Ini</span>
                <span class="text-[var(--text-muted)]">({{ \Carbon\Carbon::parse($selectedDate)->translatedFormat('l, d F Y') }})</span>
                @if($isNonEffectiveDay)
                    <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold {{ $isHoliday ? 'bg-rose-50 text-rose-700 border border-rose-200' : 'bg-amber-50 text-amber-700 border border-amber-200' }}">
                        {{ $isHoliday ? 'Hari Libur Khusus' : 'Libur Akhir Pekan / Non-Aktif' }}
                    </span>
                @endif
            </div>

            <!-- 5-Minute Auto-Refresh Indicator -->
            <div x-data="{
                secondsLeft: 300,
                formatTime() {
                    const m = Math.floor(this.secondsLeft / 60);
                    const s = this.secondsLeft % 60;
                    return String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
                },
                init() {
                    setInterval(() => {
                        if (this.secondsLeft > 1) {
                            this.secondsLeft--;
                        } else {
                            window.location.reload();
                        }
                    }, 1000);
                }
            }" class="hidden sm:inline-flex items-center gap-1.5 px-2 py-0.5 rounded-[var(--radius-sm)] bg-[var(--surface-secondary)] border border-[var(--border-subtle)] text-[11px] text-[var(--text-secondary)]">
                <svg class="size-3 text-[var(--primary-accessible)]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                <span>Auto-refresh: <strong class="font-mono text-[var(--text)]" x-text="formatTime()">05:00</strong></span>
                <button type="button" @click="window.location.reload()" title="Refresh sekarang" class="hover:text-[var(--primary-accessible)] transition-colors p-0.5 ml-0.5">
                    <svg class="size-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                </button>
            </div>
        </div>

        <form method="GET" action="{{ route('dashboard') }}" class="flex flex-wrap items-center gap-2 w-full md:w-auto">
            <div class="flex items-center gap-1.5 rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface-secondary)] px-2.5 py-1">
                <label for="date" class="text-[var(--text-secondary)] font-medium">Tanggal:</label>
                <input type="date" id="date" name="date" value="{{ $selectedDate }}" onchange="this.value && this.form.submit()" class="bg-transparent font-medium text-[var(--text)] focus:outline-none cursor-pointer text-xs">
            </div>

            @php
                $currentUser = Auth::user();
                $isHomeroom = $currentUser?->isHomeroomTeacher() ?? false;
            @endphp

            @if(! $isHomeroom)
                <select name="class_id" onchange="this.form.submit()" class="rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface-secondary)] px-2.5 py-1 text-xs text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none cursor-pointer">
                    <option value="">Semua Kelas</option>
                    @foreach($classes as $c)
                        <option value="{{ $c->id }}" {{ $selectedClassId == $c->id ? 'selected' : '' }}>{{ $c->name }}</option>
                    @endforeach
                </select>
            @elseif($classes->isNotEmpty())
                <div class="flex items-center gap-1.5 rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface-secondary)] px-2.5 py-1 text-xs">
                    <span class="text-[var(--text-secondary)] font-medium">Kelas:</span>
                    <span class="font-bold text-[var(--text)]">{{ $classes->first()->name }}</span>
                </div>
            @endif

            @if($selectedDate !== now('Asia/Jakarta')->toDateString() || (! $isHomeroom && $selectedClassId))
                <a href="{{ route('dashboard') }}" class="inline-flex items-center justify-center rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface)] px-3 py-1 text-xs font-medium text-[var(--text-secondary)] hover:bg-[var(--surface-secondary)] transition-colors">
                    Reset
                </a>
            @endif
        </form>
    </div>

    <!-- KPI Metric Tiles Grid (6 tiles) -->
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
        <!-- Total Siswa -->
        <article class="min-w-0 rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] p-3.5 shadow-[var(--shadow)]">
            <div class="flex items-start justify-between gap-2">
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-wider text-[var(--text-muted)] truncate">Total Siswa</p>
                    <p class="mt-1 text-2xl font-bold leading-none tabular-nums text-[var(--text)]">{{ $totalStudents }}</p>
                    <p class="mt-1.5 text-[11px] text-[var(--text-secondary)]">Siswa Aktif</p>
                </div>
                <span class="rounded bg-[var(--surface-secondary)] border border-[var(--border)] p-1.5 text-[var(--text-secondary)] shrink-0">
                    <svg class="size-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                </span>
            </div>
        </article>

        <!-- Hadir Tepat Waktu -->
        <article class="min-w-0 rounded-[var(--radius)] border border-emerald-200/60 bg-[var(--surface)] p-3.5 shadow-[var(--shadow)]">
            <div class="flex items-start justify-between gap-2">
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-wider text-emerald-700 truncate">Tepat Waktu</p>
                    <div class="mt-1 flex items-baseline gap-1.5">
                        <p class="text-2xl font-bold leading-none tabular-nums text-emerald-800">{{ $hadirCount }}</p>
                        <span class="rounded bg-emerald-50 px-1 py-0.2 text-[10px] font-semibold text-emerald-700 border border-emerald-200">
                            {{ $totalStudents > 0 ? round(($hadirCount / $totalStudents) * 100) : 0 }}%
                        </span>
                    </div>
                    <p class="mt-1.5 text-[11px] text-emerald-600 font-medium">Sesuai Jadwal</p>
                </div>
                <span class="rounded bg-emerald-50 p-1.5 text-emerald-600 border border-emerald-200/60 shrink-0">
                    <svg class="size-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                </span>
            </div>
        </article>

        <!-- Terlambat -->
        <article class="min-w-0 rounded-[var(--radius)] border border-amber-200/60 bg-[var(--surface)] p-3.5 shadow-[var(--shadow)]">
            <div class="flex items-start justify-between gap-2">
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-wider text-amber-700 truncate">Terlambat</p>
                    <p class="mt-1 text-2xl font-bold leading-none tabular-nums text-amber-800">{{ $terlambatCount }}</p>
                    <p class="mt-1.5 text-[11px] text-amber-600 font-medium">Lewat Toleransi</p>
                </div>
                <span class="rounded bg-amber-50 p-1.5 text-amber-600 border border-amber-200/60 shrink-0">
                    <svg class="size-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                </span>
            </div>
        </article>

        <!-- Sudah Pulang -->
        <article class="min-w-0 rounded-[var(--radius)] border border-blue-200/60 bg-[var(--surface)] p-3.5 shadow-[var(--shadow)]">
            <div class="flex items-start justify-between gap-2">
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-wider text-blue-700 truncate">Sudah Pulang</p>
                    <p class="mt-1 text-2xl font-bold leading-none tabular-nums text-blue-800">{{ $pulangCount }}</p>
                    <p class="mt-1.5 text-[11px] text-blue-600 font-medium">Check-Out Valid</p>
                </div>
                <span class="rounded bg-blue-50 p-1.5 text-blue-600 border border-blue-200/60 shrink-0">
                    <svg class="size-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                </span>
            </div>
        </article>

        <!-- Izin / Sakit -->
        <article class="min-w-0 rounded-[var(--radius)] border border-purple-200/60 bg-[var(--surface)] p-3.5 shadow-[var(--shadow)]">
            <div class="flex items-start justify-between gap-2">
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-wider text-purple-700 truncate">Izin / Sakit</p>
                    <p class="mt-1 text-2xl font-bold leading-none tabular-nums text-purple-800">{{ $izinSakitCount }}</p>
                    <p class="mt-1.5 text-[11px] text-purple-600 font-medium">Koreksi Operator</p>
                </div>
                <span class="rounded bg-purple-50 p-1.5 text-purple-600 border border-purple-200/60 shrink-0">
                    <svg class="size-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                </span>
            </div>
        </article>

        <!-- Belum Hadir / Alpa -->
        <article class="min-w-0 rounded-[var(--radius)] border border-rose-200/60 bg-[var(--surface)] p-3.5 shadow-[var(--shadow)]">
            <div class="flex items-start justify-between gap-2">
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-wider text-rose-700 truncate">Alpa / Belum Hadir</p>
                    <p class="mt-1 text-2xl font-bold leading-none tabular-nums text-rose-800">{{ $alpaCount + $belumHadirCount }}</p>
                    <p class="mt-1.5 text-[11px] text-rose-600 font-medium">
                        Belum Hadir / Perlu Tinjauan
                        @if($pendingCount > 0)
                            <span class="block text-orange-700">{{ $pendingCount }} perlu tinjauan</span>
                        @endif
                    </p>
                </div>
                <span class="rounded bg-rose-50 p-1.5 text-rose-600 border border-rose-200/60 shrink-0">
                    <svg class="size-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                </span>
            </div>
        </article>
    </div>

    <!-- Visualisasi Grafik: Tren Presensi 7 Hari Terakhir & Rasio Hari Ini -->
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-4">
        <!-- Grafik Bar: Tren Kehadiran 7 Hari Terakhir (8 Kolom) -->
        <section class="lg:col-span-8 rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] p-4 shadow-[var(--shadow)] flex flex-col justify-between">
            <div>
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 pb-3 border-b border-[var(--border-subtle)]">
                    <div>
                        <h3 class="text-xs font-bold text-[var(--text)] uppercase tracking-wider flex items-center gap-2">
                            <svg class="size-4 text-[var(--primary)]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 12l3-3 3 3 4-4M8 21l4-4 4 4M3 4h18M4 4h16v12a1 1 0 01-1 1H5a1 1 0 01-1-1V4z"/></svg>
                            <span>Tren Kehadiran 7 Hari Terakhir</span>
                        </h3>
                        <p class="text-[11px] text-[var(--text-muted)] mt-0.5">Komparasi jumlah siswa tepat waktu, terlambat, pulang cepat, izin/sakit, dan alpa/belum hadir</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2 text-[11px]">
                        <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded bg-[var(--surface-secondary)] border border-[var(--border-subtle)] text-[var(--text-secondary)] font-medium">
                            <span class="size-2 rounded-full bg-emerald-500"></span> Tepat Waktu
                        </span>
                        <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded bg-[var(--surface-secondary)] border border-[var(--border-subtle)] text-[var(--text-secondary)] font-medium">
                            <span class="size-2 rounded-full bg-amber-500"></span> Terlambat
                        </span>
                        <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded bg-[var(--surface-secondary)] border border-[var(--border-subtle)] text-[var(--text-secondary)] font-medium">
                            <span class="size-2 rounded-full bg-sky-500"></span> Pulang Cepat
                        </span>
                        <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded bg-[var(--surface-secondary)] border border-[var(--border-subtle)] text-[var(--text-secondary)] font-medium">
                            <span class="size-2 rounded-full bg-purple-500"></span> Izin/Sakit
                        </span>
                        <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded bg-[var(--surface-secondary)] border border-[var(--border-subtle)] text-[var(--text-secondary)] font-medium">
                            <span class="size-2 rounded-full bg-rose-500"></span> Alpa / Belum Hadir
                        </span>
                    </div>
                </div>

                <div class="relative mt-4 h-64 w-full">
                    <canvas id="trendChart"></canvas>
                </div>
            </div>

            <div class="mt-3 pt-2.5 border-t border-[var(--border-subtle)] flex items-center justify-between text-[11px] text-[var(--text-muted)]">
                <span>Data dihitung otomatis berdasarkan tanggal aktif sekolah.</span>
                <span class="font-medium text-[var(--text-secondary)]">Rentang: {{ $trendChartData['labels'][0] ?? '-' }} &mdash; {{ end($trendChartData['labels']) ?: '-' }}</span>
            </div>
        </section>

        <!-- Donut Chart: Rasio Kehadiran Hari Ini (4 Kolom) -->
        <section class="lg:col-span-4 rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] p-4 shadow-[var(--shadow)] flex flex-col justify-between">
            <div>
                <div class="pb-3 border-b border-[var(--border-subtle)]">
                    <h3 class="text-xs font-bold text-[var(--text)] uppercase tracking-wider flex items-center gap-2">
                        <svg class="size-4 text-[var(--primary)]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 3.055A9.001 9.001 0 1020.945 13H11V3.055z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.488 9H15V3.512A9.025 9.025 0 0120.488 9z"/></svg>
                        <span>Rasio Kehadiran Hari Ini</span>
                    </h3>
                    <p class="text-[11px] text-[var(--text-muted)] mt-0.5">Persentase total siswa berdasarkan status presensi</p>
                </div>

                <div class="relative mt-4 flex items-center justify-center h-48 w-full">
                    <canvas id="donutChart"></canvas>
                    <div class="absolute inset-0 flex flex-col items-center justify-center pointer-events-none">
                        <span class="text-2xl font-extrabold text-[var(--text)] tabular-nums">{{ $donutChartData['rate'] }}%</span>
                        <span class="text-[10px] uppercase font-bold tracking-wider text-[var(--text-muted)]">Hadir</span>
                    </div>
                </div>

                <div class="mt-4 space-y-1.5 text-xs">
                    <div class="flex items-center justify-between p-1.5 rounded bg-[var(--surface-secondary)]/60 text-[11px]">
                        <span class="flex items-center gap-1.5 font-medium text-[var(--text)]">
                            <span class="size-2 rounded-full bg-emerald-500"></span> Tepat Waktu
                        </span>
                        <span class="font-bold tabular-nums text-emerald-700">{{ $hadirCount }} Siswa</span>
                    </div>
                    <div class="flex items-center justify-between p-1.5 rounded bg-[var(--surface-secondary)]/60 text-[11px]">
                        <span class="flex items-center gap-1.5 font-medium text-[var(--text)]">
                            <span class="size-2 rounded-full bg-amber-500"></span> Terlambat
                        </span>
                        <span class="font-bold tabular-nums text-amber-700">{{ $terlambatCount }} Siswa</span>
                    </div>
                    <div class="flex items-center justify-between p-1.5 rounded bg-[var(--surface-secondary)]/60 text-[11px]">
                        <span class="flex items-center gap-1.5 font-medium text-[var(--text)]">
                            <span class="size-2 rounded-full bg-sky-500"></span> Pulang Cepat
                        </span>
                        <span class="font-bold tabular-nums text-sky-700">{{ $pulangCepatCount }} Siswa</span>
                    </div>
                    <div class="flex items-center justify-between p-1.5 rounded bg-[var(--surface-secondary)]/60 text-[11px]">
                        <span class="flex items-center gap-1.5 font-medium text-[var(--text)]">
                            <span class="size-2 rounded-full bg-purple-500"></span> Izin / Sakit
                        </span>
                        <span class="font-bold tabular-nums text-purple-700">{{ $izinSakitCount }} Siswa</span>
                    </div>
                    <div class="flex items-center justify-between p-1.5 rounded bg-[var(--surface-secondary)]/60 text-[11px]">
                        <span class="flex items-center gap-1.5 font-medium text-[var(--text)]">
                            <span class="size-2 rounded-full bg-rose-500"></span> Alpa / Belum Hadir
                        </span>
                        <span class="font-bold tabular-nums text-rose-700">{{ $alpaCount + $belumHadirCount }} Siswa</span>
                    </div>
                </div>
            </div>
        </section>
    </div>

    <!-- Alert Unmapped Logs jika ada (Khusus Admin/Operator) -->
    @if(! $isHomeroom && $unmappedLogs->count() > 0)
        <div class="p-3.5 rounded-[var(--radius)] bg-amber-50/90 border border-amber-200 text-amber-900 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 shadow-xs">
            <div class="flex items-start gap-2.5">
                <span class="p-1 rounded bg-amber-200/80 text-amber-800 shrink-0 mt-0.5">
                    <svg class="size-4" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>
                </span>
                <div>
                    <h4 class="text-xs font-bold">Perhatian: Ada {{ $unmappedLogs->count() }} Scan Mesin Belum Terpetakan ke Siswa!</h4>
                    <p class="text-[11px] text-amber-800 mt-0.5">
                        Log tidak dikenal disimpan sebagai unmapped dan tidak dibuang, agar operator dapat melakukan pemetaan manual ke siswa yang tepat.
                    </p>
                </div>
            </div>
            <a href="{{ route('logs.unmapped') }}" class="px-3 py-1 bg-amber-600 hover:bg-amber-700 text-white rounded-[var(--radius-sm)] text-xs font-semibold transition shrink-0">
                Tinjau Sekarang
            </a>
        </div>
    @endif

    <!-- System Integration Status Panels (Khusus Admin/Operator) -->
    @if(! $isHomeroom)
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
        <!-- Fingerprint Device Status -->
        <section class="rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] p-4 shadow-[var(--shadow)] space-y-3">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-2.5">
                    <span class="flex size-7.5 items-center justify-center rounded bg-[var(--sidebar-bg)] text-xs font-bold text-[var(--primary)] shadow-xs">
                        <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 11c0 3.517-1.009 6.799-2.753 9.571m-3.44-2.04l.054-.09A13.916 13.916 0 008 11a4 4 0 118 0c0 1.017-.07 2.019-.203 3m-2.118 6.844A21.88 21.88 0 0015.171 17m3.839 1.132c.645-2.266.99-4.659.99-7.132A8 8 0 004 11m0 0c0 4.29 1.547 8.223 4.12 11.282" />
                        </svg>
                    </span>
                    <div>
                        <h3 class="text-xs font-bold text-[var(--text)]">{{ $mainDevice->name ?? 'Mesin Fingerprint' }}</h3>
                        <p class="text-[11px] text-[var(--text-secondary)] font-normal">Terminal Hardware LAN TCP/IP</p>
                    </div>
                </div>
                <x-device-status :status="$mainDevice?->displayStatus() ?? 'offline'" />
            </div>

            @if($mainDevice)
                <div class="grid grid-cols-3 gap-2 pt-2 border-t border-[var(--border-subtle)] text-xs">
                    <div class="p-2 rounded-[var(--radius-sm)] bg-[var(--surface-secondary)] border border-[var(--border-subtle)]">
                        <div class="text-[10px] text-[var(--text-muted)] font-medium">IP LAN</div>
                        <div class="font-mono font-bold text-[var(--text)] text-xs mt-0.5">{{ $mainDevice->ip }}:{{ $mainDevice->port }}</div>
                    </div>
                    <div class="p-2 rounded-[var(--radius-sm)] bg-[var(--surface-secondary)] border border-[var(--border-subtle)]">
                        <div class="text-[10px] text-[var(--text-muted)] font-medium">PIN Terpetakan</div>
                        <div class="font-bold text-[var(--primary-accessible)] text-xs mt-0.5">{{ $mainDevice->device_users_count }} Siswa</div>
                    </div>
                    <div class="p-2 rounded-[var(--radius-sm)] bg-[var(--surface-secondary)] border border-[var(--border-subtle)]">
                        <div class="text-[10px] text-[var(--text-muted)] font-medium">Total Scan Log</div>
                        <div class="font-mono font-bold text-[var(--text)] text-xs mt-0.5">{{ $mainDevice->attendance_logs_count }} Log</div>
                    </div>
                </div>
            @endif
        </section>

        <!-- WhatsApp Gateway & Queue Status -->
        <section class="rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] p-4 shadow-[var(--shadow)] space-y-3">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-2.5">
                    <span class="flex size-7.5 items-center justify-center rounded bg-emerald-600 text-xs font-bold text-white shadow-xs">
                        WA
                    </span>
                    <div>
                        <h3 class="text-xs font-bold text-[var(--text)]">WhatsApp Gateway (Outbox)</h3>
                        <p class="text-[11px] text-[var(--text-secondary)] font-normal">Antrean Notifikasi Database Driver</p>
                    </div>
                </div>
                <a href="{{ route('notifications.index') }}" class="text-xs font-semibold text-[var(--primary-accessible)] hover:underline inline-flex items-center gap-1">
                    <span>Outbox</span>
                    <span>&rarr;</span>
                </a>
            </div>

            <div class="grid grid-cols-5 gap-1.5 pt-2 border-t border-[var(--border-subtle)] text-center text-xs">
                <div class="p-1.5 rounded-[var(--radius-sm)] bg-[var(--surface-secondary)] border border-[var(--border-subtle)]">
                    <div class="text-[9px] text-[var(--text-muted)] uppercase font-semibold">Total</div>
                    <div class="font-bold text-[var(--text)] mt-0.5">{{ $waStats['total'] }}</div>
                </div>
                <div class="p-1.5 rounded-[var(--radius-sm)] bg-amber-50/70 border border-amber-200/60">
                    <div class="text-[9px] text-amber-700 uppercase font-semibold">Antre</div>
                    <div class="font-bold text-amber-800 mt-0.5">{{ $waStats['queued'] }}</div>
                </div>
                <div class="p-1.5 rounded-[var(--radius-sm)] bg-purple-50/70 border border-purple-200/60" title="Notifikasi ditahan menunggu validasi nomor">
                    <div class="text-[9px] text-purple-700 uppercase font-semibold">Tahan</div>
                    <div class="font-bold text-purple-800 mt-0.5">{{ $waStats['held'] }}</div>
                </div>
                <div class="p-1.5 rounded-[var(--radius-sm)] bg-blue-50/70 border border-blue-200/60">
                    <div class="text-[9px] text-blue-700 uppercase font-semibold">Terkirim</div>
                    <div class="font-bold text-blue-800 mt-0.5">{{ $waStats['sent'] }}</div>
                </div>
                <div class="p-1.5 rounded-[var(--radius-sm)] bg-rose-50/70 border border-rose-200/60" title="Dibatalkan karena nomor wali invalid (menghemat kuota)">
                    <div class="text-[9px] text-rose-700 uppercase font-semibold">Hemat</div>
                    <div class="font-bold text-rose-800 mt-0.5">{{ $waStats['cancelled'] }}</div>
                </div>
            </div>
        </section>

        <!-- Validasi Nomor WhatsApp Wali (Fonnte API Optimization) -->
        <section class="rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] p-4 shadow-[var(--shadow)] space-y-3">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-2.5">
                    <span class="flex size-7.5 items-center justify-center rounded bg-sky-600 text-xs font-bold text-white shadow-xs">
                        <svg class="size-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                        </svg>
                    </span>
                    <div>
                        <h3 class="text-xs font-bold text-[var(--text)]">Validasi Nomor Wali (Fonnte)</h3>
                        <p class="text-[11px] text-[var(--text-secondary)] font-normal">Pemeriksaan Status Terdaftar WhatsApp</p>
                    </div>
                </div>
                <a href="{{ route('guardians.validation') }}"
                   class="text-xs font-semibold text-[var(--primary-accessible)] hover:underline">
                    Kelola Validasi
                </a>
            </div>

            <div class="grid grid-cols-4 gap-2 pt-2 border-t border-[var(--border-subtle)] text-center text-xs">
                <div class="p-2 rounded-[var(--radius-sm)] bg-emerald-50/70 border border-emerald-200/60">
                    <div class="text-[10px] text-emerald-700 uppercase font-semibold">Valid</div>
                    <div class="font-bold text-emerald-800 mt-0.5">{{ $guardianStats['valid'] }}</div>
                </div>
                <div class="p-2 rounded-[var(--radius-sm)] bg-amber-50/70 border border-amber-200/60">
                    <div class="text-[10px] text-amber-700 uppercase font-semibold">Pending</div>
                    <div class="font-bold text-amber-800 mt-0.5">{{ $guardianStats['pending'] }}</div>
                </div>
                <div class="p-2 rounded-[var(--radius-sm)] bg-rose-50/70 border border-rose-200/60">
                    <div class="text-[10px] text-rose-700 uppercase font-semibold">Invalid</div>
                    <div class="font-bold text-rose-800 mt-0.5">{{ $guardianStats['invalid'] }}</div>
                </div>
                <div class="p-2 rounded-[var(--radius-sm)] bg-slate-100 border border-slate-200">
                    <div class="text-[10px] text-slate-600 uppercase font-semibold">Unknown</div>
                    <div class="font-bold text-slate-800 mt-0.5">{{ $guardianStats['unknown'] }}</div>
                </div>
            </div>
        </section>
    </div>
    @endif

    <!-- Live Data Table: Presensi Hari Ini -->
    <section class="min-w-0 rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] shadow-[var(--shadow)] overflow-hidden">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--border-subtle)] px-4 py-3">
            <div>
                <h3 class="text-xs font-bold text-[var(--text)]">Presensi Siswa Hari Ini</h3>
                <p class="text-[11px] text-[var(--text-secondary)]">Record kehadiran hasil evaluasi jadwal dan toleransi</p>
            </div>
            <a href="{{ route('attendances.index', ['date' => $selectedDate]) }}" class="inline-flex items-center gap-1.5 px-3 py-1 rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface-secondary)] text-xs font-semibold text-[var(--text)] hover:bg-[var(--border-subtle)] transition-colors">
                <span>Buka Tabel Lengkap</span>
                <span>&rarr;</span>
            </a>
        </div>

        @php
            $showDashboardClassCol = empty($selectedClassId) && ! $isHomeroom;
        @endphp

        <div class="w-full overflow-x-auto">
            <table class="min-w-[700px] w-full text-left text-xs">
                <thead class="bg-[var(--surface-secondary)] text-[11px] font-semibold text-[var(--text-secondary)] uppercase tracking-wider border-b border-[var(--border-subtle)]">
                    <tr>
                        <th scope="col" class="px-4 py-2.5">Nama Siswa</th>
                        @if($showDashboardClassCol)
                            <th scope="col" class="px-4 py-2.5">Kelas</th>
                        @endif
                        <th scope="col" class="px-4 py-2.5 font-mono">Scan Masuk</th>
                        <th scope="col" class="px-4 py-2.5 font-mono">Scan Pulang</th>
                        <th scope="col" class="px-4 py-2.5">Status</th>
                        <th scope="col" class="px-4 py-2.5">Wali Murid</th>
                        <th scope="col" class="px-4 py-2.5">Status WA</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--border-subtle)] text-[13px]">
                    @forelse($attendances as $att)
                    @php
                        $studentName = $att->student?->name ?? 'Siswa tidak tersedia';
                        $studentNis = $att->student?->nis ?? '-';
                        $studentClass = $att->student?->schoolClass?->name ?? '-';
                        $guardian = $att->student?->guardians->first();
                    @endphp
                    <tr class="hover:bg-[var(--surface-secondary)]/50 transition-colors">
                        <td class="px-4 py-2.5">
                            <div class="font-semibold text-[var(--text)]">{{ $studentName }}</div>
                            <div class="text-[11px] text-[var(--text-muted)] font-mono">NIS: {{ $studentNis }}</div>
                        </td>

                        @if($showDashboardClassCol)
                            <td class="px-4 py-2.5 text-xs text-[var(--text-secondary)] font-medium">
                                {{ $studentClass }}
                            </td>
                        @endif

                        <td class="px-4 py-2.5 font-mono text-xs">
                            {{ $att->check_in_at ? $att->check_in_at->format('H:i:s') : '-' }}
                        </td>

                        <td class="px-4 py-2.5 font-mono text-xs">
                            @if($att->check_out_at)
                                <span class="font-semibold text-blue-700">{{ $att->check_out_at->format('H:i:s') }}</span>
                            @else
                                <span class="text-[var(--text-muted)] text-[11px]">Belum Pulang</span>
                            @endif
                        </td>

                        <td class="px-4 py-2.5">
                            @if($att->status === 'hadir')
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                    Tepat Waktu
                                </span>
                            @elseif($att->status === 'terlambat')
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-semibold bg-amber-50 text-amber-700 border border-amber-200">
                                    Terlambat
                                </span>
                            @elseif($att->status === 'pulang_cepat')
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-semibold bg-sky-50 text-sky-700 border border-sky-200">
                                    Pulang Cepat
                                </span>
                            @elseif($att->status === 'izin')
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-semibold bg-purple-50 text-purple-700 border border-purple-200">
                                    Izin
                                </span>
                            @elseif($att->status === 'sakit')
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-semibold bg-indigo-50 text-indigo-700 border border-indigo-200">
                                    Sakit
                                </span>
                            @elseif($att->status === 'perlu_tinjauan')
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-semibold bg-orange-50 text-orange-700 border border-orange-200">
                                    Perlu Tinjauan
                                </span>
                            @else
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-semibold bg-slate-100 text-slate-700 border border-slate-200">
                                    {{ ucfirst($att->status) }}
                                </span>
                            @endif
                        </td>

                        <td class="px-4 py-2.5 text-xs">
                            @if($guardian)
                                <div class="font-medium text-[var(--text)]">{{ $guardian->name }}</div>
                                <div class="text-[11px] font-mono text-[var(--text-muted)]">
                                    {{ filled($guardian->wa_number) ? '+'.$guardian->wa_number : 'Nomor WA belum diisi' }}
                                </div>
                            @else
                                <span class="text-[var(--text-muted)] italic">-</span>
                            @endif
                        </td>

                        <td class="px-4 py-2.5">
                            @if($att->notifications->count() > 0)
                                @php $notif = $att->notifications->first(); @endphp
                                <x-notification-status :status="$notif->status" />
                            @else
                                <span class="text-[var(--text-muted)] text-[11px]">-</span>
                            @endif
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="7" class="px-4 py-8 text-center text-xs text-[var(--text-muted)]">
                            Belum ada catatan presensi pada tanggal ini.
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    if (typeof Chart === 'undefined') return;

    // 1. Inisialisasi Grafik Tren 7 Hari Terakhir
    const trendCtx = document.getElementById('trendChart')?.getContext('2d');
    if (trendCtx) {
        new Chart(trendCtx, {
            type: 'bar',
            data: {
                labels: @json($trendChartData['labels']),
                datasets: [
                    {
                        label: 'Tepat Waktu',
                        data: @json($trendChartData['hadir']),
                        backgroundColor: '#10b981',
                        borderRadius: 4,
                        barPercentage: 0.7,
                        categoryPercentage: 0.8,
                    },
                    {
                        label: 'Terlambat',
                        data: @json($trendChartData['terlambat']),
                        backgroundColor: '#f59e0b',
                        borderRadius: 4,
                        barPercentage: 0.7,
                        categoryPercentage: 0.8,
                    },
                    {
                        label: 'Pulang Cepat',
                        data: @json($trendChartData['pulang_cepat']),
                        backgroundColor: '#0ea5e9',
                        borderRadius: 4,
                        barPercentage: 0.7,
                        categoryPercentage: 0.8,
                    },
                    {
                        label: 'Izin & Sakit',
                        data: @json($trendChartData['izin_sakit']),
                        backgroundColor: '#a855f7',
                        borderRadius: 4,
                        barPercentage: 0.7,
                        categoryPercentage: 0.8,
                    },
                    {
                        label: 'Alpa / Belum Hadir',
                        data: @json($trendChartData['alpa']),
                        backgroundColor: '#f43f5e',
                        borderRadius: 4,
                        barPercentage: 0.7,
                        categoryPercentage: 0.8,
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false,
                },
                plugins: {
                    legend: {
                        display: false,
                    },
                    tooltip: {
                        backgroundColor: '#0f172a',
                        titleColor: '#f8fafc',
                        bodyColor: '#f8fafc',
                        padding: 10,
                        cornerRadius: 6,
                        boxPadding: 4,
                    }
                },
                scales: {
                    x: {
                        stacked: true,
                        grid: {
                            display: false,
                        },
                        ticks: {
                            font: { size: 11 },
                            color: '#64748b'
                        }
                    },
                    y: {
                        stacked: true,
                        beginAtZero: true,
                        grid: {
                            color: 'rgba(148, 163, 184, 0.15)',
                        },
                        ticks: {
                            font: { size: 11 },
                            color: '#64748b',
                            precision: 0
                        }
                    }
                }
            }
        });
    }

    // 2. Inisialisasi Donut Chart Rasio Kehadiran Hari Ini
    const donutCtx = document.getElementById('donutChart')?.getContext('2d');
    if (donutCtx) {
        new Chart(donutCtx, {
            type: 'doughnut',
            data: {
                labels: @json($donutChartData['labels']),
                datasets: [{
                    data: @json($donutChartData['data']),
                    backgroundColor: [
                        '#10b981', // Tepat Waktu
                        '#f59e0b', // Terlambat
                        '#0ea5e9', // Pulang Cepat
                        '#a855f7', // Izin & Sakit
                        '#f43f5e'  // Belum Hadir
                    ],
                    borderWidth: 2,
                    borderColor: 'transparent',
                    hoverOffset: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '76%',
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        backgroundColor: '#0f172a',
                        titleColor: '#f8fafc',
                        bodyColor: '#f8fafc',
                        padding: 10,
                        cornerRadius: 6,
                    }
                }
            }
        });
    }
});
</script>
@endsection

