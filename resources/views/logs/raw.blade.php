@extends('layouts.app')

@section('title', 'Log Scan Mentah')
@section('page-pretitle', 'Hardware & Audit')
@section('page-title', 'Log Scan Mentah (Raw Logs)')
@section('page-subtitle', 'Catatan waktu asli dari mesin fingerprint (Immutable untuk audit integritas)')

@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="transition-colors hover:text-[var(--text)]">Beranda</a>
    <span aria-hidden="true">/</span>
    <span>Hardware &amp; Audit</span>
    <span aria-hidden="true">/</span>
    <span class="font-medium text-[var(--text)]" aria-current="page">Log Scan Mentah</span>
@endsection

@section('content')
<div class="space-y-4">
    @if($timeReviewCount > 0 && $status !== \App\Models\AttendanceLog::STATUS_NEEDS_REVIEW)
        <a href="{{ route('logs.raw', ['status' => \App\Models\AttendanceLog::STATUS_NEEDS_REVIEW, 'date' => 'all']) }}" class="flex items-center justify-between gap-3 rounded-[var(--radius)] border border-orange-200 bg-orange-50 px-4 py-3 text-xs text-orange-900 transition-colors hover:bg-orange-100">
            <span class="font-semibold">{{ $timeReviewCount }} scan menunggu tinjauan waktu (termasuk tanggal historis).</span>
            <span class="font-medium underline underline-offset-2">Buka antrean</span>
        </a>
    @endif

    @if($status === \App\Models\AttendanceLog::STATUS_NEEDS_REVIEW)
        <div class="rounded-[var(--radius)] border border-sky-200 bg-sky-50 px-4 py-3 text-xs text-sky-900">
            Tinjauan waktu hanya memeriksa selisih jam scan mesin dengan waktu diterima server. Ini berbeda dari status absensi <span class="font-semibold">Perlu Tinjauan</span>, yang berarti bukti scan belum lengkap dan dikoreksi di menu Koreksi Absensi.
        </div>
    @endif

    {{-- Filter dipindah ke toolbar DataTables oleh tables.js: dropdown jumlah
         baris + filter di kiri, kotak pencarian bawaan DataTables di kanan.
         Tetap form GET biasa bila JS mati. --}}
    <form id="raw-filter" method="GET" action="{{ route('logs.raw') }}" class="flex flex-wrap items-center gap-2 text-xs">
        <div class="flex h-8.5 items-center gap-1.5 rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface-secondary)] px-2.5">
            <label for="date" class="text-[var(--text-secondary)] font-medium whitespace-nowrap">Tanggal Scan:</label>
            <input type="date" id="date" name="date" value="{{ $dateInput }}" onchange="this.value && this.form.submit()" class="bg-transparent font-medium text-[var(--text)] focus:outline-none cursor-pointer text-xs">
        </div>

        <select name="device_id" onchange="this.form.submit()" class="h-8.5 rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface-secondary)] px-2.5 text-xs text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none cursor-pointer">
            <option value="">Semua Perangkat</option>
            @foreach($devices as $d)
                <option value="{{ $d->id }}" {{ $deviceId == $d->id ? 'selected' : '' }}>{{ $d->name }}</option>
            @endforeach
        </select>

        <select name="status" onchange="this.form.submit()" class="h-8.5 rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface-secondary)] px-2.5 text-xs text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none cursor-pointer">
            <option value="">Semua Status Log</option>
            <option value="processed" {{ $status === 'processed' ? 'selected' : '' }}>Processed</option>
            <option value="unprocessed" {{ $status === 'unprocessed' ? 'selected' : '' }}>Belum Diproses</option>
            <option value="needs_review" {{ $status === 'needs_review' ? 'selected' : '' }}>Perlu Tinjauan Waktu</option>
            <option value="time_rejected" {{ $status === 'time_rejected' ? 'selected' : '' }}>Ditolak (Waktu)</option>
            <option value="unmapped" {{ $status === 'unmapped' ? 'selected' : '' }}>Unmapped</option>
            <option value="failed" {{ $status === 'failed' ? 'selected' : '' }}>Gagal Diproses</option>
            <option value="dismissed" {{ $status === 'dismissed' ? 'selected' : '' }}>Dibersihkan (PIN Sudah Dipetakan)</option>
            <option value="inactive_student" {{ $status === 'inactive_student' ? 'selected' : '' }}>Siswa Terhapus</option>
            <option value="ignored" {{ $status === 'ignored' ? 'selected' : '' }}>Ignored (Duplikat)</option>
            <option value="rejected" {{ $status === 'rejected' ? 'selected' : '' }}>Ditolak (Di Luar Jendela)</option>
            <option value="unprocessed" {{ $status === 'unprocessed' ? 'selected' : '' }}>Unprocessed</option>
            <option value="failed" {{ $status === 'failed' ? 'selected' : '' }}>Gagal Permanen (Perlu Retry)</option>
        </select>

        @if($isFiltered)
            <a href="{{ route('logs.raw') }}" class="inline-flex h-8.5 items-center justify-center rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface)] px-3 text-xs font-medium text-[var(--text-secondary)] hover:bg-[var(--surface-secondary)] transition-colors">
                Reset
            </a>
        @endif
    </form>

    <!-- Table Card -->
    <section class="min-w-0 rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] shadow-[var(--shadow)] overflow-hidden">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--border-subtle)] px-4 py-3">
            <h2 class="text-xs font-bold text-[var(--text)]">Data Raw Sensor Ingest</h2>
            <div class="text-[11px] text-[var(--text-muted)]">Source of Truth Mesin Fingerprint</div>
        </div>

        <div>
            <table class="min-w-[700px] w-full text-left text-xs font-mono" data-datatable data-page-length="25" data-length-change="true" data-toolbar-filter="#raw-filter" data-empty-message="Tidak ada raw log yang tersimpan sesuai filter">
                <thead class="bg-[var(--surface-secondary)] text-[11px] font-semibold text-[var(--text-secondary)] uppercase tracking-wider border-b border-[var(--border-subtle)] font-sans">
                    <tr>
                        <th scope="col" class="px-4 py-2.5">Waktu Scan Mesin</th>
                        <th scope="col" class="px-4 py-2.5">Waktu Terima Server</th>
                        <th scope="col" class="px-4 py-2.5">Perangkat</th>
                        <th scope="col" class="px-4 py-2.5">PIN Mesin</th>
                        <th scope="col" class="px-4 py-2.5">Source Key (Idempotent)</th>
                        <th scope="col" class="px-4 py-2.5">Status Log</th>
                        <th scope="col" class="px-4 py-2.5">Catatan Proses</th>
                        <th scope="col" class="px-4 py-2.5 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--border-subtle)] text-[13px]">
                    @foreach($logs as $log)
                    <tr class="hover:bg-[var(--surface-secondary)]/50 transition-colors">
                        <td class="px-4 py-2.5 font-bold text-[var(--text)]">{{ $log->occurred_at->format('Y-m-d H:i:s') }}</td>
                        <td class="px-4 py-2.5 text-[var(--text-secondary)]">{{ $log->received_at->format('Y-m-d H:i:s') }}</td>
                        <td class="px-4 py-2.5 font-sans font-medium text-[var(--text)]">{{ $log->device->name ?? 'Fingerprint' }}</td>
                        <td class="px-4 py-2.5">
                            <span class="inline-flex items-center px-2 py-0.5 rounded-[var(--radius-sm)] bg-[var(--surface-secondary)] font-bold text-[var(--text)] border border-[var(--border)]">
                                {{ $log->machine_user_id }}
                            </span>
                        </td>
                        <td class="px-4 py-2.5 text-[11px] text-[var(--text-muted)] truncate max-w-xs" title="{{ $log->source_key }}">
                            {{ $log->source_key }}
                        </td>
                        <td class="px-4 py-2.5 font-sans">
                            @if($log->status === 'processed')
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                    Processed
                                </span>
                            @elseif($log->status === 'unmapped')
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold bg-amber-50 text-amber-700 border border-amber-200">
                                    Unmapped (Belum Terdaftar)
                                </span>
                            @elseif($log->status === 'dismissed')
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold bg-zinc-100 text-zinc-600 border border-zinc-200">
                                    Dibersihkan (PIN Sudah Dipetakan)
                                </span>
                            @elseif($log->status === 'inactive_student')
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold bg-zinc-100 text-zinc-600 border border-zinc-200">
                                    Siswa Terhapus
                                </span>
                            @elseif($log->status === 'ignored')
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold bg-slate-100 text-slate-600 border border-slate-200">
                                    Ignored (Duplikat)
                                </span>
                            @elseif($log->status === 'needs_review')
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold bg-orange-50 text-orange-700 border border-orange-200">
                                    Perlu Tinjauan Waktu
                                </span>
                            @elseif($log->status === 'time_rejected')
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold bg-zinc-100 text-zinc-600 border border-zinc-200">
                                    Ditolak (Waktu)
                                </span>
                            @elseif($log->status === 'rejected')
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold bg-rose-50 text-rose-700 border border-rose-200">
                                    Ditolak
                                </span>
                            @elseif($log->status === 'failed')
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold bg-rose-100 text-rose-800 border border-rose-300">
                                    Gagal Permanen
                                </span>
                            @else
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold bg-blue-50 text-blue-700 border border-blue-200">
                                    Unprocessed
                                </span>
                            @endif
                        </td>
                        <td class="px-4 py-2.5 font-sans text-[11px] text-[var(--text-secondary)] max-w-xs truncate" title="{{ $log->processing_note }}">
                            <div class="truncate">{{ $log->processing_note ?: '-' }}</div>
                            @if($log->processing_attempts > 0)
                                <div class="text-[10px] text-[var(--text-muted)]">
                                    Percobaan: {{ $log->processing_attempts }}
                                    @if($log->next_retry_at)
                                        &middot; Retry: {{ $log->next_retry_at->format('H:i:s') }}
                                    @endif
                                </div>
                            @endif
                        </td>
                        <td class="px-4 py-2.5 text-right font-sans">
                            @if($log->status === \App\Models\AttendanceLog::STATUS_NEEDS_REVIEW)
                                @if(auth()->user()?->isAdmin())
                                    <form action="{{ route('logs.raw.time-review.approve', $log) }}" method="POST" class="flex flex-col items-end gap-1">
                                        @csrf
                                        <input type="text" name="review_note" required maxlength="500" placeholder="Alasan persetujuan/penolakan..." class="h-7 w-56 rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface)] px-2 text-[11px] text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none">
                                        <div class="flex items-center gap-1">
                                            <button type="submit" class="inline-flex items-center px-2.5 py-1 rounded-[var(--radius-sm)] border border-emerald-200 bg-emerald-50 hover:bg-emerald-100 text-[11px] font-semibold text-emerald-800 transition-colors">
                                                Setujui
                                            </button>
                                            <button type="submit" formaction="{{ route('logs.raw.time-review.reject', $log) }}" class="inline-flex items-center px-2.5 py-1 rounded-[var(--radius-sm)] border border-rose-200 bg-rose-50 hover:bg-rose-100 text-[11px] font-semibold text-rose-800 transition-colors">
                                                Tolak
                                            </button>
                                        </div>
                                    </form>
                                @else
                                    <span class="text-[10px] text-orange-700">Menunggu admin</span>
                                @endif
                            @elseif(in_array($log->status, ['failed', 'unprocessed'], true))
                                <form action="{{ route('logs.raw.retry', $log) }}" method="POST" class="inline">
                                    @csrf
                                    <button type="submit" class="inline-flex items-center px-2.5 py-1 rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface)] hover:bg-[var(--surface-secondary)] text-[11px] font-semibold text-[var(--text)] transition-colors">
                                        Proses Ulang
                                    </button>
                                </form>
                            @else
                                <span class="text-[var(--text-muted)]">-</span>
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

