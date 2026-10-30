@extends('layouts.app')

@section('title', 'Laporan Presensi')
@section('page-pretitle', 'Laporan & Rekapitulasi')
@section('page-title', 'Laporan Presensi Siswa')
@section('page-subtitle', 'Pusat laporan kehadiran siswa resmi: Harian, Bulanan, Per Siswa, dan Per Kelas')

@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="transition-colors hover:text-[var(--text)]">Beranda</a>
    <span aria-hidden="true">/</span>
    <span>Laporan &amp; Rekapitulasi</span>
    <span aria-hidden="true">/</span>
    <span class="font-medium text-[var(--text)]" aria-current="page">Laporan Presensi</span>
@endsection

@section('content')
<div class="space-y-4">

    {{-- Tab Pilihan Jenis Laporan (pola tabs-pill Gentelella dalam Card bg) --}}
    <div class="rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] p-2.5 shadow-[var(--shadow)] flex flex-wrap items-center justify-between gap-3 text-xs">
        <div class="overflow-x-auto w-full sm:w-auto">
            <div class="tabs-pill" role="tablist" aria-label="Jenis laporan presensi">
                <a role="tab" aria-selected="{{ $type === 'daily' ? 'true' : 'false' }}"
                   href="{{ route('attendances.reports.index', array_merge(request()->query(), ['type' => 'daily'])) }}"
                   class="tab inline-flex items-center gap-2 {{ $type === 'daily' ? 'active' : '' }}">
                    <svg class="size-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                    <span>Laporan Harian</span>
                </a>

                @if(! $isHomeroom)
                    <a role="tab" aria-selected="{{ $type === 'monthly' ? 'true' : 'false' }}"
                       href="{{ route('attendances.reports.index', array_merge(request()->query(), ['type' => 'monthly'])) }}"
                       class="tab inline-flex items-center gap-2 {{ $type === 'monthly' ? 'active' : '' }}">
                        <svg class="size-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                        <span>Laporan Bulanan</span>
                    </a>
                @endif

                <a role="tab" aria-selected="{{ $type === 'class' ? 'true' : 'false' }}"
                   href="{{ route('attendances.reports.index', array_merge(request()->query(), ['type' => 'class'])) }}"
                   class="tab inline-flex items-center gap-2 {{ $type === 'class' ? 'active' : '' }}">
                    <svg class="size-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
                    <span>{{ $isHomeroom ? 'Rekap Bulanan Kelas' : 'Laporan Per Kelas' }}</span>
                </a>

                <a role="tab" aria-selected="{{ $type === 'student' ? 'true' : 'false' }}"
                   href="{{ route('attendances.reports.index', array_merge(request()->query(), ['type' => 'student'])) }}"
                   class="tab inline-flex items-center gap-2 {{ $type === 'student' ? 'active' : '' }}">
                    <svg class="size-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                    <span>Laporan Per Siswa</span>
                </a>
            </div>
        </div>
    </div>

    {{-- Filter compact; dipindah ke toolbar DataTables oleh tables.js
         (dropdown jumlah baris + filter di kiri, kotak pencarian di kanan).
         Tetap form GET biasa bila JS mati. --}}
    <form id="report-filter" method="GET" action="{{ route('attendances.reports.index') }}"
          class="flex flex-wrap items-center gap-2 text-xs">
        <input type="hidden" name="type" value="{{ $type }}">

        {{-- Periode: Tanggal (Harian) atau Bulan (lainnya) --}}
        @if($type === 'daily')
            <div class="flex h-8.5 items-center gap-1.5 rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface-secondary)] px-2.5">
                <span class="text-[var(--text-secondary)] font-medium whitespace-nowrap">Tanggal:</span>
                <input type="date" name="date" value="{{ $date }}" required
                       onchange="this.value && this.form.submit()"
                       class="bg-transparent font-medium text-[var(--text)] focus:outline-none cursor-pointer text-xs">
            </div>
        @else
            <div class="flex h-8.5 items-center gap-1.5 rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface-secondary)] px-2.5">
                <span class="text-[var(--text-secondary)] font-medium whitespace-nowrap">Periode:</span>
                <input type="month" name="month" value="{{ $month }}" required
                       onchange="this.value && this.form.submit()"
                       class="bg-transparent font-medium text-[var(--text)] focus:outline-none cursor-pointer text-xs">
            </div>
        @endif

        {{-- Kelas: Admin/Operator bebas filter (kecuali bulanan rekap seluruh sekolah); Wali Kelas otomatis bersih dengan badge penanda --}}
        @if(! $isHomeroom && $type !== 'monthly')
            <select name="class_id" {{ $type === 'class' ? 'required' : '' }}
                    onchange="this.form.submit()"
                    class="h-8.5 min-w-[150px] rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface-secondary)] px-2.5 text-xs text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none cursor-pointer">
                @if($type !== 'class')
                    <option value="">Semua Kelas</option>
                @endif
                @foreach($classes as $c)
                    <option value="{{ $c->id }}" {{ (string)$classId === (string)$c->id ? 'selected' : '' }}>
                        {{ $c->name }} ({{ $c->academic_year }})
                    </option>
                @endforeach
            </select>
        @elseif($isHomeroom && $classes->isNotEmpty())
            <div class="flex h-8.5 items-center gap-1.5 rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface-secondary)] px-2.5 text-xs">
                <span class="text-[var(--text-secondary)] font-medium">Kelas:</span>
                <span class="font-bold text-[var(--text)]">{{ $classes->first()->name }}</span>
            </div>
        @endif

        {{-- Siswa (Hanya untuk Laporan Per Siswa) --}}
        @if($type === 'student')
            <select name="student_id" required
                    onchange="this.form.submit()"
                    class="h-8.5 min-w-[200px] rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface-secondary)] px-2.5 text-xs text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none cursor-pointer">
                @foreach($students as $st)
                    <option value="{{ $st->id }}" {{ (string)$studentId === (string)$st->id ? 'selected' : '' }}>
                        {{ $st->name }} ({{ $st->schoolClass?->name ?? 'Tanpa Kelas' }})
                    </option>
                @endforeach
            </select>
        @endif

        {{-- Status (Harian) --}}
        @if($type === 'daily')
            <select name="status"
                    onchange="this.form.submit()"
                    class="h-8.5 rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface-secondary)] px-2.5 text-xs text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none cursor-pointer">
                <option value="">Semua Status</option>
                <option value="hadir" {{ $status === 'hadir' ? 'selected' : '' }}>Tepat Waktu (Hadir)</option>
                <option value="terlambat" {{ $status === 'terlambat' ? 'selected' : '' }}>Terlambat</option>
                <option value="pulang_cepat" {{ $status === 'pulang_cepat' ? 'selected' : '' }}>Pulang Cepat</option>
                <option value="izin" {{ $status === 'izin' ? 'selected' : '' }}>Izin</option>
                <option value="sakit" {{ $status === 'sakit' ? 'selected' : '' }}>Sakit</option>
                <option value="alpa" {{ $status === 'alpa' ? 'selected' : '' }}>Alpa</option>
                <option value="perlu_tinjauan" {{ $status === 'perlu_tinjauan' ? 'selected' : '' }}>Perlu Tinjauan</option>
            </select>
        @endif

        @if($hasActiveFilter)
            <a href="{{ route('attendances.reports.index', ['type' => $type]) }}"
               class="inline-flex h-8.5 items-center justify-center rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface)] px-3 text-xs font-medium text-[var(--text-secondary)] hover:bg-[var(--surface-secondary)] transition-colors">
                Reset
            </a>
        @endif
    </form>

    {{-- Ringkasan Kartu Statistik (Summary Cards) --}}
    @php
        $sum = $reportData['summary'];
    @endphp
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
        {{-- Total Subjek / Hari Efektif --}}
        <div class="rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] p-3">
            <span class="text-[11px] font-medium text-[var(--text-muted)]">
                {{ $type === 'student' ? 'Hari Efektif' : 'Total Siswa' }}
            </span>
            <p class="text-lg font-bold text-[var(--text)] mt-0.5">
                {{ $type === 'student' ? ($sum['total_effective'] ?? 0).' Hari' : ($sum['total_students'] ?? 0) }}
            </p>
        </div>

        {{-- Tepat Waktu (Hadir) --}}
        <div class="rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] p-3">
            <span class="text-[11px] font-medium text-[var(--text-muted)]">Tepat Waktu</span>
            <p class="text-lg font-bold text-[var(--text)] mt-0.5">{{ $sum['hadir'] ?? 0 }}</p>
        </div>

        {{-- Terlambat / Pulang Cepat --}}
        <div class="rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] p-3">
            <span class="text-[11px] font-medium text-[var(--text-muted)]">Terlambat / Pulang Cepat</span>
            <p class="text-lg font-bold text-[var(--text)] mt-0.5">
                {{ ($sum['terlambat'] ?? 0) + ($sum['pulang_cepat'] ?? 0) }}
                <span class="text-[10px] text-[var(--text-muted)] font-normal">({{ $sum['terlambat'] ?? 0 }} T / {{ $sum['pulang_cepat'] ?? 0 }} PC)</span>
            </p>
        </div>

        {{-- Izin / Sakit --}}
        <div class="rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] p-3">
            <span class="text-[11px] font-medium text-[var(--text-muted)]">Izin / Sakit</span>
            <p class="text-lg font-bold text-[var(--text)] mt-0.5">
                {{ ($sum['izin'] ?? 0) + ($sum['sakit'] ?? 0) }}
                <span class="text-[10px] text-[var(--text-muted)] font-normal">({{ $sum['izin'] ?? 0 }} I / {{ $sum['sakit'] ?? 0 }} S)</span>
            </p>
        </div>

        {{-- Alpa --}}
        <div class="rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] p-3">
            <span class="text-[11px] font-medium text-[var(--text-muted)]">Alpa (Tanpa Ket.)</span>
            <p class="text-lg font-bold text-[var(--text)] mt-0.5">{{ $sum['alpa'] ?? 0 }}</p>
            @if(($sum['perlu_tinjauan'] ?? 0) > 0)
                <p class="text-[10px] text-orange-600 mt-0.5">{{ $sum['perlu_tinjauan'] }} perlu tinjauan</p>
            @endif
        </div>

        {{-- Persentase Kehadiran --}}
        <div class="rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] p-3">
            <span class="text-[11px] font-medium text-[var(--text-muted)]">% Kehadiran</span>
            <p class="text-lg font-bold text-[var(--text)] mt-0.5">{{ $sum['percentage'] ?? 0 }}%</p>
        </div>
    </div>

    {{-- Tabel Preview Laporan --}}
    <section class="min-w-0 rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] shadow-[var(--shadow)] overflow-hidden">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--border-subtle)] px-4 py-3">
            <div>
                <h2 class="text-xs font-bold text-[var(--text)] uppercase tracking-wide">
                    {{ $reportData['title'] }}
                </h2>
                <p class="text-[11px] text-[var(--text-muted)] mt-0.5">
                    @if($type === 'daily')
                        Tanggal: <strong>{{ $reportData['date_formatted'] }}</strong>
                        @if($reportData['selectedClass']) &bull; Kelas: {{ $reportData['selectedClass']->name }} @endif
                    @elseif($type === 'student')
                        Siswa: <strong>{{ $reportData['selectedStudent']?->name ?? 'Siswa' }}</strong>
                        ({{ $reportData['selectedStudent']?->schoolClass?->name ?? '-' }}) &bull; Periode: {{ $reportData['month_formatted'] }}
                    @elseif($type === 'class')
                        Kelas: <strong>{{ $reportData['selectedClass']?->name ?? 'Kelas' }}</strong> &bull; Periode: {{ $reportData['month_formatted'] }}
                    @else
                        Periode: <strong>{{ $reportData['month_formatted'] }}</strong>
                        @if($reportData['selectedClass']) &bull; Kelas: {{ $reportData['selectedClass']->name }} @endif
                    @endif
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                {{-- Cetak / PDF A4 --}}
                <a href="{{ route('attendances.reports.print', array_merge(request()->query(), $activeReportParams)) }}" target="_blank"
                   class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface)] text-xs font-semibold text-[var(--text)] hover:bg-[var(--surface-secondary)] transition-colors shadow-xs">
                    <svg class="size-3.5 text-rose-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
                    </svg>
                    <span>Cetak / PDF (A4)</span>
                </a>

                {{-- Ekspor Excel --}}
                <a href="{{ route('attendances.reports.export', array_merge(request()->query(), $activeReportParams)) }}"
                   class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-[var(--radius-sm)] bg-[var(--primary)] text-xs font-semibold text-white hover:brightness-90 transition-colors shadow-xs">
                    <svg class="size-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                    <span>Ekspor Excel (.xlsx)</span>
                </a>
            </div>
        </div>

        {{-- Isi Tabel Preview Berdasarkan Tipe (selalu dirender; DataTables
             akan menampilkan pesan kosong bila tidak ada baris) --}}
        <div class="overflow-x-auto">
            {{-- 1. Tabel Laporan Harian --}}
            @if($type === 'daily')
                @php
                    $showDailyClassCol = empty($classId) && ! $isHomeroom;
                @endphp
                <table class="min-w-[1100px] w-full text-left text-xs" data-datatable data-page-length="25" data-length-change="true" data-toolbar-filter="#report-filter" data-empty-message="Tidak Ada Data Presensi - belum ada catatan absensi sesuai filter">
                        <thead class="bg-[var(--surface-secondary)] text-[11px] font-semibold text-[var(--text-secondary)] uppercase tracking-wider border-b border-[var(--border)]">
                            <tr>
                                <th class="py-2.5 px-3 text-center w-12">No</th>
                                <th class="py-2.5 px-3 w-28">NIS</th>
                                <th class="py-2.5 px-3">Nama Siswa</th>
                                @if($showDailyClassCol)
                                    <th class="py-2.5 px-3 w-28">Kelas</th>
                                @endif
                                <th class="py-2.5 px-3 w-24 text-center whitespace-nowrap">Scan Masuk</th>
                                <th class="py-2.5 px-3 w-24 text-center whitespace-nowrap">Telat (Menit)</th>
                                <th class="py-2.5 px-3 w-24 text-center whitespace-nowrap">Scan Pulang</th>
                                <th class="py-2.5 px-3 w-24 text-center whitespace-nowrap">PLG Cepat (Menit)</th>
                                <th class="py-2.5 px-3 w-28 text-center">Status</th>
                                <th class="py-2.5 px-3">Keterangan</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[var(--border)]">
                            @foreach($reportData['rows'] as $r)
                                <tr class="hover:bg-[var(--surface-secondary)]/50 transition-colors">
                                    <td class="py-2 px-3 text-center font-mono text-[var(--text-muted)]">{{ $r['no'] }}</td>
                                    <td class="py-2 px-3 font-mono text-[var(--text)]">{{ $r['nis'] }}</td>
                                    <td class="py-2 px-3 font-semibold text-[var(--text)]">{{ $r['student_name'] }}</td>
                                    @if($showDailyClassCol)
                                        <td class="py-2 px-3 text-[var(--text-secondary)]">{{ $r['class_name'] }}</td>
                                    @endif
                                    <td class="py-2 px-3 text-center font-mono">{{ $r['check_in_at'] }}</td>
                                    <td class="py-2 px-3 text-center font-mono">{{ $r['late_minutes_display'] ?? '-' }}</td>
                                    <td class="py-2 px-3 text-center font-mono">{{ $r['check_out_at'] }}</td>
                                    <td class="py-2 px-3 text-center font-mono">{{ $r['early_leave_minutes_display'] ?? '-' }}</td>
                                    <td class="py-2 px-3 text-center">
                                        @if($r['status'] === 'hadir')
                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-semibold bg-emerald-500/15 text-emerald-600 dark:text-emerald-400">Tepat Waktu</span>
                                        @elseif($r['status'] === 'terlambat')
                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-semibold bg-amber-500/15 text-amber-600 dark:text-amber-400">Terlambat</span>
                                        @elseif($r['status'] === 'pulang_cepat')
                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-semibold bg-sky-500/15 text-sky-600 dark:text-sky-400">Pulang Cepat</span>
                                        @elseif($r['status'] === 'izin')
                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-semibold bg-blue-500/15 text-blue-600 dark:text-blue-400">Izin</span>
                                        @elseif($r['status'] === 'sakit')
                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-semibold bg-cyan-500/15 text-cyan-600 dark:text-cyan-400">Sakit</span>
                                        @elseif($r['status'] === 'alpa')
                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-semibold bg-rose-500/15 text-rose-600 dark:text-rose-400">Alpa</span>
                                        @elseif($r['status'] === 'perlu_tinjauan')
                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-semibold bg-orange-500/15 text-orange-600 dark:text-orange-400">Perlu Tinjauan</span>
                                        @else
                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-semibold bg-slate-500/15 text-slate-500">{{ strtoupper($r['status']) }}</span>
                                        @endif
                                    </td>
                                    <td class="py-2 px-3 text-[11px] text-[var(--text-secondary)]">{{ $r['note'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>

                {{-- 2. Tabel Laporan Bulanan & Per Kelas --}}
                @elseif($type === 'monthly' || $type === 'class')
                    @php
                        $showMonthlyClassCol = $type === 'monthly' && empty($classId) && ! $isHomeroom;
                    @endphp
                    <table class="min-w-[800px] w-full text-left text-xs" data-datatable data-page-length="25" data-length-change="true" data-toolbar-filter="#report-filter" data-empty-message="Tidak Ada Data Presensi - belum ada catatan absensi sesuai filter">
                        <thead class="bg-[var(--surface-secondary)] text-[11px] font-semibold text-[var(--text-secondary)] uppercase tracking-wider border-b border-[var(--border)]">
                            <tr>
                                <th class="py-2.5 px-3 text-center w-12">No</th>
                                <th class="py-2.5 px-3 w-24">NIS</th>
                                <th class="py-2.5 px-3">Nama Siswa</th>
                                @if($showMonthlyClassCol)
                                    <th class="py-2.5 px-3 w-28">Kelas</th>
                                @endif
                                <th class="py-2.5 px-2 text-center w-16 text-emerald-600">Hadir</th>
                                <th class="py-2.5 px-2 text-center w-16 text-amber-600">Telat</th>
                                <th class="py-2.5 px-2 text-center w-16 text-sky-600">Plg Cepat</th>
                                <th class="py-2.5 px-2 text-center w-14 text-blue-600">Izin</th>
                                <th class="py-2.5 px-2 text-center w-14 text-cyan-600">Sakit</th>
                                <th class="py-2.5 px-2 text-center w-14 text-rose-600">Alpa</th>
                                <th class="py-2.5 px-2 text-center w-16 text-orange-600">Pending</th>
                                <th class="py-2.5 px-3 text-center w-20">Total H</th>
                                <th class="py-2.5 px-3 text-center w-20">% Hadir</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[var(--border)]">
                            @foreach($reportData['rows'] as $r)
                                <tr class="hover:bg-[var(--surface-secondary)]/50 transition-colors">
                                    <td class="py-2 px-3 text-center font-mono text-[var(--text-muted)]">{{ $r['no'] }}</td>
                                    <td class="py-2 px-3 font-mono text-[var(--text)]">{{ $r['nis'] }}</td>
                                    <td class="py-2 px-3 font-semibold text-[var(--text)]">{{ $r['student_name'] }}</td>
                                    @if($showMonthlyClassCol)
                                        <td class="py-2 px-3 text-[var(--text-secondary)]">{{ $r['class_name'] }}</td>
                                    @endif
                                    <td class="py-2 px-2 text-center font-mono text-emerald-600 font-semibold">{{ $r['hadir'] }}</td>
                                    <td class="py-2 px-2 text-center font-mono text-amber-600">{{ $r['terlambat'] }}</td>
                                    <td class="py-2 px-2 text-center font-mono text-sky-600">{{ $r['pulang_cepat'] }}</td>
                                    <td class="py-2 px-2 text-center font-mono text-blue-600">{{ $r['izin'] }}</td>
                                    <td class="py-2 px-2 text-center font-mono text-cyan-600">{{ $r['sakit'] }}</td>
                                    <td class="py-2 px-2 text-center font-mono text-rose-600">{{ $r['alpa'] }}</td>
                                    <td class="py-2 px-2 text-center font-mono text-orange-600">{{ $r['perlu_tinjauan'] ?? 0 }}</td>
                                    <td class="py-2 px-3 text-center font-mono font-bold">{{ $r['total_hadir'] }}</td>
                                    <td data-order="{{ $r['percentage'] }}" class="py-2 px-3 text-center font-mono font-bold {{ $r['percentage'] >= 85 ? 'text-emerald-600' : ($r['percentage'] >= 75 ? 'text-amber-600' : 'text-rose-600') }}">
                                        {{ $r['percentage'] }}%
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>

                {{-- 3. Tabel Laporan Per Siswa --}}
                @elseif($type === 'student')
                    <table class="min-w-[1000px] w-full text-left text-xs" data-datatable data-searching="false" data-page-length="31" data-length-change="false" data-toolbar-filter="#report-filter" data-empty-message="Tidak Ada Data Presensi - belum ada catatan absensi sesuai filter">
                        <thead class="bg-[var(--surface-secondary)] text-[11px] font-semibold text-[var(--text-secondary)] uppercase tracking-wider border-b border-[var(--border)]">
                            <tr>
                                <th class="py-2.5 px-3 text-center w-12">No</th>
                                <th class="py-2.5 px-3 w-28">Tanggal</th>
                                <th class="py-2.5 px-3 w-28 text-center whitespace-nowrap">Jadwal Masuk</th>
                                <th class="py-2.5 px-3 w-24 text-center whitespace-nowrap">Scan Masuk</th>
                                <th class="py-2.5 px-3 w-20 text-center whitespace-nowrap">Telat (Menit)</th>
                                <th class="py-2.5 px-3 w-28 text-center whitespace-nowrap">Jadwal Pulang</th>
                                <th class="py-2.5 px-3 w-24 text-center whitespace-nowrap">Scan Pulang</th>
                                <th class="py-2.5 px-3 w-24 text-center whitespace-nowrap">PLG Cepat (Menit)</th>
                                <th class="py-2.5 px-3">Keterangan</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[var(--border)]">
                            @foreach($reportData['dailyRows'] as $r)
                                <tr class="{{ $r['is_holiday'] ? 'bg-[var(--surface-secondary)]/70 text-[var(--text-muted)] italic' : 'hover:bg-[var(--surface-secondary)]/50' }} transition-colors">
                                    <td class="py-2 px-3 text-center font-mono text-[var(--text-muted)]">{{ $loop->iteration }}</td>
                                    <td class="py-2 px-3 font-mono">{{ $r['date_formatted'] }}</td>
                                    <td class="py-2 px-3 text-center font-mono text-[var(--text-secondary)]">{{ $r['schedule_in'] }}</td>
                                    <td class="py-2 px-3 text-center font-mono">{{ $r['check_in_at'] }}</td>
                                    <td class="py-2 px-3 text-center font-mono">{{ $r['late_minutes_display'] ?? '-' }}</td>
                                    <td class="py-2 px-3 text-center font-mono text-[var(--text-secondary)]">{{ $r['schedule_out'] }}</td>
                                    <td class="py-2 px-3 text-center font-mono">{{ $r['check_out_at'] }}</td>
                                    <td class="py-2 px-3 text-center font-mono">{{ $r['early_leave_minutes_display'] ?? '-' }}</td>
                                    <td class="py-2 px-3 text-[11px]">
                                        {{ $r['note'] ?: '-' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
            @endif
        </div>
    </section>

</div>
@endsection
