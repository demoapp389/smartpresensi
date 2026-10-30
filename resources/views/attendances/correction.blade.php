@extends('layouts.app')

@php
    $currentUser = Auth::user();
    $isWali = $currentUser?->isHomeroomTeacher() ?? false;
    $actionableIds = collect($actionableAttendanceIds)->filter()->keys();
    $actionableCount = $actionableIds->count();
@endphp

@section('title', $isWali ? 'Presensi Manual' : 'Koreksi Absensi')
@section('page-pretitle', $isWali ? 'Presensi Siswa' : 'Operasional & Pengecualian')
@section('page-title', $isWali ? 'Presensi Manual Siswa' : 'Koreksi Absensi & Pengecualian Operator')
@section('page-subtitle', $isWali ? 'Catat izin atau sakit untuk siswa kelas yang Anda ampu' : 'Koreksi status presensi dengan alasan yang tercatat pada audit log tanpa menghapus data scan')

@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="transition-colors hover:text-[var(--text)]">Beranda</a>
    <span aria-hidden="true">/</span>
    <span>{{ $isWali ? 'Presensi' : 'Operasional & Pengecualian' }}</span>
    <span aria-hidden="true">/</span>
    <span class="font-medium text-[var(--text)]" aria-current="page">{{ $isWali ? 'Presensi Manual' : 'Koreksi Absensi' }}</span>
@endsection

@section('content')
<div class="space-y-4" x-data="{
    correctModal: false,
    manualModal: false,
    bulkModal: false,
    scanReasonModal: false,
    adminScanModal: false,
    activeAtt: {},
    scanAtt: {},
    adminScanAtt: {},
    scanReason: '',
    adminScanReason: '',
    selected: [],
    bulkStatus: 'izin',
    bulkReason: '',
    toggleAll(e) {
        if (e.target.checked) {
            this.selected = @js(collect($actionableAttendanceIds)->filter()->keys()->map(fn ($id) => (string) $id)->values());
        } else {
            this.selected = [];
        }
    },
    statusLabel(status) {
        return ({ hadir: 'Hadir', terlambat: 'Terlambat', izin: 'Izin', sakit: 'Sakit', alpa: 'Alpa', perlu_tinjauan: 'Perlu Tinjauan' })[status] ?? status;
    }
}" @open-manual-modal.window="manualModal = true">
    {{-- Filter dipindah ke toolbar DataTables oleh tables.js: dropdown jumlah
         baris + filter di kiri, kotak pencarian bawaan DataTables di kanan.
         Tetap form GET biasa bila JS mati. --}}
    <form id="correction-filter" method="GET" action="{{ route('attendances.correction') }}" class="flex flex-wrap items-center gap-2 text-xs">
        <div class="flex h-8.5 items-center gap-1.5 rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface-secondary)] px-2.5">
            <label for="date" class="text-[var(--text-secondary)] font-medium whitespace-nowrap">Tanggal:</label>
            <input type="date" id="date" name="date" value="{{ $selectedDate }}" onchange="this.value && this.form.submit()" class="bg-transparent font-medium text-[var(--text)] focus:outline-none cursor-pointer text-xs">
        </div>

        @if($isFiltered)
            <a href="{{ route('attendances.correction') }}" class="inline-flex h-8.5 items-center justify-center rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface)] px-3 text-xs font-medium text-[var(--text-secondary)] hover:bg-[var(--surface-secondary)] transition-colors">
                Reset
            </a>
        @endif
    </form>

    <!-- Attendance Table -->
    <section class="min-w-0 rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] shadow-[var(--shadow)] overflow-hidden">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--border-subtle)] px-4 py-3">
            <h2 class="text-xs font-bold text-[var(--text)]">Daftar Presensi untuk Penyesuaian</h2>

            <div class="flex flex-wrap items-center gap-2">
                @if(Auth::user()->isHomeroomTeacher() && Auth::user()->schoolClass)
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded bg-emerald-50 border border-emerald-200 text-xs font-semibold text-emerald-800">
                        <span class="size-2 rounded-full bg-emerald-500"></span>
                        <span>Wali Kelas: {{ Auth::user()->schoolClass->name }}</span>
                    </span>
                @endif

                <button type="button" @click="$dispatch('open-manual-modal')"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-[var(--radius-sm)] bg-[var(--primary)] text-xs font-semibold text-white hover:brightness-90 transition-colors shadow-xs">
                    <svg class="size-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    <span>{{ $isWali ? 'Input Presensi Manual (Izin/Sakit)' : 'Input Presensi Manual' }}</span>
                </button>
            </div>
        </div>

        <!-- Bulk Action Bar -->
        @if($canBulkCorrect)
            <div x-cloak x-show="selected.length > 0" x-transition
                 class="flex flex-wrap items-center justify-between gap-3 border-b border-indigo-200 bg-indigo-50/80 dark:bg-indigo-950/40 dark:border-indigo-800/60 px-4 py-2.5">
                <div class="flex items-center gap-3">
                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold bg-indigo-100 text-indigo-800 dark:bg-indigo-900/60 dark:text-indigo-200 border border-indigo-300 dark:border-indigo-700">
                        <span x-text="selected.length"></span> presensi dipilih
                    </span>
                    <button type="button" @click="selected = []"
                        class="inline-flex items-center px-2.5 py-1 rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface)] hover:bg-[var(--surface-secondary)] text-xs font-medium text-[var(--text-secondary)] transition-colors cursor-pointer">
                        Batal Pilih
                    </button>
                </div>

                <div class="flex flex-wrap items-center gap-3">
                    <button type="button" @click="bulkModal = true"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-[var(--radius-sm)] bg-[var(--primary)] text-xs font-semibold text-white hover:brightness-90 transition-colors shadow-xs cursor-pointer">
                        <svg class="size-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                        <span>Koreksi Massal (<span x-text="selected.length"></span>)</span>
                    </button>
                </div>
            </div>
        @endif

        <div>
            <table class="min-w-[900px] w-full text-left text-xs" data-datatable data-page-length="25" data-length-change="true" data-toolbar-filter="#correction-filter" data-empty-message="Tidak ada catatan presensi pada tanggal ini">
                <thead class="bg-[var(--surface-secondary)] text-[11px] font-semibold text-[var(--text-secondary)] uppercase tracking-wider border-b border-[var(--border-subtle)]">
                    <tr>
                        <th scope="col" class="w-10 px-4 py-2.5 text-center" data-orderable="false">
                            <input type="checkbox" @change="toggleAll($event)" :checked="selected.length > 0 && selected.length === {{ $actionableCount }}" @disabled($actionableCount === 0) title="Pilih semua presensi yang dapat dikoreksi" class="size-4 rounded border-[var(--border)] text-[var(--primary-accessible)] focus:ring-[var(--primary-accessible)] cursor-pointer disabled:cursor-not-allowed disabled:opacity-50">
                        </th>
                        <th scope="col" class="px-4 py-2.5">Nama Siswa</th>
                        @if(! $isWali)
                            <th scope="col" class="px-4 py-2.5">Kelas</th>
                        @endif
                        <th scope="col" class="px-4 py-2.5 font-mono">Scan Masuk</th>
                        <th scope="col" class="px-4 py-2.5 font-mono whitespace-nowrap">Telat (Menit)</th>
                        <th scope="col" class="px-4 py-2.5 font-mono">Scan Pulang</th>
                        <th scope="col" class="px-4 py-2.5 font-mono whitespace-nowrap">PLG Cepat (Menit)</th>
                        <th scope="col" class="px-4 py-2.5">Status Saat Ini</th>
                        <th scope="col" class="px-4 py-2.5">Riwayat Koreksi</th>
                        <th scope="col" data-orderable="false" class="px-4 py-2.5 text-right">Tindakan</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--border-subtle)] text-[13px]">
                    @foreach($attendances as $att)
                    @php
                        $studentName = $att->student?->name ?? 'Siswa tidak tersedia';
                        $studentNis = $att->student?->nis ?? '-';
                        $studentClass = $att->student?->schoolClass?->name ?? '-';
                    @endphp
                    <tr class="hover:bg-[var(--surface-secondary)]/50 transition-colors">
                        <td class="w-10 px-4 py-2.5 text-center">
                            @if($actionableAttendanceIds[$att->id] ?? false)
                                <input type="checkbox" :value="'{{ $att->id }}'" x-model="selected" class="size-4 rounded border-[var(--border)] text-[var(--primary-accessible)] focus:ring-[var(--primary-accessible)] cursor-pointer">
                            @else
                                <span class="text-[var(--text-muted)]" title="Tidak ada status koreksi yang tersedia">-</span>
                            @endif
                        </td>
                        <td class="px-4 py-2.5">
                            <div class="font-semibold text-[var(--text)]">{{ $studentName }}</div>
                            <div class="text-[11px] text-[var(--text-muted)] font-mono">NIS: {{ $studentNis }}</div>
                        </td>

                        @if(! $isWali)
                            <td class="px-4 py-2.5 text-xs text-[var(--text-secondary)] font-medium">
                                {{ $studentClass }}
                            </td>
                        @endif

                        <td class="px-4 py-2.5 font-mono text-xs">
                            <div>{{ $att->check_in_at ? $att->check_in_at->format('H:i:s') : '-' }}</div>
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
                            <div>{{ $att->check_out_at ? $att->check_out_at->format('H:i:s') : '-' }}</div>
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
                                    'hadir' => 'bg-emerald-50 text-emerald-700 border border-emerald-200',
                                    'terlambat' => 'bg-amber-50 text-amber-700 border border-amber-200',
                                    'izin' => 'bg-purple-50 text-purple-700 border border-purple-200',
                                    'sakit' => 'bg-blue-50 text-blue-700 border border-blue-200',
                                    'alpa' => 'bg-rose-50 text-rose-700 border border-rose-200',
                                    'perlu_tinjauan' => 'bg-orange-50 text-orange-700 border border-orange-200',
                                    'pulang_cepat' => 'bg-sky-50 text-sky-700 border border-sky-200',
                                    default => 'bg-slate-50 text-slate-600 border border-slate-200',
                                };
                                $statusLabel = $att->status === 'pulang_cepat'
                                    ? 'Pulang Cepat (Lama)'
                                    : $att->status_display_label;
                            @endphp
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-semibold {{ $statusClass }}">
                                {{ $statusLabel }}
                            </span>
                            @if($att->status === 'perlu_tinjauan' || $att->check_out_status === 'belum_absen')
                                <div class="text-[10px] text-orange-700 font-medium mt-1">
                                    @if($att->check_in_at !== null || $att->check_out_at !== null)
                                        {{ $att->scan_completeness_label }}. Status kehadiran mengikuti scan.
                                    @else
                                        Tidak ada scan. Tunggu koreksi Izin/Sakit/Alpa atau Auto-Alpa H+1.
                                    @endif
                                </div>
                            @endif
                        </td>

                        <td class="px-4 py-2.5 text-xs">
                            @if($att->override_reason)
                                <div class="text-[var(--text)] font-semibold">{{ $att->override_reason }}</div>
                                <div class="text-[10px] text-[var(--text-muted)]">
                                    {{ $att->isAutomaticAlpa() ? 'Sumber: Auto-Alpa H+1' : 'Oleh: '.($att->operator->name ?? 'Operator') }}
                                </div>
                            @elseif($att->isAutomaticAlpa())
                                <div class="text-[var(--text)] font-semibold">Alpa otomatis H+1</div>
                                <div class="text-[10px] text-[var(--text-muted)]">Sumber: Sistem</div>
                            @else
                                <span class="text-[var(--text-muted)] italic">Otomatis dari Mesin</span>
                            @endif
                        </td>

                        <td class="px-4 py-2.5 text-right">
                            @if($adminScanExceptionAttendanceIds[$att->id] ?? false)
                                <button type="button"
                                    @click="adminScanAtt = {
                                        id: '{{ $att->id }}',
                                        name: '{{ addslashes($studentName) }}',
                                        status: '{{ \App\Services\AttendanceCorrectionService::ADMIN_SCAN_EXCEPTION_STATUSES[0] }}',
                                        reason: '{{ addslashes($att->override_reason ?? '') }}'
                                    }; adminScanReason = adminScanAtt.reason; adminScanModal = true;"
                                    class="inline-flex items-center px-2.5 py-1 rounded-[var(--radius-sm)] border border-amber-200 bg-amber-50 text-xs font-semibold text-amber-800 hover:bg-amber-100 transition-colors">
                                    Pengecualian Admin
                                </button>
                            @elseif($actionableAttendanceIds[$att->id] ?? false)
                                <button type="button"
                                    @click="activeAtt = {
                                        id: '{{ $att->id }}',
                                        name: '{{ addslashes($studentName) }}',
                                        status: @js(in_array($att->status, $allowedStatusesByAttendance[$att->id] ?? [], true) ? $att->status : (($allowedStatusesByAttendance[$att->id] ?? [])[0] ?? $att->status)),
                                        allowedStatuses: @js($allowedStatusesByAttendance[$att->id] ?? []),
                                        isPending: {{ $att->status === 'perlu_tinjauan' ? 'true' : 'false' }},
                                        scanContext: '{{ $att->check_in_at !== null && $att->check_out_at !== null ? 'complete' : (($att->check_in_at !== null || $att->check_out_at !== null) ? 'partial' : 'none') }}',
                                        reason: '{{ addslashes($att->override_reason ?? '') }}'
                                    }; correctModal = true;"
                                    class="inline-flex items-center px-2.5 py-1 rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface)] hover:bg-[var(--surface-secondary)] text-xs font-semibold text-[var(--text)] transition-colors">
                                    {{ $att->status === 'perlu_tinjauan' ? 'Verifikasi' : 'Koreksi' }}
                                </button>
                            @elseif($scanReasonAttendanceIds[$att->id] ?? false)
                                <button type="button"
                                    @click="scanAtt = {
                                        id: '{{ $att->id }}',
                                        name: '{{ addslashes($studentName) }}',
                                        reason: '{{ addslashes($att->override_reason ?? '') }}'
                                    }; scanReason = scanAtt.reason; scanReasonModal = true;"
                                    class="inline-flex items-center px-2.5 py-1 rounded-[var(--radius-sm)] border border-orange-200 bg-orange-50 text-xs font-semibold text-orange-800 hover:bg-orange-100 transition-colors">
                                    Isi Alasan
                                </button>
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

    <!-- Modal Form Koreksi Operator -->
    <div x-cloak x-show="correctModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-[#0f1623]/60 backdrop-blur-xs">
        <div @click.away="correctModal = false" class="bg-[var(--surface)] rounded-[var(--radius-lg)] border border-[var(--border)] shadow-xl w-full max-w-md p-5">
            <h3 class="text-sm font-bold text-[var(--text)] mb-1">
                <span x-text="activeAtt.isPending ? 'Verifikasi Presensi: ' : 'Koreksi Presensi: '"></span><span x-text="activeAtt.name"></span>
            </h3>
            <p class="text-[11px] text-[var(--text-secondary)] mb-4">
                Setiap perubahan dicatat di Audit Log lengkap dengan alasan dan user operator. Data scan asli tidak dihapus.
            </p>

            <div x-show="activeAtt.scanContext === 'none'" x-cloak class="mb-4 rounded-[var(--radius-sm)] border border-orange-200 bg-orange-50 px-3 py-2 text-[11px] text-orange-800">
                Tidak ada scan masuk maupun pulang. Pilih Izin, Sakit, atau Alpa. Jika tidak dikoreksi, presensi otomatis menjadi Alpa pada H+1.
            </div>

            <div x-show="activeAtt.scanContext === 'partial'" x-cloak class="mb-4 rounded-[var(--radius-sm)] border border-orange-200 bg-orange-50 px-3 py-2 text-[11px] text-orange-800">
                Scan tidak lengkap. Pilih status penyelesaian yang sesuai; jam scan tetap disimpan sebagai bukti.
            </div>

            <div x-show="activeAtt.scanContext === 'complete'" x-cloak class="mb-4 rounded-[var(--radius-sm)] border border-amber-200 bg-amber-50 px-3 py-2 text-[11px] text-amber-800">
                Presensi memiliki scan lengkap. Koreksi ke Izin, Sakit, atau Alpa hanya dapat dilakukan admin dan tidak menghapus jam scan.
            </div>

            <form :action="'{{ url('attendances') }}/' + activeAtt.id + '/correct'" method="POST" class="space-y-3 text-xs">
                @csrf
                <div>
                    <label class="block font-semibold text-[var(--text)] mb-1">Ubah Status Menjadi</label>
                    <select name="status" x-model="activeAtt.status" required class="h-9 w-full rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] px-3 text-xs text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none focus:ring-1 focus:ring-[var(--primary-accessible)]">
                        <template x-for="status in activeAtt.allowedStatuses" :key="status">
                            <option :value="status" x-text="statusLabel(status)"></option>
                        </template>
                    </select>
                    <p class="mt-1 text-[10px] text-[var(--text-muted)]">
                        Pilihan status mengikuti bukti scan dan wewenang role Anda.
                    </p>
                </div>

                <div>
                    <label class="block font-semibold text-[var(--text)] mb-1">Alasan Koreksi (Wajib)</label>
                    <textarea name="override_reason" x-model="activeAtt.reason" rows="3" required placeholder="Contoh: Membawa surat dokter / izin resmi wali murid"
                        class="w-full rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] p-2.5 text-xs text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none focus:ring-1 focus:ring-[var(--primary-accessible)]"></textarea>
                </div>

                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" @click="correctModal = false" class="px-3.5 py-1.5 rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface)] hover:bg-[var(--surface-secondary)] text-xs font-semibold text-[var(--text-secondary)] transition-colors">
                        Batal
                    </button>
                    <button type="submit" class="px-3.5 py-1.5 rounded-[var(--radius-sm)] bg-[var(--primary)] text-xs font-semibold text-white hover:brightness-95 transition-colors">
                        Simpan Koreksi
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Pengecualian Scan Masuk (Admin) -->
    <div x-cloak x-show="adminScanModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-[#0f1623]/60 backdrop-blur-xs">
        <div @click.away="adminScanModal = false" class="bg-[var(--surface)] rounded-[var(--radius-lg)] border border-[var(--border)] shadow-xl w-full max-w-md p-5">
            <h3 class="text-sm font-bold text-[var(--text)] mb-1">
                Pengecualian Scan Masuk: <span x-text="adminScanAtt.name"></span>
            </h3>
            <p class="text-[11px] text-[var(--text-secondary)] mb-4">
                Presensi ini hanya memiliki scan pulang. Admin dapat menetapkan status kebijakan tanpa mengubah atau mengarang data scan.
            </p>

            <div class="mb-4 rounded-[var(--radius-sm)] border border-amber-200 bg-amber-50 px-3 py-2 text-[11px] text-amber-800">
                Status yang diizinkan hanya Izin, Sakit, atau Alpa. Hadir dan Terlambat tidak dapat dipilih melalui pengecualian ini.
            </div>

            <form :action="'{{ url('attendances') }}/' + adminScanAtt.id + '/admin-scan-exception'" method="POST" class="space-y-3 text-xs">
                @csrf
                <div>
                    <label class="block font-semibold text-[var(--text)] mb-1">Status Pengecualian</label>
                    <select name="status" x-model="adminScanAtt.status" required class="h-9 w-full rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] px-3 text-xs text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none focus:ring-1 focus:ring-[var(--primary-accessible)]">
                        @foreach(\App\Services\AttendanceCorrectionService::ADMIN_SCAN_EXCEPTION_STATUSES as $status)
                            <option value="{{ $status }}">{{ \App\Models\Attendance::STATUS_LABELS[$status] ?? ucfirst($status) }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="block font-semibold text-[var(--text)] mb-1">Alasan Pengecualian (Wajib)</label>
                    <textarea name="override_reason" x-model="adminScanReason" rows="3" required placeholder="Contoh: Mesin gagal mencatat scan masuk / surat izin resmi"
                        class="w-full rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] p-2.5 text-xs text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none focus:ring-1 focus:ring-[var(--primary-accessible)]"></textarea>
                </div>

                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" @click="adminScanModal = false" class="px-3.5 py-1.5 rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface)] hover:bg-[var(--surface-secondary)] text-xs font-semibold text-[var(--text-secondary)] transition-colors">
                        Batal
                    </button>
                    <button type="submit" class="px-3.5 py-1.5 rounded-[var(--radius-sm)] bg-[var(--primary)] text-xs font-semibold text-white hover:brightness-95 transition-colors">
                        Simpan Pengecualian
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Alasan Scan Tidak Lengkap -->
    <div x-cloak x-show="scanReasonModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-[#0f1623]/60 backdrop-blur-xs">
        <div @click.away="scanReasonModal = false" class="bg-[var(--surface)] rounded-[var(--radius-lg)] border border-[var(--border)] shadow-xl w-full max-w-md p-5">
            <h3 class="text-sm font-bold text-[var(--text)] mb-1">
                Alasan Scan Tidak Lengkap: <span x-text="scanAtt.name"></span>
            </h3>
            <p class="text-[11px] text-[var(--text-secondary)] mb-4">
                Alasan ini hanya dicatat untuk audit dan tidak mengubah jam atau status kehadiran. Kelengkapan scan tetap ditandai terpisah.
            </p>

            <form :action="'{{ url('attendances') }}/' + scanAtt.id + '/scan-reason'" method="POST" class="space-y-3 text-xs">
                @csrf
                <div>
                    <label class="block font-semibold text-[var(--text)] mb-1">Alasan (Wajib)</label>
                    <textarea name="override_reason" x-model="scanReason" rows="3" required placeholder="Contoh: Mesin pulang bermasalah / siswa lupa scan pulang"
                        class="w-full rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] p-2.5 text-xs text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none focus:ring-1 focus:ring-[var(--primary-accessible)]"></textarea>
                </div>

                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" @click="scanReasonModal = false" class="px-3.5 py-1.5 rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface)] hover:bg-[var(--surface-secondary)] text-xs font-semibold text-[var(--text-secondary)] transition-colors">
                        Batal
                    </button>
                    <button type="submit" class="px-3.5 py-1.5 rounded-[var(--radius-sm)] bg-[var(--primary)] text-xs font-semibold text-white hover:brightness-95 transition-colors">
                        Simpan Alasan
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Input Absensi Manual -->
    <div x-cloak x-show="manualModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-[#0f1623]/60 backdrop-blur-xs">
        <div @click.away="manualModal = false" class="bg-[var(--surface)] rounded-[var(--radius-lg)] border border-[var(--border)] shadow-xl w-full max-w-md p-5">
            <h3 class="text-sm font-bold text-[var(--text)] mb-1">Input Presensi Manual</h3>
            <p class="text-[11px] text-[var(--text-secondary)] mb-4">
                {{ $isWali ? 'Catat izin atau sakit untuk siswa di kelas yang Anda ampu.' : 'Catat presensi manual dengan alasan yang tercatat pada audit log.' }}
            </p>

            <form action="{{ route('attendances.manual') }}" method="POST" class="space-y-3 text-xs">
                @csrf
                <div>
                    <label class="block font-semibold text-[var(--text)] mb-1">Pilih Siswa</label>
                    <select name="student_id" required class="h-9 w-full rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] px-3 text-xs text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none focus:ring-1 focus:ring-[var(--primary-accessible)]">
                        <option value="">-- Pilih Siswa --</option>
                        @foreach($students as $st)
                            <option value="{{ $st->id }}">{{ $st->name }} ({{ $st->schoolClass?->name ?? '-' }})</option>
                        @endforeach
                    </select>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block font-semibold text-[var(--text)] mb-1">Tanggal</label>
                        <input type="date" name="school_date" value="{{ $selectedDate }}" required
                            class="h-9 w-full rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] px-3 text-xs text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none focus:ring-1 focus:ring-[var(--primary-accessible)]">
                    </div>
                    <div>
                        <label class="block font-semibold text-[var(--text)] mb-1">Status</label>
                        <select name="status" required class="h-9 w-full rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] px-3 text-xs text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none focus:ring-1 focus:ring-[var(--primary-accessible)]">
                            @foreach($manualStatuses as $manualStatus)
                                <option value="{{ $manualStatus }}">{{ \App\Models\Attendance::STATUS_LABELS[$manualStatus] ?? ucfirst($manualStatus) }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <p class="text-[10px] text-[var(--text-muted)]">
                    Input manual hanya untuk presensi tanpa bukti scan. Presensi yang sudah memiliki scan mengikuti data mesin.
                </p>

                <div>
                    <label class="block font-semibold text-[var(--text)] mb-1">Keterangan / Alasan (Wajib)</label>
                    <textarea name="override_reason" rows="2" required placeholder="Contoh: Izin acara keluarga / rawat inap"
                        class="w-full rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] p-2.5 text-xs text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none focus:ring-1 focus:ring-[var(--primary-accessible)]"></textarea>
                </div>

                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" @click="manualModal = false" class="px-3.5 py-1.5 rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface)] hover:bg-[var(--surface-secondary)] text-xs font-semibold text-[var(--text-secondary)] transition-colors">
                        Batal
                    </button>
                    <button type="submit" class="px-3.5 py-1.5 rounded-[var(--radius-sm)] bg-[var(--primary)] text-xs font-semibold text-white hover:brightness-95 transition-colors">
                        Simpan Presensi
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Form Koreksi Presensi Massal -->
    <div x-cloak x-show="bulkModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-[#0f1623]/60 backdrop-blur-xs">
        <div @click.away="bulkModal = false" class="bg-[var(--surface)] rounded-[var(--radius-lg)] border border-[var(--border)] shadow-xl w-full max-w-md p-5">
            <h3 class="text-sm font-bold text-[var(--text)] mb-1">
                Koreksi Presensi Massal (<span x-text="selected.length"></span> Siswa)
            </h3>
            <p class="text-[11px] text-[var(--text-secondary)] mb-4">
                Ubah status kehadiran untuk seluruh siswa yang dipilih sekaligus. Setiap perubahan akan dicatat permanen pada Audit Log.
            </p>

            <form action="{{ route('attendances.bulk-correct') }}" method="POST" class="space-y-3 text-xs"
                @submit.prevent="askConfirm({ title: 'Koreksi Massal', message: 'Terapkan status koreksi ke ' + selected.length + ' presensi siswa terpilih?', confirmLabel: 'Terapkan', variant: 'primary' }).then(ok => ok && $el.submit())">
                @csrf
                <template x-for="id in selected" :key="id">
                    <input type="hidden" name="ids[]" :value="id">
                </template>

                <div>
                    <label class="block font-semibold text-[var(--text)] mb-1">Ubah Status Menjadi</label>
                    <select name="status" x-model="bulkStatus" required class="h-9 w-full rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] px-3 text-xs text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none focus:ring-1 focus:ring-[var(--primary-accessible)] cursor-pointer">
                        @foreach($statusOptions as $bulkStatusOption)
                            <option value="{{ $bulkStatusOption }}">{{ \App\Models\Attendance::STATUS_LABELS[$bulkStatusOption] ?? ucfirst($bulkStatusOption) }}</option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-[10px] text-[var(--text-muted)]">
                        Status Izin, Sakit, dan Alpa hanya untuk presensi tanpa bukti scan. Presensi dengan scan tidak ikut dikoreksi.
                    </p>
                </div>

                <div>
                    <label class="block font-semibold text-[var(--text)] mb-1">Alasan Koreksi Bersama (Wajib)</label>
                    <textarea name="override_reason" x-model="bulkReason" rows="3" required placeholder="Contoh: Mengikuti Lomba O2SN Tingkat Kabupaten / Surat Izin Resmi Sekolah"
                        class="w-full rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] p-2.5 text-xs text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none focus:ring-1 focus:ring-[var(--primary-accessible)]"></textarea>
                </div>

                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" @click="bulkModal = false" class="px-3.5 py-1.5 rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface)] hover:bg-[var(--surface-secondary)] text-xs font-semibold text-[var(--text-secondary)] transition-colors cursor-pointer">
                        Batal
                    </button>
                    <button type="submit" class="px-3.5 py-1.5 rounded-[var(--radius-sm)] bg-[var(--primary)] text-xs font-semibold text-white hover:brightness-95 transition-colors cursor-pointer">
                        Terapkan Koreksi Massal
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

