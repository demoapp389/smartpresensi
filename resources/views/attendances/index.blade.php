@extends('layouts.app')

@section('title', 'Riwayat Presensi')
@section('page-pretitle', 'Data Kehadiran')
@section('page-title', 'Riwayat & Rekap Presensi Siswa')
@section('page-subtitle', 'Pantau catatan absensi harian yang telah diproses oleh engine')

@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="transition-colors hover:text-[var(--text)]">Beranda</a>
    <span aria-hidden="true">/</span>
    <span>Data Kehadiran</span>
    <span aria-hidden="true">/</span>
    <span class="font-medium text-[var(--text)]" aria-current="page">Riwayat Presensi</span>
@endsection

@section('content')
<div class="space-y-4">
    {{-- Filter dipindah ke toolbar DataTables oleh tables.js: dropdown jumlah
         baris + filter di kiri, kotak pencarian bawaan DataTables di kanan.
         Tetap form GET biasa bila JS mati. --}}
    <form id="attendance-filter" method="GET" action="{{ route('attendances.index') }}" class="flex flex-wrap items-center gap-2 text-xs">
        <div class="flex h-8.5 items-center gap-1.5 rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface-secondary)] px-2.5">
            <label for="date" class="text-[var(--text-secondary)] font-medium whitespace-nowrap">Tanggal:</label>
            <input type="date" id="date" name="date" value="{{ $selectedDate }}" onchange="this.value && this.form.submit()" class="bg-transparent font-medium text-[var(--text)] focus:outline-none cursor-pointer text-xs">
        </div>

        @php
            $currentUser = Auth::user();
            $isHomeroom = $currentUser?->isHomeroomTeacher() ?? false;
        @endphp

        @if(! $isHomeroom)
            <select name="class_id" onchange="this.form.submit()" class="h-8.5 rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface-secondary)] px-2.5 text-xs text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none cursor-pointer">
                <option value="">Semua Kelas</option>
                @foreach($classes as $c)
                    <option value="{{ $c->id }}" {{ $classId == $c->id ? 'selected' : '' }}>{{ $c->name }}</option>
                @endforeach
            </select>
        @elseif($classes->isNotEmpty())
            <div class="flex h-8.5 items-center gap-1.5 rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface-secondary)] px-2.5 text-xs">
                <span class="text-[var(--text-secondary)] font-medium">Kelas:</span>
                <span class="font-bold text-[var(--text)]">{{ $classes->first()->name }}</span>
            </div>
        @endif

        <select name="status" onchange="this.form.submit()" class="h-8.5 rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface-secondary)] px-2.5 text-xs text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none cursor-pointer">
            <option value="">Semua Status</option>
            <option value="hadir" {{ $status == 'hadir' ? 'selected' : '' }}>Hadir</option>
            <option value="terlambat" {{ $status == 'terlambat' ? 'selected' : '' }}>Terlambat</option>
            <option value="izin" {{ $status == 'izin' ? 'selected' : '' }}>Izin</option>
            <option value="sakit" {{ $status == 'sakit' ? 'selected' : '' }}>Sakit</option>
            <option value="alpa" {{ $status == 'alpa' ? 'selected' : '' }}>Alpa</option>
            <option value="perlu_tinjauan" {{ $status == 'perlu_tinjauan' ? 'selected' : '' }}>Perlu Tinjauan</option>
        </select>

        <select name="out_status" onchange="this.form.submit()" class="h-8.5 rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface-secondary)] px-2.5 text-xs text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none cursor-pointer">
            <option value="">Semua Status Pulang</option>
            <option value="tepat_waktu" {{ $outStatus == 'tepat_waktu' ? 'selected' : '' }}>Tepat Waktu</option>
            <option value="pulang_cepat" {{ $outStatus == 'pulang_cepat' ? 'selected' : '' }}>Pulang Cepat</option>
            <option value="belum_absen" {{ $outStatus == 'belum_absen' ? 'selected' : '' }}>Belum Absen Pulang</option>
            <option value="perlu_verifikasi" {{ $outStatus == 'perlu_verifikasi' ? 'selected' : '' }}>Perlu Verifikasi</option>
        </select>

        @if((! $isHomeroom && $classId) || $status || $outStatus)
            <a href="{{ route('attendances.index', ['date' => $selectedDate]) }}" class="inline-flex h-8.5 items-center justify-center rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface)] px-3 text-xs font-medium text-[var(--text-secondary)] hover:bg-[var(--surface-secondary)] transition-colors">
                Reset
            </a>
        @endif
    </form>

    <!-- Attendance Table Card -->
    <section class="min-w-0 rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] shadow-[var(--shadow)] overflow-hidden">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--border-subtle)] px-4 py-3">
            <h2 class="text-xs font-bold text-[var(--text)]">Daftar Kehadiran Siswa</h2>

            @php
                $activeDate = $selectedDate ?? ($dateFrom ?? now('Asia/Jakarta')->toDateString());
            @endphp

            <div class="flex flex-wrap items-center gap-2">
                {{-- Ekspor Excel Monitoring Saat Ini --}}
                <a href="{{ route('attendances.export', array_merge(request()->query(), ['date' => $activeDate])) }}"
                   class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface)] text-xs font-semibold text-[var(--text)] hover:bg-[var(--surface-secondary)] transition-colors shadow-xs"
                   title="Ekspor catatan monitoring absensi ini ke Excel">
                    <svg class="size-3.5 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                    <span>Ekspor Excel</span>
                </a>

                {{-- Tombol Presensi Manual / Koreksi Operator --}}
                <a href="{{ route('attendances.correction', ['date' => $activeDate]) }}"
                   class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-[var(--radius-sm)] bg-[var(--primary)] text-xs font-semibold text-white hover:brightness-90 transition-colors shadow-xs">
                    <svg class="size-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                    <span>{{ $isHomeroom ? 'Presensi Manual' : 'Koreksi Operator' }}</span>
                </a>
            </div>
        </div>

        @php
            $showClassCol = empty($classId) && ! $isHomeroom;
        @endphp

        <div>
            <table class="min-w-[1000px] w-full text-left text-xs" data-datatable data-page-length="25" data-length-change="true" data-toolbar-filter="#attendance-filter" data-empty-message="Tidak ada catatan presensi pada tanggal dan filter yang dipilih">
                <thead class="bg-[var(--surface-secondary)] text-[11px] font-semibold text-[var(--text-secondary)] uppercase tracking-wider border-b border-[var(--border-subtle)]">
                    <tr>
                        <th scope="col" class="px-4 py-2.5">Nama Siswa</th>
                        @if($showClassCol)
                            <th scope="col" class="px-4 py-2.5">Kelas</th>
                        @endif
                        <th scope="col" class="px-4 py-2.5 font-mono">Scan Masuk</th>
                        <th scope="col" class="px-4 py-2.5 font-mono whitespace-nowrap">Telat (Menit)</th>
                        <th scope="col" class="px-4 py-2.5 font-mono">Scan Pulang</th>
                        <th scope="col" class="px-4 py-2.5 font-mono whitespace-nowrap">PLG Cepat (Menit)</th>
                        <th scope="col" class="px-4 py-2.5">Status Kehadiran</th>
                        <th scope="col" class="px-4 py-2.5">Wali Murid</th>
                        <th scope="col" class="px-4 py-2.5">Notifikasi WA</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--border-subtle)] text-[13px]">
                    @foreach($attendances as $att)
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

                        @if($showClassCol)
                            <td class="px-4 py-2.5 text-xs text-[var(--text-secondary)] font-medium">
                                {{ $studentClass }}
                            </td>
                        @endif

                        <td class="px-4 py-2.5 font-mono text-xs">
                            @if($att->check_in_at)
                                <span class="font-semibold text-[var(--text)]">{{ $att->check_in_at->format('H:i:s') }}</span>
                            @else
                                <span class="text-[var(--text-muted)]">-</span>
                            @endif
                        </td>

                        <td class="px-4 py-2.5 text-center font-mono text-xs">
                            @if($att->late_minutes_display !== null)
                                <span class="font-semibold {{ $att->late_minutes_display > 0 ? 'text-amber-700' : 'text-[var(--text)]' }}">
                                    {{ $att->late_minutes_display }}
                                </span>
                            @else
                                <span class="text-[var(--text-muted)]">-</span>
                            @endif
                        </td>

                        <td class="px-4 py-2.5 font-mono text-xs">
                            @if($att->check_out_at)
                                <span class="font-semibold text-blue-700">{{ $att->check_out_at->format('H:i:s') }}</span>
                            @else
                                <span class="text-[var(--text-muted)] text-[11px]">Belum Pulang</span>
                            @endif
                        </td>

                        <td class="px-4 py-2.5 text-center font-mono text-xs">
                            @if($att->early_leave_minutes_display !== null)
                                <span class="font-semibold {{ $att->early_leave_minutes_display > 0 ? 'text-sky-700' : 'text-[var(--text)]' }}">
                                    {{ $att->early_leave_minutes_display }}
                                </span>
                            @else
                                <span class="text-[var(--text-muted)]">-</span>
                            @endif
                        </td>

                        <td class="px-4 py-2.5">
                            @php
                                $statusClass = match ($att->status) {
                                    'hadir' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
                                    'terlambat' => 'bg-amber-50 text-amber-700 border-amber-200',
                                    'izin' => 'bg-purple-50 text-purple-700 border-purple-200',
                                    'sakit' => 'bg-blue-50 text-blue-700 border-blue-200',
                                    'alpa' => 'bg-rose-50 text-rose-700 border-rose-200',
                                    'perlu_tinjauan' => 'bg-orange-50 text-orange-700 border-orange-200',
                                    'pulang_cepat' => 'bg-sky-50 text-sky-700 border-sky-200',
                                    default => 'bg-slate-50 text-slate-600 border-slate-200',
                                };
                                $statusLabel = $att->status === 'pulang_cepat'
                                    ? 'Pulang Cepat (Lama)'
                                    : $att->status_display_label;
                            @endphp
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-semibold border {{ $statusClass }}">
                                {{ $statusLabel }}
                            </span>

                            @if($att->is_manual_override)
                                <div class="text-[10px] text-purple-700 mt-0.5 font-medium italic">
                                    Koreksi: {{ $att->override_reason }}
                                </div>
                            @elseif($att->isAutomaticAlpa())
                                <div class="text-[10px] text-rose-700 mt-0.5 font-medium italic">
                                    Sumber: Auto-Alpa H+1
                                </div>
                            @endif

                            @if($att->scan_completeness_label !== 'Scan Lengkap')
                                <div class="text-[10px] text-[var(--text-muted)] mt-0.5">
                                    {{ $att->scan_completeness_label }}
                                </div>
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
                            @if(!$guardian)
                                {{-- Tidak ada wali terdaftar --}}
                                <span class="inline-flex items-center px-1.5 py-0.5 rounded font-semibold text-[10px] bg-slate-100 text-slate-500 border border-slate-200">Tanpa Wali</span>
                            @elseif(empty($guardian->wa_number))
                                {{-- Wali ada tapi nomor kosong --}}
                                <span class="inline-flex items-center px-1.5 py-0.5 rounded font-semibold text-[10px] bg-slate-100 text-slate-500 border border-slate-200">Tanpa Nomor</span>
                            @elseif($guardian->wa_status === 'invalid')
                                {{-- Nomor tidak valid --}}
                                <span class="inline-flex items-center px-1.5 py-0.5 rounded font-semibold text-[10px] bg-rose-50 text-rose-600 border border-rose-200">Invalid</span>
                            @elseif($guardian->wa_status === 'pending' || $guardian->wa_status === 'unknown')
                                {{-- Belum divalidasi --}}
                                <span class="inline-flex items-center px-1.5 py-0.5 rounded font-semibold text-[10px] bg-amber-50 text-amber-600 border border-amber-200">Belum Validasi</span>
                            @elseif($att->notifications->count() > 0)
                                {{-- Ada notifikasi: tampilkan status masing-masing --}}
                                <div class="space-y-0.5">
                                    @foreach($att->notifications as $notif)
                                        <div class="flex items-center gap-1.5 text-[11px]">
                                            <span class="font-medium text-[var(--text-muted)] text-[10px] uppercase">
                                                {{ $notif->event_type === 'check_in' ? 'Masuk' : ($notif->event_type === 'check_out' ? 'Pulang' : 'Telat') }}:
                                            </span>
                                            <x-notification-status :status="$notif->status" />
                                        </div>
                                    @endforeach
                                </div>
                            @else
                                {{-- Nomor valid tapi belum ada notifikasi --}}
                                <span class="text-[var(--text-muted)] italic text-[11px]">-</span>
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

