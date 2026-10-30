<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan Presensi Siswa - {{ $selectedStudent?->name ?? 'Siswa' }} ({{ $startDate->translatedFormat('F Y') }}).pdf</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        @page {
            size: A4 portrait;
            margin: 8mm 10mm 8mm 10mm;
        }
        @media print {
            .no-print {
                display: none !important;
            }
            html, body {
                background: white !important;
                color: #0f172a !important;
                font-size: 8.5pt !important;
                line-height: 1.15 !important;
                margin: 0 !important;
                padding: 0 !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .print-container {
                box-shadow: none !important;
                border: none !important;
                padding: 0 !important;
                margin: 0 !important;
                width: 100% !important;
                max-width: 100% !important;
                page-break-inside: avoid !important;
                break-inside: avoid !important;
                max-height: 278mm !important;
                overflow: hidden !important;
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
                page-break-inside: auto !important;
                break-inside: auto !important;
            }
            tr {
                page-break-inside: avoid !important;
                break-inside: avoid !important;
            }
            .report-header-title {
                font-size: 13.5pt !important;
                font-weight: 800 !important;
                letter-spacing: 0.5px !important;
                line-height: 1.25 !important;
                text-align: center !important;
            }
            .identity-master-table {
                width: 100% !important;
                border: none !important;
                margin-top: 6px !important;
                margin-bottom: 2px !important;
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
                font-size: 9pt !important;
                line-height: 1.35 !important;
                padding-top: 1px !important;
                padding-bottom: 1px !important;
            }
        }

        .report-header-title {
            font-size: 1.2rem;
            font-weight: 800;
            letter-spacing: 0.025em;
            text-align: center;
        }
        @media (min-width: 768px) {
            .report-header-title {
                font-size: 1.35rem;
            }
        }
        .identity-master-table {
            border: none;
            width: 100%;
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
            padding-top: 2px;
            padding-bottom: 2px;
        }
    </style>
</head>
<body class="bg-slate-100 text-slate-800 font-sans antialiased min-h-screen py-5 px-4">

    <!-- Top Action / Filter Bar (Disembunyikan saat Cetak) -->
    <div class="no-print max-w-4xl mx-auto mb-4 bg-white rounded-xl shadow-sm border border-slate-200 p-3.5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-sm font-bold text-slate-900">Cetak Laporan Presensi Harian Siswa</h1>
                <p class="text-xs text-slate-500">Format cetak A4 1 halaman tanpa kop dan tanpa tanda tangan</p>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('attendances.index') }}" class="px-3 py-1.5 rounded-lg border border-slate-200 hover:bg-slate-50 text-xs font-semibold text-slate-600 transition-colors">
                    &larr; Kembali
                </a>
                <a href="{{ route('attendances.report.student', ['student_id' => $selectedStudent?->id, 'month' => $month, 'format' => 'xlsx']) }}" class="px-3.5 py-1.5 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-xs font-semibold text-white transition-colors shadow-xs flex items-center gap-1.5">
                    <svg class="size-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    <span>Export Excel (.xlsx)</span>
                </a>
                <button type="button" onclick="window.print()" class="px-4 py-1.5 rounded-lg bg-rose-600 hover:bg-rose-700 text-xs font-semibold text-white transition-colors shadow-xs flex items-center gap-1.5 cursor-pointer">
                    <svg class="size-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                    <span>Cetak / Simpan PDF</span>
                </button>
            </div>
        </div>

        <!-- Filter Form -->
        <form method="GET" action="{{ route('attendances.report.student') }}" class="mt-3 pt-3 border-t border-slate-100 flex flex-wrap items-end gap-3 text-xs">
            <div>
                <label class="block font-semibold text-slate-700 mb-1">Pilih Siswa:</label>
                <select name="student_id" required class="h-9 rounded-lg border border-slate-300 bg-white px-3 text-xs focus:ring-1 focus:ring-emerald-500 min-w-[220px]">
                    @foreach($students as $st)
                    <option value="{{ $st->id }}" {{ $selectedStudent?->id == $st->id ? 'selected' : '' }}>
                        {{ $st->name }} ({{ $st->schoolClass?->name ?? 'Tanpa Kelas' }})
                    </option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="block font-semibold text-slate-700 mb-1">Pilih Periode Bulan:</label>
                <input type="month" name="month" value="{{ $month }}" required class="h-9 rounded-lg border border-slate-300 bg-white px-3 text-xs focus:ring-1 focus:ring-emerald-500">
            </div>

            <button type="submit" class="h-9 px-4 rounded-lg bg-slate-800 hover:bg-slate-900 text-white font-semibold transition-colors">
                Tampilkan Laporan
            </button>
        </form>
    </div>

    <!-- Printable Paper Sheet (Wajib 1 Halaman A4) -->
    <div class="print-container max-w-4xl mx-auto bg-white rounded-xl shadow-md border border-slate-200 p-6">

        @if($selectedStudent)
        <!-- Header Bersih (Tanpa Kop Surat) -->
        <div class="report-header-section mb-3.5 border-b border-slate-300 pb-3">
            <h1 class="report-header-title uppercase text-slate-900 mb-2.5">
                LAPORAN PRESENSI SISWA
            </h1>

            {{-- Keterangan 2 Kolom (Kiri: Nama & NIS, Kanan: Kelas & Periode) --}}
            <table class="w-full border-none identity-master-table">
                <tbody>
                    <tr>
                        {{-- Kolom Kiri: Nama & NIS --}}
                        <td class="w-1/2 align-top border-none p-0 pr-4">
                            <table class="identity-sub-table text-[9pt] md:text-[9.5pt] leading-normal text-slate-700 border-none">
                                <tbody>
                                    <tr>
                                        <td class="font-medium text-slate-600 whitespace-nowrap border-none p-0 py-0.5 pr-2 w-[55px]">Nama</td>
                                        <td class="text-center font-bold text-slate-500 border-none p-0 py-0.5 px-1.5 w-[14px]">:</td>
                                        <td class="font-bold text-slate-900 whitespace-nowrap border-none p-0 py-0.5">{{ $selectedStudent->name }}</td>
                                    </tr>
                                    <tr>
                                        <td class="font-medium text-slate-600 whitespace-nowrap border-none p-0 py-0.5 pr-2 w-[55px]">NIS</td>
                                        <td class="text-center font-bold text-slate-500 border-none p-0 py-0.5 px-1.5 w-[14px]">:</td>
                                        <td class="font-bold font-mono text-slate-900 whitespace-nowrap border-none p-0 py-0.5">{{ $selectedStudent->nis ?? '-' }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </td>

                        {{-- Kolom Kanan: Kelas & Periode --}}
                        <td class="w-1/2 align-top border-none p-0 pl-6">
                            <table class="identity-sub-table text-[9pt] md:text-[9.5pt] leading-normal text-slate-700 border-none">
                                <tbody>
                                    <tr>
                                        <td class="font-medium text-slate-600 whitespace-nowrap border-none p-0 py-0.5 pr-2 w-[65px]">Kelas</td>
                                        <td class="text-center font-bold text-slate-500 border-none p-0 py-0.5 px-1.5 w-[14px]">:</td>
                                        <td class="font-bold text-slate-900 whitespace-nowrap border-none p-0 py-0.5">{{ $selectedStudent->schoolClass?->name ?? '-' }}</td>
                                    </tr>
                                    <tr>
                                        <td class="font-medium text-slate-600 whitespace-nowrap border-none p-0 py-0.5 pr-2 w-[65px]">Periode</td>
                                        <td class="text-center font-bold text-slate-500 border-none p-0 py-0.5 px-1.5 w-[14px]">:</td>
                                        <td class="font-bold text-slate-900 whitespace-nowrap border-none p-0 py-0.5">{{ $startDate->locale('id')->isoFormat('MMMM Y') }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Tabel Rincian Harian (Ukuran Compact 8pt Wajib Pas 1 Halaman A4) -->
        <div class="border border-slate-400 rounded overflow-hidden">
            <table class="w-full text-left text-[8pt] border-collapse border border-slate-400">
                <thead>
                    <tr class="bg-slate-100 border-b border-slate-400 text-slate-900 font-bold uppercase text-[7.5pt] leading-tight">
                        <th class="py-1 px-1.5 border border-slate-300 w-8 text-center">TGL</th>
                        <th class="py-1 px-2 border border-slate-300 w-20">HARI</th>
                        <th class="py-1 px-1.5 border border-slate-300 w-20 text-center font-mono">JAM MASUK</th>
                        <th class="py-1 px-1.5 border border-slate-300 w-14 text-center font-mono">TELAT (MENIT)</th>
                        <th class="py-1 px-1.5 border border-slate-300 w-20 text-center font-mono">JAM PULANG</th>
                        <th class="py-1 px-1.5 border border-slate-300 w-16 text-center font-mono">PLG CEPAT (MENIT)</th>
                        <th class="py-1 px-2 border border-slate-300">KETERANGAN</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-300">
                    @foreach($dailyRows as $row)
                    <tr class="{{ $row['is_holiday'] ? 'bg-slate-50/80 text-slate-500 italic' : 'hover:bg-slate-50' }}">
                        <td class="py-[1.5px] px-1.5 border border-slate-300 text-center font-bold font-mono">{{ $row['day_number'] }}</td>
                        <td class="py-[1.5px] px-2 border border-slate-300 font-medium">{{ $row['day_name'] }}</td>
                        <td class="py-[1.5px] px-1.5 border border-slate-300 text-center font-mono">
                            {{ $row['attendance']?->check_in_at ? $row['attendance']->check_in_at->format('H:i') : '-' }}
                        </td>
                        <td class="py-[1.5px] px-1.5 border border-slate-300 text-center font-mono">{{ $row['late_minutes_display'] ?? '-' }}</td>
                        <td class="py-[1.5px] px-1.5 border border-slate-300 text-center font-mono">
                            {{ $row['attendance']?->check_out_at ? $row['attendance']->check_out_at->format('H:i') : '-' }}
                        </td>
                        <td class="py-[1.5px] px-1.5 border border-slate-300 text-center font-mono">{{ $row['early_leave_minutes_display'] ?? '-' }}</td>
                        <td class="py-[1.5px] px-2 border border-slate-300 text-[7.5pt]">
                            {{ $row['note'] ?: '-' }}
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @else
        <div class="p-8 text-center text-slate-400 text-xs">
            Silakan pilih siswa pada filter di atas untuk melihat laporan.
        </div>
        @endif

    </div>

</body>
</html>
