<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rekap Presensi Kelas {{ $selectedClass?->name ?? 'Kelas' }} - {{ $startDate->translatedFormat('F Y') }}.pdf</title>
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
            th, td {
                border-color: #475569 !important;
            }
        }
    </style>
</head>
<body class="bg-slate-100 text-slate-800 font-sans antialiased min-h-screen py-5 px-4">

    <!-- Top Action / Filter Bar (Disembunyikan saat Cetak) -->
    <div class="no-print max-w-4xl mx-auto mb-4 bg-white rounded-xl shadow-sm border border-slate-200 p-3.5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-sm font-bold text-slate-900">Rekapitulasi Presensi Bulanan Per Kelas</h1>
                <p class="text-xs text-slate-500">Format cetak A4 1 halaman tanpa kop dan tanpa tanda tangan</p>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('attendances.index') }}" class="px-3 py-1.5 rounded-lg border border-slate-200 hover:bg-slate-50 text-xs font-semibold text-slate-600 transition-colors">
                    &larr; Kembali
                </a>
                <a href="{{ route('attendances.report.class', ['class_id' => $selectedClass?->id, 'month' => $month, 'format' => 'xlsx']) }}" class="px-3.5 py-1.5 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-xs font-semibold text-white transition-colors shadow-xs flex items-center gap-1.5">
                    <svg class="size-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    <span>Export Excel (.xlsx)</span>
                </a>
                <button type="button" onclick="window.print()" class="px-4 py-1.5 rounded-lg bg-indigo-600 hover:bg-indigo-700 text-xs font-semibold text-white transition-colors shadow-xs flex items-center gap-1.5 cursor-pointer">
                    <svg class="size-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                    <span>Cetak / Simpan PDF</span>
                </button>
            </div>
        </div>

        <!-- Filter Form -->
        <form method="GET" action="{{ route('attendances.report.class') }}" class="mt-3 pt-3 border-t border-slate-100 flex flex-wrap items-end gap-3 text-xs">
            <div>
                <label class="block font-semibold text-slate-700 mb-1">Pilih Kelas:</label>
                <select name="class_id" required class="h-9 rounded-lg border border-slate-300 bg-white px-3 text-xs focus:ring-1 focus:ring-indigo-500 min-w-[200px]">
                    @foreach($classes as $c)
                    <option value="{{ $c->id }}" {{ $selectedClass?->id == $c->id ? 'selected' : '' }}>
                        {{ $c->name }} ({{ $c->academic_year }})
                    </option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="block font-semibold text-slate-700 mb-1">Pilih Periode Bulan:</label>
                <input type="month" name="month" value="{{ $month }}" required class="h-9 rounded-lg border border-slate-300 bg-white px-3 text-xs focus:ring-1 focus:ring-indigo-500">
            </div>

            <button type="submit" class="h-9 px-4 rounded-lg bg-slate-800 hover:bg-slate-900 text-white font-semibold transition-colors">
                Tampilkan Laporan
            </button>
        </form>
    </div>

    <!-- Printable Paper Sheet (Wajib 1 Halaman A4) -->
    <div class="print-container max-w-4xl mx-auto bg-white rounded-xl shadow-md border border-slate-200 p-6">

        @if($selectedClass)
        <!-- Header Bersih (Tanpa Kop Surat) -->
        <div class="report-header-section mb-3 text-center border-b border-slate-300 pb-2">
            <h1 class="text-sm md:text-base font-extrabold uppercase tracking-wide text-slate-900">
                REKAPITULASI PRESENSI KELAS
            </h1>
            <div class="mt-1 text-[8.5pt] text-slate-700 flex flex-wrap items-center justify-center gap-x-4 gap-y-0.5">
                <span>Kelas: <strong>{{ $selectedClass->name }}</strong></span>
                <span>Tahun Ajaran: <strong>{{ $selectedClass->academic_year }}</strong></span>
                <span>Periode: <strong>{{ $startDate->locale('id')->isoFormat('MMMM Y') }}</strong></span>
                <span>Hari Efektif: <strong>{{ $effectiveDays }} Hari</strong></span>
            </div>
        </div>

        <!-- Tabel Rekapitulasi Seluruh Siswa (Ukuran Font Compact 8pt Wajib 1 Halaman A4) -->
        <div class="border border-slate-400 rounded overflow-hidden">
            <table class="w-full text-left text-[8pt] border-collapse border border-slate-400">
                <thead>
                    <tr class="bg-slate-100 border-b border-slate-400 text-slate-900 font-bold uppercase text-[7.5pt]">
                        <th class="py-1 px-1 border border-slate-300 w-7 text-center">NO</th>
                        <th class="py-1 px-1.5 border border-slate-300 w-16">NIS</th>
                        <th class="py-1 px-2 border border-slate-300">NAMA SISWA</th>
                        <th class="py-1 px-1 border border-slate-300 w-12 text-center text-emerald-800">HADIR</th>
                    <th class="py-1 px-1 border border-slate-300 w-12 text-center text-amber-800">TELAT</th>
                    <th class="py-1 px-1 border border-slate-300 w-12 text-center text-sky-800">PLG CEPAT</th>
                    <th class="py-1 px-1 border border-slate-300 w-10 text-center text-blue-800">IZIN</th>
                        <th class="py-1 px-1 border border-slate-300 w-10 text-center text-cyan-800">SAKIT</th>
                        <th class="py-1 px-1 border border-slate-300 w-10 text-center text-rose-800">ALPA</th>
                        <th class="py-1 px-1 border border-slate-300 w-12 text-center text-orange-800">PENDING</th>
                        <th class="py-1 px-1 border border-slate-300 w-12 text-center">TOTAL</th>
                        <th class="py-1 px-1 border border-slate-300 w-14 text-center">% HADIR</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-300">
                    @forelse($studentRows as $idx => $row)
                    <tr class="hover:bg-slate-50">
                        <td class="py-[2px] px-1 border border-slate-300 text-center font-mono text-slate-600">{{ $idx + 1 }}</td>
                        <td class="py-[2px] px-1.5 border border-slate-300 font-mono">{{ $row['student']->nis ?? '-' }}</td>
                        <td class="py-[2px] px-2 border border-slate-300 font-semibold text-slate-900 truncate max-w-[200px]">{{ $row['student']->name }}</td>
                        <td class="py-[2px] px-1 border border-slate-300 text-center font-mono text-emerald-700 font-bold">{{ $row['hadir'] }}</td>
                    <td class="py-[2px] px-1 border border-slate-300 text-center font-mono text-amber-700">{{ $row['terlambat'] }}</td>
                    <td class="py-[2px] px-1 border border-slate-300 text-center font-mono text-sky-700">{{ $row['pulang_cepat'] }}</td>
                    <td class="py-[2px] px-1 border border-slate-300 text-center font-mono text-blue-700">{{ $row['izin'] }}</td>
                        <td class="py-[2px] px-1 border border-slate-300 text-center font-mono text-cyan-700">{{ $row['sakit'] }}</td>
                        <td class="py-[2px] px-1 border border-slate-300 text-center font-mono text-rose-700">{{ $row['alpa'] }}</td>
                        <td class="py-[2px] px-1 border border-slate-300 text-center font-mono text-orange-700">{{ $row['perlu_tinjauan'] ?? 0 }}</td>
                        <td class="py-[2px] px-1 border border-slate-300 text-center font-mono font-bold">{{ $row['total_hadir'] }}</td>
                        <td class="py-[2px] px-1 border border-slate-300 text-center font-mono font-bold {{ $row['percentage'] >= 85 ? 'text-emerald-700' : ($row['percentage'] >= 75 ? 'text-amber-700' : 'text-rose-700') }}">
                            {{ $row['percentage'] }}%
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="11" class="p-6 text-center text-slate-400">
                            Belum ada siswa yang terdaftar di kelas ini.
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @else
        <div class="p-8 text-center text-slate-400 text-xs">
            Silakan pilih kelas pada filter di atas untuk melihat laporan.
        </div>
        @endif

    </div>

</body>
</html>
