<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $reportData['title'] }} - {{ config('whatsapp.school_name', 'SMA Negeri 1') }}</title>
    <!-- Favicon -->
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <link rel="alternate icon" type="image/png" href="{{ asset('logo_smart_presensi.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('logo_smart_presensi.png') }}">

    <!-- Inter & JetBrains Mono Fonts (Identik dengan Aplikasi) -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        body, table, th, td, h1, h2, h3, p, span {
            font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif !important;
        }
        .font-mono {
            font-family: 'JetBrains Mono', ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace !important;
        }

        @page {
            size: {{ $type === 'daily' ? 'A4 landscape' : 'A4 portrait' }};
            margin: 6mm 10mm 12mm 10mm;
        }
        @media print {
            .no-print {
                display: none !important;
            }
            html, body {
                background: white !important;
                color: #0f172a !important;
                font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif !important;
                font-size: 8.5pt !important;
                line-height: 1.15 !important;
                margin: 0 !important;
                padding: 0 !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .print-container {
                display: block !important;
                min-height: auto !important;
                height: auto !important;
                box-shadow: none !important;
                border: none !important;
                padding: 0 !important;
                margin: 0 !important;
                width: 100% !important;
                max-width: 100% !important;
                border-radius: 0 !important;
                overflow: visible !important;
            }
            .report-header-section {
                page-break-inside: avoid !important;
                break-inside: avoid !important;
                page-break-after: avoid !important;
                break-after: avoid !important;
            }
            table {
                width: 100% !important;
                border-collapse: collapse !important;
            }
            th, td {
                border-color: #475569 !important;
            }

            /* Wajib 1 Halaman untuk Laporan Per Siswa dan Per Kelas */
            .single-page-sheet {
                page-break-inside: avoid !important;
                break-inside: avoid !important;
                page-break-before: avoid !important;
                break-before: avoid !important;
                page-break-after: avoid !important;
                break-after: avoid !important;
                overflow: visible !important;
            }
            .single-page-sheet table {
                page-break-inside: auto !important;
                break-inside: auto !important;
            }
            .single-page-sheet tr {
                page-break-inside: avoid !important;
                break-inside: avoid !important;
            }
            .single-page-sheet tfoot {
                display: none !important;
            }

            /* 1. Spesifik Laporan Per Siswa (Maksimal 31 Baris Hari - Format Lega & Elegan) */
            .sheet-student .report-header-section {
                margin-bottom: 16px !important;
                padding-bottom: 12px !important;
            }
            .sheet-student .report-header-title {
                font-size: 15pt !important;
                margin-top: 0 !important;
                margin-bottom: 20px !important;
            }
            .sheet-student .identity-master-table {
                margin-top: 16px !important;
                margin-bottom: 8px !important;
            }
            .sheet-student .identity-sub-table td {
                font-size: 10pt !important;
                padding-top: 2.5px !important;
                padding-bottom: 2.5px !important;
                line-height: 1.4 !important;
            }
            .sheet-student .detail-table th {
                font-size: 8.5pt !important;
                padding-top: 5px !important;
                padding-bottom: 5px !important;
                padding-left: 3px !important;
                padding-right: 3px !important;
                line-height: 1.35 !important;
            }
            .sheet-student .detail-table td {
                font-size: 8.5pt !important;
                padding-top: 4px !important;
                padding-bottom: 4px !important;
                padding-left: 3px !important;
                padding-right: 3px !important;
                line-height: 1.35 !important;
            }
            .sheet-student .detail-table td span {
                font-size: 8.5pt !important;
            }

            /* 2. Spesifik Laporan Per Kelas (32 - 40 Siswa per Kelas, Pas 1 Halaman Tanpa Terpotong) */
            .sheet-class .report-header-section {
                margin-bottom: 10px !important;
                padding-bottom: 8px !important;
            }
            .sheet-class .report-header-title {
                font-size: 13.5pt !important;
                margin-top: 0 !important;
                margin-bottom: 18px !important;
            }
            .sheet-class .identity-master-table {
                margin-top: 14px !important;
                margin-bottom: 6px !important;
            }
            .sheet-class .identity-sub-table td {
                font-size: 9.5pt !important;
                padding-top: 1.5px !important;
                padding-bottom: 1.5px !important;
                line-height: 1.3 !important;
            }
            .sheet-class .detail-table th {
                font-size: 8pt !important;
                padding-top: 4px !important;
                padding-bottom: 4px !important;
                padding-left: 2.5px !important;
                padding-right: 2.5px !important;
                line-height: 1.3 !important;
            }
            .sheet-class .detail-table td {
                font-size: 8pt !important;
                padding-top: 3px !important;
                padding-bottom: 3px !important;
                padding-left: 2.5px !important;
                padding-right: 2.5px !important;
                line-height: 1.3 !important;
            }
            .sheet-class .detail-table td span {
                font-size: 8pt !important;
            }

            /* Multi Halaman untuk Laporan Semua Siswa */
            .multi-page-sheet table {
                page-break-inside: auto !important;
            }
            .multi-page-sheet tr {
                page-break-inside: avoid !important;
            }
            .multi-page-sheet thead {
                display: table-header-group !important;
            }

            .report-header-title {
                font-size: 16pt !important;
                font-weight: 800 !important;
                letter-spacing: 0.5px !important;
                line-height: 1.25 !important;
                text-align: center !important;
                margin-top: 2px !important;
                margin-bottom: 16px !important;
            }
            .detail-table th {
                font-size: 7.5pt !important;
                padding-top: 4.5px !important;
                padding-bottom: 4.5px !important;
                line-height: 1.35 !important;
            }
            .detail-table td {
                font-size: 7.5pt !important;
                padding-top: 3.5px !important;
                padding-bottom: 3.5px !important;
                line-height: 1.35 !important;
            }
            .daily-table th {
                font-size: 8pt !important;
                padding-top: 5.5px !important;
                padding-bottom: 5.5px !important;
                line-height: 1.4 !important;
            }
            .daily-table td {
                font-size: 8pt !important;
                padding-top: 4.5px !important;
                padding-bottom: 4.5px !important;
                line-height: 1.4 !important;
            }
            .identity-master-table {
                width: 100% !important;
                border: none !important;
                margin-top: 14px !important;
                margin-bottom: 6px !important;
            }
            .identity-master-table td {
                border: none !important;
            }
            .identity-sub-table {
                width: auto !important;
                border: none !important;
            }
            .identity-sub-table td {
                border: none !important;
                font-size: 10pt !important;
                line-height: 1.35 !important;
                padding-top: 1.5px !important;
                padding-bottom: 1.5px !important;
            }
            .report-footer {
                position: fixed !important;
                bottom: 0 !important;
                left: 0 !important;
                right: 0 !important;
                width: 100% !important;
                background-color: white !important;
                margin: 0 !important;
                padding-top: 5px !important;
                padding-bottom: 2px !important;
                border-top: 1px solid #cbd5e1 !important;
                page-break-inside: avoid !important;
                z-index: 9999 !important;
            }
            .report-footer td {
                border: none !important;
                font-size: 7.5pt !important;
                color: #64748b !important;
            }
        }

        .report-header-title {
            font-size: 16pt;
            font-weight: 800;
            letter-spacing: 0.025em;
            text-align: center;
            margin-bottom: 18px;
        }
        .detail-table th {
            font-size: 8.5pt;
            padding-top: 6px;
            padding-bottom: 6px;
            line-height: 1.4;
        }
        .detail-table td {
            font-size: 8.5pt;
            padding-top: 5px;
            padding-bottom: 5px;
            line-height: 1.4;
        }
        .daily-table th {
            font-size: 8pt;
            padding-top: 7px;
            padding-bottom: 7px;
            line-height: 1.45;
        }
        .daily-table td {
            font-size: 8pt;
            padding-top: 6px;
            padding-bottom: 6px;
            line-height: 1.45;
        }
        .identity-master-table {
            border: none;
            width: 100%;
            margin-top: 14px;
        }
        .identity-master-table td {
            border: none;
        }
        .identity-sub-table {
            border: none;
            width: auto;
        }
        .identity-sub-table td {
            border: none;
            font-size: 10pt;
            line-height: 1.35;
            padding-top: 2px;
            padding-bottom: 2px;
        }
        .report-footer {
            margin-top: auto;
            padding-top: 10px;
            border-top: 1px solid #e2e8f0;
        }
        .report-footer td {
            border: none;
            font-size: 8pt;
            color: #64748b;
        }
    </style>
</head>
<body class="bg-slate-100 text-slate-800 font-sans antialiased min-h-screen py-5 px-4">

    {{-- Top Action Bar (Disembunyikan saat mencetak) --}}
    <div class="no-print max-w-4xl mx-auto mb-4 bg-white rounded-xl shadow-xs border border-slate-200 p-3.5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-sm font-bold text-slate-900">{{ $reportData['title'] }}</h1>
                <p class="text-xs text-slate-500">
                    Format cetak A4 {{ in_array($type, ['student', 'class'], true) ? 'standar 1 halaman' : 'multi-halaman' }} tanpa kop &amp; tanda tangan
                </p>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('attendances.reports.index', request()->query()) }}"
                   class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface)] text-xs font-semibold text-[var(--text)] hover:bg-[var(--surface-secondary)] transition-colors shadow-xs">
                    &larr; Kembali ke Laporan
                </a>
                <a href="{{ route('attendances.reports.export', request()->query()) }}"
                   class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-[var(--radius-sm)] bg-[var(--primary)] text-xs font-semibold text-white hover:brightness-90 transition-colors shadow-xs">
                    <svg class="size-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    <span>Ekspor Excel (.xlsx)</span>
                </a>
                <button type="button" onclick="window.print()"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface)] text-xs font-semibold text-[var(--text)] hover:bg-[var(--surface-secondary)] transition-colors shadow-xs cursor-pointer">
                    <svg class="size-3.5 text-rose-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                    <span>Cetak / Simpan PDF (A4)</span>
                </button>
            </div>
        </div>
    </div>

    {{-- Kertas Dokumen Resmi Ukuran A4 (Wajib 1 Halaman untuk student & class) --}}
    @php
        $isSinglePage = in_array($type, ['student', 'class'], true);
        $studentObj = $reportData['selectedStudent'] ?? null;
        $classObj = $reportData['selectedClass'] ?? ($studentObj?->schoolClass ?? null);

        $infoNama = $studentObj?->name ?? '-';
        $infoNis = $studentObj?->nis ?? '-';
        $infoKelas = $classObj?->name ?? 'Semua Kelas';
        $infoPeriode = $type === 'daily'
            ? \Carbon\Carbon::parse($reportData['date'], 'Asia/Jakarta')->locale('id')->isoFormat('D MMMM Y')
            : ($reportData['month_formatted'] ?? '-');

        $dailyScheduleIn = '-';
        $dailyScheduleOut = '-';
        if ($type === 'daily') {
            $parsedDay = \Carbon\Carbon::parse($reportData['date'], 'Asia/Jakarta');
            $sched = \App\Models\SchoolSchedule::where('day_of_week', $parsedDay->dayOfWeekIso)->where('is_active', true)->first();
            if ($sched) {
                $dailyScheduleIn = substr($sched->start_at, 0, 5);
                $dailyScheduleOut = substr($sched->end_at, 0, 5);
            }
        }
        $hasSpecificClass = $classObj !== null;
    @endphp

    <div class="print-container {{ $isSinglePage ? 'single-page-sheet' : 'multi-page-sheet' }} sheet-{{ $type }} max-w-4xl mx-auto bg-white rounded-xl shadow-md border border-slate-200 p-6 flex flex-col justify-between min-h-[297mm]">

        {{-- Header Bersih Laporan (Tanpa Kop Surat) --}}
        <div class="report-header-section mb-5 border-b border-slate-300 pb-4">
            <h1 class="report-header-title uppercase text-slate-900 mb-4">
                {{ $reportData['title'] }}
            </h1>

            {{-- Keterangan Identitas 2 Kolom Disesuaikan Berdasarkan Jenis Laporan --}}
            <table class="w-full border-none identity-master-table identity-table text-[10pt] mt-3.5">
                <tbody>
                    <tr>
                        @if($type === 'student')
                            {{-- 1. Laporan Per Siswa: Kiri (Nama & NIS), Kanan (Kelas & Periode) --}}
                            <td class="w-1/2 align-top border-none p-0 pr-4">
                                <table class="identity-sub-table text-[10pt] leading-normal text-slate-700 border-none">
                                    <tbody>
                                        <tr>
                                            <td class="font-medium text-slate-600 whitespace-nowrap border-none p-0 py-0.5 pr-2 w-[55px]">Nama</td>
                                            <td class="text-center font-bold text-slate-500 border-none p-0 py-0.5 px-1.5 w-[14px]">:</td>
                                            <td class="font-bold text-slate-900 whitespace-nowrap border-none p-0 py-0.5">{{ $studentObj?->name ?? '-' }}</td>
                                        </tr>
                                        <tr>
                                            <td class="font-medium text-slate-600 whitespace-nowrap border-none p-0 py-0.5 pr-2 w-[55px]">NIS</td>
                                            <td class="text-center font-bold text-slate-500 border-none p-0 py-0.5 px-1.5 w-[14px]">:</td>
                                            <td class="font-bold font-mono text-slate-900 whitespace-nowrap border-none p-0 py-0.5">{{ $studentObj?->nis ?? '-' }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </td>
                            <td class="w-1/2 align-top border-none p-0 pl-6">
                                <table class="identity-sub-table text-[10pt] leading-normal text-slate-700 border-none">
                                    <tbody>
                                        <tr>
                                            <td class="font-medium text-slate-600 whitespace-nowrap border-none p-0 py-0.5 pr-2 w-[65px]">Kelas</td>
                                            <td class="text-center font-bold text-slate-500 border-none p-0 py-0.5 px-1.5 w-[14px]">:</td>
                                            <td class="font-bold text-slate-900 whitespace-nowrap border-none p-0 py-0.5">{{ $classObj?->name ?? '-' }}</td>
                                        </tr>
                                        <tr>
                                            <td class="font-medium text-slate-600 whitespace-nowrap border-none p-0 py-0.5 pr-2 w-[65px]">Periode</td>
                                            <td class="text-center font-bold text-slate-500 border-none p-0 py-0.5 px-1.5 w-[14px]">:</td>
                                            <td class="font-bold text-slate-900 whitespace-nowrap border-none p-0 py-0.5">{{ $infoPeriode }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </td>

                        @elseif($type === 'class')
                            {{-- 2. Laporan Per Kelas: Kiri (Kelas & Tahun Ajaran), Kanan (Periode & Total Siswa) --}}
                            <td class="w-1/2 align-top border-none p-0 pr-4">
                                <table class="identity-sub-table text-[10pt] leading-normal text-slate-700 border-none">
                                    <tbody>
                                        <tr>
                                            <td class="font-medium text-slate-600 whitespace-nowrap border-none p-0 py-0.5 pr-2 w-[90px]">Kelas</td>
                                            <td class="text-center font-bold text-slate-500 border-none p-0 py-0.5 px-1.5 w-[14px]">:</td>
                                            <td class="font-bold text-slate-900 whitespace-nowrap border-none p-0 py-0.5">{{ $classObj?->name ?? '-' }}</td>
                                        </tr>
                                        <tr>
                                            <td class="font-medium text-slate-600 whitespace-nowrap border-none p-0 py-0.5 pr-2 w-[90px]">Tahun Ajaran</td>
                                            <td class="text-center font-bold text-slate-500 border-none p-0 py-0.5 px-1.5 w-[14px]">:</td>
                                            <td class="font-bold text-slate-900 whitespace-nowrap border-none p-0 py-0.5">{{ $classObj?->academic_year ?? '-' }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </td>
                            <td class="w-1/2 align-top border-none p-0 pl-6">
                                <table class="identity-sub-table text-[10pt] leading-normal text-slate-700 border-none">
                                    <tbody>
                                        <tr>
                                            <td class="font-medium text-slate-600 whitespace-nowrap border-none p-0 py-0.5 pr-2 w-[75px]">Periode</td>
                                            <td class="text-center font-bold text-slate-500 border-none p-0 py-0.5 px-1.5 w-[14px]">:</td>
                                            <td class="font-bold text-slate-900 whitespace-nowrap border-none p-0 py-0.5">{{ $infoPeriode }}</td>
                                        </tr>
                                        <tr>
                                            <td class="font-medium text-slate-600 whitespace-nowrap border-none p-0 py-0.5 pr-2 w-[75px]">Total Siswa</td>
                                            <td class="text-center font-bold text-slate-500 border-none p-0 py-0.5 px-1.5 w-[14px]">:</td>
                                            <td class="font-bold text-slate-900 whitespace-nowrap border-none p-0 py-0.5">{{ count($reportData['rows']) }} Siswa</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </td>

                        @elseif($type === 'monthly')
                            {{-- 3. Laporan Bulanan (Semua Kelas): Kiri (Periode & Lingkup), Kanan (Total Siswa & Hari Efektif) --}}
                            <td class="w-1/2 align-top border-none p-0 pr-4">
                                <table class="identity-sub-table text-[10pt] leading-normal text-slate-700 border-none">
                                    <tbody>
                                        <tr>
                                            <td class="font-medium text-slate-600 whitespace-nowrap border-none p-0 py-0.5 pr-2 w-[85px]">Periode</td>
                                            <td class="text-center font-bold text-slate-500 border-none p-0 py-0.5 px-1.5 w-[14px]">:</td>
                                            <td class="font-bold text-slate-900 whitespace-nowrap border-none p-0 py-0.5">{{ $infoPeriode }}</td>
                                        </tr>
                                        <tr>
                                            <td class="font-medium text-slate-600 whitespace-nowrap border-none p-0 py-0.5 pr-2 w-[85px]">Lingkup</td>
                                            <td class="text-center font-bold text-slate-500 border-none p-0 py-0.5 px-1.5 w-[14px]">:</td>
                                            <td class="font-bold text-slate-900 whitespace-nowrap border-none p-0 py-0.5">Semua Kelas</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </td>
                            <td class="w-1/2 align-top border-none p-0 pl-6">
                                <table class="identity-sub-table text-[10pt] leading-normal text-slate-700 border-none">
                                    <tbody>
                                        <tr>
                                            <td class="font-medium text-slate-600 whitespace-nowrap border-none p-0 py-0.5 pr-2 w-[90px]">Total Siswa</td>
                                            <td class="text-center font-bold text-slate-500 border-none p-0 py-0.5 px-1.5 w-[14px]">:</td>
                                            <td class="font-bold text-slate-900 whitespace-nowrap border-none p-0 py-0.5">{{ count($reportData['rows']) }} Siswa</td>
                                        </tr>
                                        <tr>
                                            <td class="font-medium text-slate-600 whitespace-nowrap border-none p-0 py-0.5 pr-2 w-[90px]">Hari Efektif</td>
                                            <td class="text-center font-bold text-slate-500 border-none p-0 py-0.5 px-1.5 w-[14px]">:</td>
                                            <td class="font-bold text-slate-900 whitespace-nowrap border-none p-0 py-0.5">{{ $reportData['summary']['effective_days'] ?? '-' }} Hari</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </td>

                        @else
                            {{-- 4. Laporan Harian: Kiri (Hari/Tanggal & Kelas), Kanan (Jadwal Masuk & Jadwal Pulang) --}}
                            <td class="w-1/2 align-top border-none p-0 pr-4">
                                <table class="identity-sub-table text-[10pt] leading-normal text-slate-700 border-none">
                                    <tbody>
                                        <tr>
                                            <td class="font-medium text-slate-600 whitespace-nowrap border-none p-0 py-0.5 pr-2 w-[90px]">Hari, Tanggal</td>
                                            <td class="text-center font-bold text-slate-500 border-none p-0 py-0.5 px-1.5 w-[14px]">:</td>
                                            <td class="font-bold text-slate-900 whitespace-nowrap border-none p-0 py-0.5">{{ \Carbon\Carbon::parse($reportData['date'], 'Asia/Jakarta')->locale('id')->isoFormat('dddd, D MMMM Y') }}</td>
                                        </tr>
                                        <tr>
                                            <td class="font-medium text-slate-600 whitespace-nowrap border-none p-0 py-0.5 pr-2 w-[90px]">Kelas</td>
                                            <td class="text-center font-bold text-slate-500 border-none p-0 py-0.5 px-1.5 w-[14px]">:</td>
                                            <td class="font-bold text-slate-900 whitespace-nowrap border-none p-0 py-0.5">{{ $classObj?->name ?? 'Semua Kelas' }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </td>
                            <td class="w-1/2 align-top border-none p-0 pl-6">
                                <table class="identity-sub-table text-[10pt] leading-normal text-slate-700 border-none">
                                    <tbody>
                                        <tr>
                                            <td class="font-medium text-slate-600 whitespace-nowrap border-none p-0 py-0.5 pr-2 w-[90px]">Jadwal Masuk</td>
                                            <td class="text-center font-bold text-slate-500 border-none p-0 py-0.5 px-1.5 w-[14px]">:</td>
                                            <td class="font-bold text-slate-900 whitespace-nowrap border-none p-0 py-0.5">{{ $dailyScheduleIn }} WIB</td>
                                        </tr>
                                        <tr>
                                            <td class="font-medium text-slate-600 whitespace-nowrap border-none p-0 py-0.5 pr-2 w-[90px]">Jadwal Pulang</td>
                                            <td class="text-center font-bold text-slate-500 border-none p-0 py-0.5 px-1.5 w-[14px]">:</td>
                                            <td class="font-bold text-slate-900 whitespace-nowrap border-none p-0 py-0.5">{{ $dailyScheduleOut }} WIB</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </td>
                        @endif
                    </tr>
                </tbody>
            </table>
        </div>

        {{-- Tabel Detail Data Laporan (Tanpa Kartu Ringkasan / Summary Card) --}}
        @if($reportData['total_rows'] === 0)
            <div class="p-8 text-center text-slate-400 border border-dashed border-slate-300 rounded my-4 text-xs">
                Tidak ada catatan absensi yang ditemukan untuk parameter laporan yang dipilih.
            </div>
        @else
            <div class="w-full">
                {{-- 1. Laporan Harian --}}
                @if($type === 'daily')
                    <table class="detail-table daily-table w-full text-left text-[8pt] border-collapse">
                        <thead>
                            <tr class="bg-slate-100 border-b border-slate-400 text-slate-900 font-bold uppercase text-[8pt]">
                                <th class="py-2 px-1.5 border border-slate-300 w-8 text-center">NO</th>
                                <th class="py-2 px-2 border border-slate-300 w-20">NIS</th>
                                <th class="py-2 px-2.5 border border-slate-300">NAMA SISWA</th>
                                @if(! $hasSpecificClass)
                                    <th class="py-2 px-2 border border-slate-300 w-24">KELAS</th>
                                @endif
                                <th class="py-2 px-2 border border-slate-300 text-center font-mono whitespace-nowrap">JADWAL MASUK</th>
                                <th class="py-2 px-2 border border-slate-300 text-center font-mono whitespace-nowrap">SCAN MASUK</th>
                                <th class="py-2 px-2 border border-slate-300 text-center font-mono leading-tight">TELAT (MENIT)</th>
                                <th class="py-2 px-2 border border-slate-300 text-center font-mono whitespace-nowrap">JADWAL PULANG</th>
                                <th class="py-2 px-2 border border-slate-300 text-center font-mono whitespace-nowrap">SCAN PULANG</th>
                                <th class="py-2 px-2 border border-slate-300 text-center font-mono leading-tight">PLG CEPAT (MENIT)</th>
                                <th class="py-2 px-2 border border-slate-300 w-20 text-center">STATUS</th>
                                <th class="py-2 px-2 border border-slate-300">KETERANGAN</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-300">
                            @foreach($reportData['rows'] as $r)
                                <tr class="hover:bg-slate-50">
                                    <td class="py-1.5 px-1.5 border border-slate-300 text-center font-mono text-slate-600">{{ $r['no'] }}</td>
                                    <td class="py-1.5 px-2 border border-slate-300 font-mono">{{ $r['nis'] }}</td>
                                    <td class="py-1.5 px-2.5 border border-slate-300 font-semibold text-slate-900">{{ $r['student_name'] }}</td>
                                    @if(! $hasSpecificClass)
                                        <td class="py-1.5 px-2 border border-slate-300 text-slate-700">{{ $r['class_name'] }}</td>
                                    @endif
                                    <td class="py-1.5 px-2 border border-slate-300 text-center font-mono whitespace-nowrap">{{ $dailyScheduleIn }}</td>
                                    <td class="py-1.5 px-2 border border-slate-300 text-center font-mono whitespace-nowrap">{{ $r['check_in_at'] }}</td>
                                    <td class="py-1.5 px-2 border border-slate-300 text-center font-mono">{{ $r['late_minutes_display'] ?? '-' }}</td>
                                    <td class="py-1.5 px-2 border border-slate-300 text-center font-mono whitespace-nowrap">{{ $dailyScheduleOut }}</td>
                                    <td class="py-1.5 px-2 border border-slate-300 text-center font-mono whitespace-nowrap">{{ $r['check_out_at'] }}</td>
                                    <td class="py-1.5 px-2 border border-slate-300 text-center font-mono">{{ $r['early_leave_minutes_display'] ?? '-' }}</td>
                                    <td class="py-1.5 px-2 border border-slate-300 text-center font-bold">
                                        @if($r['status'] === 'hadir')
                                            <span class="text-emerald-700">Hadir</span>
                                        @elseif($r['status'] === 'terlambat')
                                            <span class="text-amber-700">Terlambat</span>
                                        @elseif($r['status'] === 'pulang_cepat')
                                            <span class="text-sky-700">Pulang Cepat</span>
                                        @elseif($r['status'] === 'izin')
                                            <span class="text-blue-700">Izin</span>
                                        @elseif($r['status'] === 'sakit')
                                            <span class="text-cyan-700">Sakit</span>
                                        @elseif($r['status'] === 'alpa')
                                            <span class="text-rose-700">Alpa</span>
                                        @elseif($r['status'] === 'perlu_tinjauan')
                                            <span class="text-orange-700">Perlu Tinjauan</span>
                                        @else
                                            <span>{{ strtoupper($r['status']) }}</span>
                                        @endif
                                    </td>
                                    <td class="py-1.5 px-2 border border-slate-300 text-[8pt] text-slate-600">{{ $r['note'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="100" class="border-none p-0" style="border: none !important; height: 16px !important;"></td>
                            </tr>
                        </tfoot>
                    </table>

                {{-- 2. Laporan Bulanan (Semua Siswa) & Per Kelas (Wajib 1 Halaman A4 untuk Per Kelas) --}}
                @elseif($type === 'monthly' || $type === 'class')
                    <table class="detail-table w-full text-left text-[8.5pt] border-collapse">
                        <thead>
                            <tr class="bg-slate-100 border-b border-slate-400 text-slate-900 font-bold uppercase text-[8.5pt]">
                                <th class="py-1.5 px-1 border border-slate-300 w-7 text-center">NO</th>
                                <th class="py-1.5 px-1.5 border border-slate-300 w-16">NIS</th>
                                <th class="py-1.5 px-2 border border-slate-300">NAMA SISWA</th>
                                @if($type === 'monthly' && ! $hasSpecificClass)
                                    <th class="py-1.5 px-2 border border-slate-300 w-24">KELAS</th>
                                @endif
                                <th class="py-1.5 px-1 border border-slate-300 w-12 text-center text-emerald-800">HADIR</th>
                                <th class="py-1.5 px-1 border border-slate-300 w-12 text-center text-amber-800">TELAT</th>
                                <th class="py-1.5 px-1 border border-slate-300 w-12 text-center text-sky-800">PLG CEPAT</th>
                                <th class="py-1.5 px-1 border border-slate-300 w-10 text-center text-blue-800">IZIN</th>
                                <th class="py-1.5 px-1 border border-slate-300 w-10 text-center text-cyan-800">SAKIT</th>
                                <th class="py-1.5 px-1 border border-slate-300 w-10 text-center text-rose-800">ALPA</th>
                                <th class="py-1.5 px-1 border border-slate-300 w-12 text-center text-orange-800">PENDING</th>
                                <th class="py-1.5 px-1 border border-slate-300 w-12 text-center">TOTAL</th>
                                <th class="py-1.5 px-1 border border-slate-300 w-14 text-center">% HADIR</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-300">
                            @foreach($reportData['rows'] as $r)
                                <tr class="hover:bg-slate-50">
                                    <td class="py-1 px-1 border border-slate-300 text-center font-mono text-slate-600">{{ $r['no'] }}</td>
                                    <td class="py-1 px-1.5 border border-slate-300 font-mono">{{ $r['nis'] }}</td>
                                    <td class="py-1 px-2 border border-slate-300 font-semibold text-slate-900 truncate max-w-[200px]">{{ $r['student_name'] }}</td>
                                    @if($type === 'monthly' && ! $hasSpecificClass)
                                        <td class="py-1 px-2 border border-slate-300 text-slate-700">{{ $r['class_name'] }}</td>
                                    @endif
                                    <td class="py-1 px-1 border border-slate-300 text-center font-mono text-emerald-700 font-bold">{{ $r['hadir'] }}</td>
                                    <td class="py-1 px-1 border border-slate-300 text-center font-mono text-amber-700">{{ $r['terlambat'] }}</td>
                                    <td class="py-1 px-1 border border-slate-300 text-center font-mono text-sky-700">{{ $r['pulang_cepat'] }}</td>
                                    <td class="py-1 px-1 border border-slate-300 text-center font-mono text-blue-700">{{ $r['izin'] }}</td>
                                    <td class="py-1 px-1 border border-slate-300 text-center font-mono text-cyan-700">{{ $r['sakit'] }}</td>
                                    <td class="py-1 px-1 border border-slate-300 text-center font-mono text-rose-700">{{ $r['alpa'] }}</td>
                                    <td class="py-1 px-1 border border-slate-300 text-center font-mono text-orange-700">{{ $r['perlu_tinjauan'] ?? 0 }}</td>
                                    <td class="py-1 px-1 border border-slate-300 text-center font-mono font-bold">{{ $r['total_hadir'] }}</td>
                                    <td class="py-1 px-1 border border-slate-300 text-center font-mono font-bold {{ $r['percentage'] >= 85 ? 'text-emerald-700' : ($r['percentage'] >= 75 ? 'text-amber-700' : 'text-rose-700') }}">
                                        {{ $r['percentage'] }}%
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="100" class="border-none p-0" style="border: none !important; height: 16px !important;"></td>
                            </tr>
                        </tfoot>
                    </table>

                {{-- 3. Laporan Per Siswa (Wajib Pas 1 Halaman A4 untuk 28-31 Hari) --}}
                @elseif($type === 'student')
                    <table class="detail-table w-full text-left text-[8.5pt] border-collapse">
                        <thead>
                            <tr class="bg-slate-100 border-b border-slate-400 text-slate-900 font-bold uppercase text-[8.5pt]">
                                <th class="py-1.5 px-1.5 border border-slate-300 w-8 text-center">NO</th>
                                <th class="py-1.5 px-2 border border-slate-300 w-24">TANGGAL</th>
                                <th class="py-1.5 px-2 border border-slate-300 text-center font-mono whitespace-nowrap">JADWAL MASUK</th>
                                <th class="py-1.5 px-2 border border-slate-300 text-center font-mono whitespace-nowrap">SCAN MASUK</th>
                                <th class="py-1.5 px-2 border border-slate-300 text-center font-mono leading-tight">TELAT (MENIT)</th>
                                <th class="py-1.5 px-2 border border-slate-300 text-center font-mono whitespace-nowrap">JADWAL PULANG</th>
                                <th class="py-1.5 px-2 border border-slate-300 text-center font-mono whitespace-nowrap">SCAN PULANG</th>
                                <th class="py-1.5 px-2 border border-slate-300 text-center font-mono leading-tight">PLG CEPAT (MENIT)</th>
                                <th class="py-1.5 px-2 border border-slate-300">KETERANGAN</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-300">
                            @foreach($reportData['dailyRows'] as $r)
                                <tr class="{{ $r['is_holiday'] ? 'bg-slate-50/80 text-slate-500 italic' : 'hover:bg-slate-50' }}">
                                    <td class="py-1 px-1.5 border border-slate-300 text-center font-bold font-mono">{{ $loop->iteration }}</td>
                                    <td class="py-1 px-2 border border-slate-300 font-mono">{{ $r['date_formatted'] }}</td>
                                    <td class="py-1 px-2 border border-slate-300 text-center font-mono whitespace-nowrap">{{ $r['schedule_in'] }}</td>
                                    <td class="py-1 px-2 border border-slate-300 text-center font-mono whitespace-nowrap">{{ $r['check_in_at'] }}</td>
                                    <td class="py-1 px-2 border border-slate-300 text-center font-mono">{{ $r['late_minutes_display'] ?? '-' }}</td>
                                    <td class="py-1 px-2 border border-slate-300 text-center font-mono whitespace-nowrap">{{ $r['schedule_out'] }}</td>
                                    <td class="py-1 px-2 border border-slate-300 text-center font-mono whitespace-nowrap">{{ $r['check_out_at'] }}</td>
                                    <td class="py-1 px-2 border border-slate-300 text-center font-mono">{{ $r['early_leave_minutes_display'] ?? '-' }}</td>
                                    <td class="py-1 px-2 border border-slate-300 text-[8.5pt]">
                                        {{ $r['note'] ?: '-' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="100" class="border-none p-0" style="border: none !important; height: 16px !important;"></td>
                            </tr>
                        </tfoot>
                    </table>
                @endif
            </div>
        @endif

        {{-- Footer Informasi Cetak Dokumen Resmi --}}
        <div class="report-footer mt-4 pt-2 border-t border-slate-300 text-[8pt] text-slate-500">
            <table class="w-full border-none" style="border: none !important;">
                <tbody>
                    <tr>
                        <td class="text-left border-none p-0" style="border: none !important;">
                            <span>Waktu Cetak:</span>
                            <span class="font-semibold text-slate-700 ml-1">{{ \Carbon\Carbon::now('Asia/Jakarta')->locale('id')->isoFormat('dddd, D MMMM Y HH:mm:ss') }} WIB</span>
                        </td>
                        <td class="text-right border-none p-0" style="border: none !important;">
                            <span>Dicetak oleh:</span>
                            <span class="font-semibold text-slate-700 ml-1">{{ auth()->user()?->name ?? 'Administrator' }}</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

    </div>

</body>
</html>
