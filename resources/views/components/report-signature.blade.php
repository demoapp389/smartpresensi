@props([
    'classTitle' => 'Wali Kelas / Guru Piket',
    'signerName' => null,
    'headmasterName' => 'Dr. H. Sulaiman, M.Pd.',
    'headmasterNip' => '19750812 199903 1 002',
    'location' => 'Jakarta',
    'date' => null,
])

@php
    $printDate = $date ? Carbon\Carbon::parse($date)->locale('id')->isoFormat('D MMMM Y') : now()->locale('id')->isoFormat('D MMMM Y');
    $fullPrintTimestamp = now()->locale('id')->isoFormat('dddd, D MMMM Y - HH:mm:ss') . ' WIB';
@endphp

<div class="report-signature-wrapper mt-6 pt-3 text-[9.5pt] text-slate-800 break-inside-avoid" style="page-break-inside: avoid;">
    <div class="flex items-start justify-between text-center px-4">
        {{-- Kiri: Pengesahan Wali Kelas / Operator --}}
        <div class="w-64">
            <p class="text-slate-600 mb-1">Mengetahui,</p>
            <p class="font-bold text-slate-900">{{ $classTitle }}</p>
            <div class="h-16"></div>
            <p class="font-bold underline text-slate-900">{{ $signerName ?: '................................................' }}</p>
            <p class="text-[8.5pt] text-slate-600 font-mono">NIP: {{ $signerName ? '19820514 200801 2 004' : '....................................' }}</p>
        </div>

        {{-- Kanan: Pengesahan Kepala Sekolah & Tanggal Cetak --}}
        <div class="w-64">
            <p class="text-slate-600 mb-1">{{ $location }}, {{ $printDate }}</p>
            <p class="font-bold text-slate-900">Kepala Sekolah</p>
            <div class="h-16"></div>
            <p class="font-bold underline text-slate-900">{{ $headmasterName }}</p>
            <p class="text-[8.5pt] text-slate-600 font-mono">NIP: {{ $headmasterNip }}</p>
        </div>
    </div>

    {{-- Footer Info Waktu Cetak & Sistem --}}
    <div class="mt-4 pt-2 border-t border-slate-300 flex items-center justify-between text-[8pt] text-slate-500">
        <div>
            Dicetak melalui Sistem Presensi Siswa Solution X601 &bull; {{ $fullPrintTimestamp }}
        </div>
        <div>
            Dokumen Sah Kedinasan &bull; Halaman Cetak Mandiri
        </div>
    </div>
</div>
