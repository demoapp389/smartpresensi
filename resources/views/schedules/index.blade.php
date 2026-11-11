@extends('layouts.app')

@section('title', 'Jadwal & Hari Libur')
@section('page-pretitle', 'Konfigurasi Waktu')
@section('page-title', 'Pengaturan Jadwal Sekolah & Kalender Libur')
@section('page-subtitle', 'Kelola jendela scan masuk/pulang, toleransi menit, dan penetapan hari libur khusus / cuti bersama')

@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="transition-colors hover:text-[var(--text)]">Beranda</a>
    <span aria-hidden="true">/</span>
    <span>Konfigurasi Waktu</span>
    <span aria-hidden="true">/</span>
    <span class="font-medium text-[var(--text)]" aria-current="page">Jadwal &amp; Hari Libur</span>
@endsection

@section('content')
<div class="space-y-4" x-data="{
    activeTab: '{{ request('tab', 'schedules') }}',
    editModal: false,
    activeSchedule: {},
    editHolidayModal: false,
    activeHoliday: { id: '', name: '', start_date: '', end_date: '', description: '', is_active: true },
    editPeriodModal: false,
    activePeriod: { id: '', name: '', start_date: '', end_date: '', notes: '', is_active: true }
}">
    <!-- Tab Navigation (Gentelella tabs-pill) -->
    <div class="rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] p-2.5 shadow-[var(--shadow)] flex flex-wrap items-center justify-between gap-3 text-xs">
        <div class="overflow-x-auto w-full sm:w-auto">
            <div class="tabs-pill" role="tablist" aria-label="Pengaturan Jadwal">
                <button type="button" role="tab" @click="activeTab = 'schedules'"
                    :class="{ 'active': activeTab === 'schedules' }"
                    class="tab inline-flex items-center gap-2 cursor-pointer">
                    <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <span>Jadwal Mingguan</span>
                    <span class="px-1.5 py-0.2 rounded-full text-[10px] font-mono font-bold"
                          :class="activeTab === 'schedules' ? 'bg-[var(--primary)] text-white' : 'bg-[var(--surface-secondary)] text-[var(--text-secondary)]'">
                        {{ $schedules->count() }} Hari
                    </span>
                </button>

                <button type="button" role="tab" @click="activeTab = 'special'"
                    :class="{ 'active': activeTab === 'special' }"
                    class="tab inline-flex items-center gap-2 cursor-pointer">
                    <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364-6.364l-.707.707M6.343 17.657l-.707.707m12.728 0l-.707-.707M6.343 6.343l-.707-.707" />
                    </svg>
                    <span>Jadwal Khusus</span>
                    <span class="px-1.5 py-0.2 rounded-full text-[10px] font-mono font-bold"
                          :class="activeTab === 'special' ? 'bg-amber-500 text-white' : 'bg-[var(--surface-secondary)] text-[var(--text-secondary)]'">
                        {{ $periods->count() }}
                    </span>
                </button>

                <button type="button" role="tab" @click="activeTab = 'holidays'"
                    :class="{ 'active': activeTab === 'holidays' }"
                    class="tab inline-flex items-center gap-2 cursor-pointer">
                    <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                    </svg>
                    <span>Kalender Hari Libur Khusus</span>
                    <span class="px-1.5 py-0.2 rounded-full text-[10px] font-mono font-bold"
                          :class="activeTab === 'holidays' ? 'bg-rose-500 text-white' : 'bg-[var(--surface-secondary)] text-[var(--text-secondary)]'">
                        {{ $holidays->total() }}
                    </span>
                </button>

                <button type="button" role="tab" @click="activeTab = 'mulai'"
                    :class="{ 'active': activeTab === 'mulai' }"
                    class="tab inline-flex items-center gap-2 cursor-pointer">
                    <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <span>Mulai Sistem</span>
                    @if($attendanceStartDate)
                        <span class="px-1.5 py-0.2 rounded-full text-[10px] font-mono font-bold"
                              :class="activeTab === 'mulai' ? 'bg-emerald-500 text-white' : 'bg-emerald-50 text-emerald-700 border border-emerald-200'">
                            Aktif
                        </span>
                    @endif
                </button>
            </div>
        </div>

        <div class="text-[11px] text-[var(--text-muted)] flex items-center gap-1.5">
            <span class="size-2 rounded-full bg-emerald-500"></span>
            <span>Zona Waktu:</span>
            <strong class="text-[var(--text)] font-mono">Asia/Jakarta (WIB)</strong>
        </div>
    </div>

    <!-- TAB 1: Jadwal Mingguan -->
    <div x-show="activeTab === 'schedules'" class="space-y-4">
        <!-- Policy Banner Info -->
        <div class="rounded-[var(--radius)] border border-[var(--primary)]/30 bg-[var(--primary-soft)] p-3.5 text-xs text-[var(--text)] flex items-start gap-3 shadow-xs">
            <span class="p-1 rounded bg-[var(--primary)]/20 text-[var(--primary-accessible)] shrink-0 mt-0.5">
                <svg class="size-4" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/></svg>
            </span>
            <div>
                <span class="font-bold text-[var(--text)]">Ketentuan Engine Evaluasi Absensi &amp; Hari Kerja:</span>
                <ul class="list-disc list-inside mt-1 space-y-0.5 text-[var(--text-secondary)] text-[11px]">
                    <li><strong>Sekolah 5 Hari Kerja:</strong> Cukup klik <em>Ubah Jam</em> pada hari Sabtu lalu hilangkan centang <em>"Aktifkan jadwal untuk hari ini"</em>. Hari non-aktif otomatis dihitung sebagai libur mingguan (tidak terhitung Alpa).</li>
                    <li><strong>Jendela Scan:</strong> Scan hanya diproses di dalam jendela masuk (mulai&ndash;akhir scan masuk) atau jendela pulang (mulai&ndash;akhir scan pulang). Scan di luar jendela dan scan pada hari libur ditolak tanpa membuat data absensi.</li>
                    <li><strong>Periode Masuk:</strong> Scan pertama di jendela masuk menentukan status <em>Hadir Tepat Waktu</em> atau <em>Terlambat</em>.</li>
                    <li><strong>Toleransi:</strong> Keterlambatan dan pulang cepat dihitung dalam menit. Scan masuk melewati toleransi menjadi <em>Terlambat</em>; scan pulang lebih awal melewati toleransi menjadi <em>Pulang Cepat</em>.</li>
                    <li><strong>Scan Pulang Tanpa Scan Masuk:</strong> Disimpan sebagai <em>Perlu Tinjauan</em> karena tidak ada bukti scan masuk.</li>
                </ul>
            </div>
        </div>

        <!-- Schedules Grid -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3.5">
            @forelse($schedules as $sc)
            <div class="rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] p-4 shadow-[var(--shadow)] space-y-3 relative {{ !$sc->is_active ? 'opacity-70 bg-[var(--surface-secondary)]/50' : '' }}">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <span class="size-2 rounded-full {{ $sc->is_active ? 'bg-[var(--success)]' : 'bg-slate-300' }}"></span>
                        <h3 class="font-bold text-xs text-[var(--text)]">{{ $sc->day_name }}</h3>
                    </div>
                    <div class="flex items-center gap-1.5">
                        @if(!$sc->is_active)
                            <span class="px-1.5 py-0.5 rounded text-[9px] font-semibold bg-rose-50 text-rose-700 border border-rose-200">
                                Libur / Nonaktif
                            </span>
                        @endif
                        <span class="px-2 py-0.5 rounded text-[10px] font-semibold uppercase {{ $sc->day_pattern === 'jumat' ? 'bg-purple-50 text-purple-700 border border-purple-200' : ($sc->day_pattern === 'sabtu' ? 'bg-blue-50 text-blue-700 border border-blue-200' : 'bg-[var(--surface-secondary)] text-[var(--text-secondary)] border border-[var(--border)]') }}">
                            {{ $sc->day_pattern }}
                        </span>
                    </div>
                </div>

                <div class="space-y-1.5 text-xs pt-1 border-t border-[var(--border-subtle)] font-mono">
                    <div class="flex items-center justify-between">
                        <span class="text-[var(--text-secondary)] font-sans text-[11px]">Scan Masuk:</span>
                        <span class="text-[var(--text)] font-semibold text-xs">
                            {{ substr($sc->check_in_start, 0, 5) }}&ndash;{{ $sc->effectiveCheckInEnd() ? substr($sc->effectiveCheckInEnd(), 0, 5) : '-' }} WIB
                        </span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-[var(--text-secondary)] font-sans text-[11px]">Jam Masuk Resmi:</span>
                        <span class="text-emerald-700 font-bold text-xs">{{ substr($sc->start_at, 0, 5) }} WIB</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-[var(--text-secondary)] font-sans text-[11px]">Toleransi Terlambat:</span>
                        <span class="text-amber-700 font-bold text-xs">{{ $sc->lateToleranceMinutes() }} menit</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-[var(--text-secondary)] font-sans text-[11px]">Scan Pulang:</span>
                        <span class="text-blue-700 font-bold text-xs">
                            {{ substr($sc->check_out_start, 0, 5) }}&ndash;{{ $sc->effectiveCheckOutEnd() ? substr($sc->effectiveCheckOutEnd(), 0, 5) : '-' }} WIB
                        </span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-[var(--text-secondary)] font-sans text-[11px]">Jam Pulang Resmi:</span>
                        <span class="text-[var(--text)] font-semibold text-xs">{{ substr($sc->end_at, 0, 5) }} WIB</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-[var(--text-secondary)] font-sans text-[11px]">Toleransi Pulang Cepat:</span>
                        <span class="text-amber-700 font-bold text-xs">{{ $sc->earlyLeaveToleranceMinutes() }} menit</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-[var(--text-secondary)] font-sans text-[11px]">Batas Alpa:</span>
                        <span class="text-rose-700 font-semibold text-xs">
                            {{ $sc->alpa_cutoff ? substr($sc->alpa_cutoff, 0, 5).' WIB' : 'Otomatis' }}
                        </span>
                    </div>
                </div>

                <div class="pt-2 border-t border-[var(--border-subtle)] flex justify-end">
                    <button type="button"
                        @click="activeSchedule = {{ $sc->toJson() }}; editModal = true;"
                        class="inline-flex items-center px-2.5 py-1 rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface)] hover:bg-[var(--surface-secondary)] text-xs font-semibold text-[var(--text)] transition-colors cursor-pointer">
                        Ubah Jam
                    </button>
                </div>
            </div>
            @empty
            <div class="col-span-3 p-8 text-center bg-[var(--surface)] rounded-[var(--radius)] border border-dashed border-[var(--border)] text-[var(--text-muted)] text-xs">
                Belum ada jadwal sekolah.
            </div>
            @endforelse
        </div>
    </div>

    <!-- TAB 2: Kalender Hari Libur Khusus -->
    <div x-show="activeTab === 'holidays'" class="space-y-4">
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-4 items-start">
            <!-- Form Card Tambah Libur di Kiri (4 kolom lg) -->
            <div class="lg:col-span-4 rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] p-4 shadow-[var(--shadow)] space-y-3">
                <div>
                    <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-rose-50 text-rose-700 border border-rose-200 uppercase tracking-wide">
                        Kalender
                    </span>
                    <h3 class="text-sm font-bold text-[var(--text)] mt-2">Tambah Hari Libur Khusus</h3>
                    <p class="text-[11px] text-[var(--text-secondary)] mt-0.5 leading-relaxed">
                        Tentukan hari libur nasional, cuti bersama, atau libur semester. Pada hari libur, scan kehadiran siswa tidak dikenakan sanksi keterlambatan.
                    </p>
                </div>

                <form action="{{ route('schedules.holidays.store') }}" method="POST" class="space-y-3 text-xs pt-1">
                    @csrf
                    <div>
                        <label class="block font-semibold text-[var(--text)] mb-1">Nama Hari Libur</label>
                        <input type="text" name="name" required placeholder="Contoh: Hari Kemerdekaan RI Ke-81"
                            class="h-9 w-full rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] px-3 text-xs text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none focus:ring-1 focus:ring-[var(--primary-accessible)]">
                    </div>

                    <div class="grid grid-cols-2 gap-2.5">
                        <div>
                            <label class="block font-semibold text-[var(--text)] mb-1">Tanggal Mulai</label>
                            <input type="date" name="start_date" required value="{{ date('Y-m-d') }}"
                                class="h-9 w-full rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] px-2.5 text-xs text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none focus:ring-1 focus:ring-[var(--primary-accessible)]">
                        </div>
                        <div>
                            <label class="block font-semibold text-[var(--text)] mb-1">Tanggal Selesai</label>
                            <input type="date" name="end_date" required value="{{ date('Y-m-d') }}"
                                class="h-9 w-full rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] px-2.5 text-xs text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none focus:ring-1 focus:ring-[var(--primary-accessible)]">
                        </div>
                    </div>

                    <div>
                        <label class="block font-semibold text-[var(--text)] mb-1">Keterangan / Catatan (Opsional)</label>
                        <textarea name="description" rows="2" placeholder="Catatan libur atau surat keputusan sekolah..."
                            class="w-full rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] p-2.5 text-xs text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none focus:ring-1 focus:ring-[var(--primary-accessible)]"></textarea>
                    </div>

                    <div class="flex items-center gap-2 pt-1">
                        <input type="checkbox" id="holiday_active" name="is_active" value="1" checked class="size-4 rounded border-[var(--border)] text-[var(--primary-accessible)] focus:ring-[var(--primary-accessible)]">
                        <label for="holiday_active" class="text-[var(--text)] text-xs font-medium">Libur Aktif Berlaku</label>
                    </div>

                    <button type="submit" class="w-full py-2 bg-rose-600 hover:bg-rose-700 font-semibold rounded-[var(--radius-sm)] text-white transition-colors text-xs shadow-xs mt-2 cursor-pointer">
                        Simpan Hari Libur
                    </button>
                </form>
            </div>

            <!-- Tabel Daftar Hari Libur di Kanan (8 kolom lg) -->
            <div class="lg:col-span-8 min-w-0 rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] shadow-[var(--shadow)] overflow-hidden">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--border-subtle)] px-4 py-3">
                    <div>
                        <h3 class="text-xs font-bold text-[var(--text)]">Daftar Hari Libur Terdaftar</h3>
                        <p class="text-[11px] text-[var(--text-secondary)]">Kalender libur sekolah, cuti bersama, dan hari besar</p>
                    </div>
                    <span class="px-2 py-0.5 rounded text-[11px] font-semibold bg-rose-50 text-rose-700 border border-rose-200">
                        Total: {{ $holidays->total() }} Hari Libur
                    </span>
                </div>

                <div class="w-full overflow-x-auto">
                    <table class="min-w-[650px] w-full text-left text-xs">
                        <thead class="bg-[var(--surface-secondary)] text-[11px] font-semibold text-[var(--text-secondary)] uppercase tracking-wider border-b border-[var(--border-subtle)]">
                            <tr>
                                <th scope="col" class="px-4 py-2.5">Nama Libur</th>
                                <th scope="col" class="px-4 py-2.5">Periode Tanggal</th>
                                <th scope="col" class="px-4 py-2.5">Keterangan</th>
                                <th scope="col" class="px-4 py-2.5">Status</th>
                                <th scope="col" class="px-4 py-2.5 text-right">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[var(--border-subtle)] text-[13px]">
                            @forelse($holidays as $h)
                            <tr class="hover:bg-[var(--surface-secondary)]/50 transition-colors">
                                <td class="px-4 py-2.5 font-semibold text-[var(--text)]">
                                    <div class="flex items-center gap-1.5">
                                        <span class="size-2 rounded-full {{ $h->is_active ? 'bg-rose-500' : 'bg-slate-300' }}"></span>
                                        <span>{{ $h->name }}</span>
                                    </div>
                                </td>
                                <td class="px-4 py-2.5 font-mono text-xs whitespace-nowrap">
                                    @if($h->start_date->equalTo($h->end_date))
                                        <span class="font-bold text-[var(--text)]">{{ $h->start_date->format('d/m/Y') }}</span>
                                    @else
                                        <span class="font-bold text-[var(--text)]">{{ $h->start_date->format('d/m/Y') }}</span>
                                        <span class="text-[var(--text-muted)]">s/d</span>
                                        <span class="font-bold text-[var(--text)]">{{ $h->end_date->format('d/m/Y') }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2.5 text-xs text-[var(--text-secondary)] max-w-xs">
                                    {{ $h->description ?: '-' }}
                                </td>
                                <td class="px-4 py-2.5 whitespace-nowrap">
                                    @if(! $h->is_active)
                                        <span class="px-2 py-0.5 rounded text-[10px] font-semibold bg-gray-100 text-gray-600 border border-gray-200">Nonaktif</span>
                                    @elseif(now()->toDateString() >= $h->start_date->toDateString() && now()->toDateString() <= $h->end_date->toDateString())
                                        <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-amber-50 text-amber-700 border border-amber-200 animate-pulse">Sedang Libur</span>
                                    @elseif(now()->toDateString() < $h->start_date->toDateString())
                                        <span class="px-2 py-0.5 rounded text-[10px] font-semibold bg-sky-50 text-sky-700 border border-sky-200">Akan Datang</span>
                                    @else
                                        <span class="px-2 py-0.5 rounded text-[10px] font-semibold bg-slate-100 text-slate-600 border border-slate-200">Selesai</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2.5 text-right whitespace-nowrap">
                                    <div class="inline-flex items-center gap-1.5 justify-end">
                                        <button type="button"
                                            @click="activeHoliday = {
                                                id: {{ $h->id }},
                                                name: '{{ addslashes($h->name) }}',
                                                start_date: '{{ $h->start_date->format('Y-m-d') }}',
                                                end_date: '{{ $h->end_date->format('Y-m-d') }}',
                                                description: '{{ addslashes($h->description ?? '') }}',
                                                is_active: {{ $h->is_active ? 'true' : 'false' }}
                                            }; editHolidayModal = true;"
                                            class="inline-flex items-center px-2.5 py-1 rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface)] hover:bg-[var(--surface-secondary)] text-xs font-semibold text-[var(--text)] transition-colors shadow-2xs cursor-pointer">
                                            Edit
                                        </button>

                                        <form action="{{ route('schedules.holidays.destroy', $h) }}" method="POST" class="inline"
                                            data-confirm="Hapus hari libur {{ addslashes($h->name) }}?"
                                            data-confirm-title="Hapus Hari Libur"
                                            data-confirm-label="Hapus"
                                            data-confirm-variant="danger">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="inline-flex items-center px-2.5 py-1 rounded-[var(--radius-sm)] border border-rose-200 bg-rose-50 hover:bg-rose-100 text-xs font-semibold text-rose-700 transition-colors shadow-2xs cursor-pointer">
                                                Hapus
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="5" class="px-4 py-8 text-center text-xs text-[var(--text-muted)]">
                                    Belum ada hari libur khusus yang didaftarkan. Gunakan formulir di sebelah kiri untuk menambah kalender libur.
                                </td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if($holidays->hasPages())
                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-[var(--border-subtle)] px-4 py-3 text-xs bg-[var(--surface)]">
                    @include('partials.per-page', ['paginator' => $holidays])
                    <div>
                        {{ $holidays->appends(['tab' => 'holidays'])->links() }}
                    </div>
                </div>
                @endif
            </div>
        </div>
    </div>

    <!-- TAB 3: Jadwal Khusus (periode tanggal) -->
    <div x-show="activeTab === 'special'" class="space-y-4">
        <div class="rounded-[var(--radius)] border border-amber-200 bg-amber-50 p-3.5 text-xs text-amber-900 flex items-start gap-3">
            <span class="p-1 rounded bg-amber-500/20 text-amber-700 shrink-0 mt-0.5">
                <svg class="size-4" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/></svg>
            </span>
            <div>
                <span class="font-bold">Jadwal khusus hanya berlaku pada rentang tanggalnya.</span>
                <ul class="list-disc list-inside mt-1 space-y-0.5 text-amber-800 text-[11px]">
                    <li>Scan dan presensi yang sudah tercatat <strong>tidak dihitung ulang</strong> saat periode dibuat, diubah, atau dihapus.</li>
                    <li>Perubahan hanya memengaruhi scan baru pada tanggal yang dicakup periode.</li>
                    <li>Tanggal di luar periode tetap memakai jadwal mingguan reguler.</li>
                </ul>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-4 items-start">
            <div class="lg:col-span-4 rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] p-4 shadow-[var(--shadow)] space-y-3">
                <div>
                    <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-amber-50 text-amber-700 border border-amber-200 uppercase tracking-wide">
                        Periode
                    </span>
                    <h3 class="text-sm font-bold text-[var(--text)] mt-2">Tambah Jadwal Khusus</h3>
                    <p class="text-[11px] text-[var(--text-secondary)] mt-0.5 leading-relaxed">
                        Contoh: jadwal Ramadan, pekan ujian, atau kegiatan khusus lain. Jadwal mingguan reguler otomatis disalin sebagai titik awal.
                    </p>
                </div>

                <form action="{{ route('schedules.periods.store') }}" method="POST" class="space-y-3 text-xs pt-1">
                    @csrf
                    <div>
                        <label class="block font-semibold text-[var(--text)] mb-1">Nama Periode</label>
                        <input type="text" name="name" required maxlength="150" placeholder="Contoh: Jadwal Ramadan 1448 H"
                            class="h-9 w-full rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] px-3 text-xs text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none focus:ring-1 focus:ring-[var(--primary-accessible)]">
                    </div>

                    <div class="grid grid-cols-2 gap-2.5">
                        <div>
                            <label class="block font-semibold text-[var(--text)] mb-1">Tanggal Mulai</label>
                            <input type="date" name="start_date" required value="{{ date('Y-m-d') }}"
                                class="h-9 w-full rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] px-2.5 text-xs text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none focus:ring-1 focus:ring-[var(--primary-accessible)]">
                        </div>
                        <div>
                            <label class="block font-semibold text-[var(--text)] mb-1">Tanggal Selesai</label>
                            <input type="date" name="end_date" required value="{{ date('Y-m-d') }}"
                                class="h-9 w-full rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] px-2.5 text-xs text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none focus:ring-1 focus:ring-[var(--primary-accessible)]">
                        </div>
                    </div>

                    <div>
                        <label class="block font-semibold text-[var(--text)] mb-1">Catatan (Opsional)</label>
                        <textarea name="notes" rows="2" maxlength="500" placeholder="Surat keputusan atau keterangan periode..."
                            class="w-full rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] p-2.5 text-xs text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none focus:ring-1 focus:ring-[var(--primary-accessible)]"></textarea>
                    </div>

                    <div class="flex items-center gap-2 pt-1">
                        <input type="hidden" name="is_active" value="0">
                        <input type="checkbox" id="period_active" name="is_active" value="1" checked class="size-4 rounded border-[var(--border)] text-[var(--primary-accessible)] focus:ring-[var(--primary-accessible)]">
                        <label for="period_active" class="text-[var(--text)] text-xs font-medium">Periode Aktif Berlaku</label>
                    </div>

                    <button type="submit" class="w-full py-2 bg-amber-600 hover:bg-amber-700 font-semibold rounded-[var(--radius-sm)] text-white transition-colors text-xs shadow-xs mt-2 cursor-pointer">
                        Simpan Periode
                    </button>
                </form>
            </div>

            <div class="lg:col-span-8 min-w-0 space-y-3">
                @forelse($periods as $period)
                    @php
                        $isCurrentPeriod = $period->is_active
                            && now()->toDateString() >= $period->start_date->toDateString()
                            && now()->toDateString() <= $period->end_date->toDateString();
                    @endphp
                    <div class="rounded-[var(--radius)] border {{ $isCurrentPeriod ? 'border-amber-300' : 'border-[var(--border)]' }} bg-[var(--surface)] shadow-[var(--shadow)] overflow-hidden">
                        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-[var(--border-subtle)] px-4 py-3">
                            <div>
                                <div class="flex flex-wrap items-center gap-1.5">
                                    <h3 class="text-xs font-bold text-[var(--text)]">{{ $period->name }}</h3>
                                    @if($isCurrentPeriod)
                                        <span class="px-1.5 py-0.5 rounded text-[9px] font-bold bg-amber-100 text-amber-800 border border-amber-300">Sedang Berlaku</span>
                                    @elseif(! $period->is_active)
                                        <span class="px-1.5 py-0.5 rounded text-[9px] font-semibold bg-slate-100 text-slate-600 border border-slate-200">Nonaktif</span>
                                    @elseif(now()->toDateString() < $period->start_date->toDateString())
                                        <span class="px-1.5 py-0.5 rounded text-[9px] font-semibold bg-sky-50 text-sky-700 border border-sky-200">Akan Datang</span>
                                    @else
                                        <span class="px-1.5 py-0.5 rounded text-[9px] font-semibold bg-slate-100 text-slate-600 border border-slate-200">Selesai</span>
                                    @endif
                                </div>
                                <p class="text-[11px] text-[var(--text-secondary)] mt-0.5 font-mono">
                                    {{ $period->start_date->format('d/m/Y') }} &ndash; {{ $period->end_date->format('d/m/Y') }}
                                </p>
                            </div>
                            <div class="flex items-center gap-1.5">
                                <button type="button"
                                    @click="activePeriod = {
                                        id: {{ $period->id }},
                                        name: @js($period->name),
                                        start_date: '{{ $period->start_date->format('Y-m-d') }}',
                                        end_date: '{{ $period->end_date->format('Y-m-d') }}',
                                        notes: @js($period->notes ?? ''),
                                        is_active: {{ $period->is_active ? 'true' : 'false' }}
                                    }; editPeriodModal = true;"
                                    class="inline-flex items-center px-2.5 py-1 rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface)] hover:bg-[var(--surface-secondary)] text-xs font-semibold text-[var(--text)] transition-colors cursor-pointer">
                                    Edit Periode
                                </button>
                                <form action="{{ route('schedules.periods.destroy', $period) }}" method="POST" class="inline"
                                    data-confirm="Hapus periode {{ addslashes($period->name) }}? Scan baru akan kembali memakai jadwal reguler. Presensi lama tidak berubah."
                                    data-confirm-title="Hapus Jadwal Khusus"
                                    data-confirm-label="Hapus"
                                    data-confirm-variant="danger">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="inline-flex items-center px-2.5 py-1 rounded-[var(--radius-sm)] border border-rose-200 bg-rose-50 hover:bg-rose-100 text-xs font-semibold text-rose-700 transition-colors cursor-pointer">
                                        Hapus
                                    </button>
                                </form>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-2 p-3">
                            @foreach($period->schedules as $sc)
                                <div class="rounded-[var(--radius-sm)] border border-[var(--border-subtle)] bg-[var(--surface-secondary)]/40 p-2.5 {{ ! $sc->is_active ? 'opacity-60' : '' }}">
                                    <div class="flex items-center justify-between">
                                        <span class="text-[11px] font-bold text-[var(--text)]">{{ $sc->day_name }}</span>
                                        @if(! $sc->is_active)
                                            <span class="text-[9px] font-semibold text-rose-700">Libur</span>
                                        @endif
                                    </div>
                                    <div class="mt-1 space-y-0.5 text-[10px] font-mono text-[var(--text-secondary)]">
                                        <div>Masuk: {{ substr($sc->check_in_start, 0, 5) }}&ndash;{{ $sc->effectiveCheckInEnd() ? substr($sc->effectiveCheckInEnd(), 0, 5) : '-' }}</div>
                                        <div>Pulang: {{ substr($sc->check_out_start, 0, 5) }}&ndash;{{ $sc->effectiveCheckOutEnd() ? substr($sc->effectiveCheckOutEnd(), 0, 5) : '-' }}</div>
                                    </div>
                                    <button type="button"
                                        @click="activeSchedule = {{ $sc->toJson() }}; editModal = true;"
                                        class="mt-1.5 w-full px-2 py-1 rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface)] hover:bg-[var(--surface-secondary)] text-[10px] font-semibold text-[var(--text)] transition-colors cursor-pointer">
                                        Ubah Jam
                                    </button>
                                </div>
                            @endforeach
                        </div>

                        @if($period->notes)
                            <p class="px-4 pb-3 text-[10px] text-[var(--text-muted)]">{{ $period->notes }}</p>
                        @endif
                    </div>
                @empty
                    <div class="p-8 text-center bg-[var(--surface)] rounded-[var(--radius)] border border-dashed border-[var(--border)] text-[var(--text-muted)] text-xs">
                        Belum ada jadwal khusus. Tambahkan periode di formulir sebelah kiri.
                    </div>
                @endforelse
            </div>
        </div>
    </div>

    <!-- TAB 4: Mulai Sistem -->
    <div x-show="activeTab === 'mulai'" class="space-y-4">
        <div class="rounded-[var(--radius)] border border-emerald-200 bg-emerald-50 p-3.5 text-xs text-emerald-900 flex items-start gap-3">
            <span class="p-1 rounded bg-emerald-500/20 text-emerald-700 shrink-0 mt-0.5">
                <svg class="size-4" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/></svg>
            </span>
            <div>
                <span class="font-bold">Tanggal mulai sistem absensi berlaku global.</span>
                <ul class="list-disc list-inside mt-1 space-y-0.5 text-emerald-800 text-[11px]">
                    <li>Data presensi dan riwayat scan <strong>sebelum tanggal ini disembunyikan</strong>, bukan dihapus, sehingga tetap tersedia untuk audit.</li>
                    <li>Sistem tidak membuat Alpa otomatis, baris <em>Perlu Tinjauan</em>, atau catatan manual pada tanggal sebelum tanggal mulai.</li>
                    <li>Menurunkan atau menghapus tanggal mulai akan menampilkan kembali data lama tanpa perhitungan ulang.</li>
                </ul>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-4 items-start">
            <div class="lg:col-span-5 rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] p-4 shadow-[var(--shadow)] space-y-3">
                <div>
                    <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200 uppercase tracking-wide">
                        Periode Sistem
                    </span>
                    <h3 class="text-sm font-bold text-[var(--text)] mt-2">Tanggal Mulai Absensi</h3>
                    <p class="text-[11px] text-[var(--text-secondary)] mt-0.5 leading-relaxed">
                        Pilih tanggal pertama sistem absensi digunakan. Kosongkan batas jika seluruh data lama tetap ingin ditampilkan.
                    </p>
                </div>

                <div class="rounded-[var(--radius-sm)] border border-[var(--border-subtle)] bg-[var(--surface-secondary)]/50 px-3 py-2 text-[11px] flex items-center justify-between gap-2">
                    <span class="text-[var(--text-secondary)]">Status saat ini:</span>
                    @if($attendanceStartDate)
                        <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200 font-mono">
                            {{ $attendanceStartDate->format('d/m/Y') }}
                        </span>
                    @else
                        <span class="px-2 py-0.5 rounded text-[10px] font-semibold bg-slate-100 text-slate-600 border border-slate-200">
                            Tanpa batas
                        </span>
                    @endif
                </div>

                <form action="{{ route('schedules.attendance-start.update') }}" method="POST" class="space-y-3 text-xs pt-1">
                    @csrf
                    @method('PUT')
                    <div>
                        <label for="attendance_start_date" class="block font-semibold text-[var(--text)] mb-1">Tanggal Mulai Sistem</label>
                        <input type="date" id="attendance_start_date" name="attendance_start_date"
                            value="{{ $attendanceStartDate?->format('Y-m-d') }}"
                            class="h-9 w-full rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] px-3 text-xs text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none focus:ring-1 focus:ring-[var(--primary-accessible)]">
                        @error('attendance_start_date')
                            <p class="mt-1 text-[11px] text-rose-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <button type="submit" class="w-full py-2 bg-emerald-600 hover:bg-emerald-700 font-semibold rounded-[var(--radius-sm)] text-white transition-colors text-xs shadow-xs mt-2 cursor-pointer">
                        Simpan Tanggal Mulai
                    </button>
                </form>

                @if($attendanceStartDate)
                    <form action="{{ route('schedules.attendance-start.update') }}" method="POST"
                        data-confirm="Hapus batas tanggal mulai? Seluruh data presensi lama akan kembali ditampilkan."
                        data-confirm-title="Hapus Batas Tanggal"
                        data-confirm-label="Hapus Batas"
                        data-confirm-variant="danger">
                        @csrf
                        @method('PUT')
                        <input type="hidden" name="attendance_start_date" value="">
                        <button type="submit" class="w-full py-2 border border-rose-200 bg-rose-50 hover:bg-rose-100 font-semibold rounded-[var(--radius-sm)] text-rose-700 transition-colors text-xs cursor-pointer">
                            Hapus Batas Tanggal
                        </button>
                    </form>
                @endif
            </div>

            <div class="lg:col-span-7 rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] p-4 shadow-[var(--shadow)] space-y-3">
                <h3 class="text-xs font-bold text-[var(--text)]">Dampak Pengaturan</h3>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5 text-[11px]">
                    <div class="rounded-[var(--radius-sm)] border border-[var(--border-subtle)] p-3 space-y-1">
                        <p class="font-semibold text-[var(--text)]">Disembunyikan</p>
                        <p class="text-[var(--text-secondary)]">Presensi, rekap laporan, dashboard, riwayat scan, dan antrean tinjauan sebelum tanggal mulai.</p>
                    </div>
                    <div class="rounded-[var(--radius-sm)] border border-[var(--border-subtle)] p-3 space-y-1">
                        <p class="font-semibold text-[var(--text)]">Tidak dibuat otomatis</p>
                        <p class="text-[var(--text-secondary)]">Alpa virtual, Auto-Alpa H+1, materialisasi Perlu Tinjauan, dan presensi manual pada tanggal lama.</p>
                    </div>
                    <div class="rounded-[var(--radius-sm)] border border-[var(--border-subtle)] p-3 space-y-1">
                        <p class="font-semibold text-[var(--text)]">Scan lama</p>
                        <p class="text-[var(--text-secondary)]">Tetap tersimpan sebagai audit, namun ditolak saat diproses dan tidak muncul di daftar.</p>
                    </div>
                    <div class="rounded-[var(--radius-sm)] border border-[var(--border-subtle)] p-3 space-y-1">
                        <p class="font-semibold text-[var(--text)]">Tanpa perhitungan ulang</p>
                        <p class="text-[var(--text-secondary)]">Mengubah tanggal mulai tidak menghapus atau menghitung ulang data yang sudah ada.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Edit Jadwal Mingguan -->
    <div x-cloak x-show="editModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-[#0f1623]/60 backdrop-blur-xs">
        <div @click.away="editModal = false" class="bg-[var(--surface)] rounded-[var(--radius-lg)] border border-[var(--border)] shadow-xl w-full max-w-md p-5">
            <div class="flex items-center justify-between pb-3 border-b border-[var(--border-subtle)] mb-4">
                <div>
                    <h3 class="text-sm font-bold text-[var(--text)]">
                        Ubah Jadwal Hari <span x-text="activeSchedule.day_name"></span>
                    </h3>
                    <p class="text-[11px] text-[var(--text-secondary)] mt-0.5">Atur jendela scan masuk/pulang dan toleransi menit</p>
                </div>
                <button type="button" @click="editModal = false" class="text-[var(--text-muted)] hover:text-[var(--text)] p-1 rounded transition-colors" aria-label="Tutup">
                    <svg class="size-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>

            <form :action="'{{ url('schedules') }}/' + activeSchedule.id" method="POST" class="space-y-3.5 text-xs">
                @csrf
                @method('PUT')
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block font-semibold text-[var(--text)] mb-1">Mulai Scan Masuk</label>
                        <input type="text" name="check_in_start" x-model="activeSchedule.check_in_start" required placeholder="06:00"
                            class="h-9 w-full rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] px-3 font-mono text-xs text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none focus:ring-1 focus:ring-[var(--primary-accessible)]">
                    </div>
                    <div>
                        <label class="block font-semibold text-[var(--text)] mb-1">Akhir Scan Masuk</label>
                        <input type="text" name="check_in_end" x-model="activeSchedule.check_in_end" placeholder="09:00"
                            class="h-9 w-full rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] px-3 font-mono text-xs text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none focus:ring-1 focus:ring-[var(--primary-accessible)]">
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block font-semibold text-[var(--text)] mb-1">Jam Masuk Resmi</label>
                        <input type="text" name="start_at" x-model="activeSchedule.start_at" required placeholder="07:00"
                            class="h-9 w-full rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] px-3 font-mono text-xs text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none focus:ring-1 focus:ring-[var(--primary-accessible)]">
                    </div>
                    <div>
                        <label class="block font-semibold text-[var(--text)] mb-1">Toleransi Terlambat (menit)</label>
                        <input type="number" name="late_tolerance_minutes" x-model.number="activeSchedule.late_tolerance_minutes" min="0" max="240" step="1" placeholder="15"
                            class="h-9 w-full rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] px-3 font-mono text-xs text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none focus:ring-1 focus:ring-[var(--primary-accessible)]">
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block font-semibold text-[var(--text)] mb-1">Mulai Scan Pulang</label>
                        <input type="text" name="check_out_start" x-model="activeSchedule.check_out_start" required placeholder="14:00"
                            class="h-9 w-full rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] px-3 font-mono text-xs text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none focus:ring-1 focus:ring-[var(--primary-accessible)]">
                    </div>
                    <div>
                        <label class="block font-semibold text-[var(--text)] mb-1">Akhir Scan Pulang</label>
                        <input type="text" name="check_out_end" x-model="activeSchedule.check_out_end" placeholder="17:00"
                            class="h-9 w-full rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] px-3 font-mono text-xs text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none focus:ring-1 focus:ring-[var(--primary-accessible)]">
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block font-semibold text-[var(--text)] mb-1">Jam Pulang Resmi</label>
                        <input type="text" name="end_at" x-model="activeSchedule.end_at" required placeholder="16:00"
                            class="h-9 w-full rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] px-3 font-mono text-xs text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none focus:ring-1 focus:ring-[var(--primary-accessible)]">
                    </div>
                    <div>
                        <label class="block font-semibold text-[var(--text)] mb-1">Toleransi Pulang Cepat (menit)</label>
                        <input type="number" name="early_leave_tolerance_minutes" x-model.number="activeSchedule.early_leave_tolerance_minutes" min="0" max="240" step="1" placeholder="0"
                            class="h-9 w-full rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] px-3 font-mono text-xs text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none focus:ring-1 focus:ring-[var(--primary-accessible)]">
                    </div>
                </div>

                <p class="text-[10px] text-[var(--text-muted)] leading-relaxed">
                    Scan di luar jendela masuk/pulang ditolak. Hari libur tidak membuat data absensi. Toleransi dihitung dalam menit.
                </p>

                <div>
                    <label class="block font-semibold text-[var(--text)] mb-1">Batas Alpa (opsional)</label>
                    <input type="text" name="alpa_cutoff" x-model="activeSchedule.alpa_cutoff" placeholder="Kosongkan untuk otomatis setelah akhir scan pulang"
                        class="h-9 w-full rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] px-3 font-mono text-xs text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none focus:ring-1 focus:ring-[var(--primary-accessible)]">
                    <p class="text-[10px] text-[var(--text-muted)] mt-1">Siswa tanpa scan setelah batas ini dianggap Alpa pada laporan.</p>
                </div>

                <div>
                    <label class="block font-semibold text-[var(--text)] mb-1">Pola Jadwal</label>
                    <input type="text" name="day_pattern" x-model="activeSchedule.day_pattern" required
                        class="h-9 w-full rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] px-3 text-xs text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none focus:ring-1 focus:ring-[var(--primary-accessible)]">
                </div>

                <div class="flex items-center gap-2 pt-1">
                    <input type="hidden" name="is_active" value="0">
                    <input type="checkbox" id="sc_active" name="is_active" value="1" x-model="activeSchedule.is_active" class="size-4 rounded border-[var(--border)] text-[var(--primary-accessible)] focus:ring-[var(--primary-accessible)]">
                    <label for="sc_active" class="text-[var(--text)] text-xs font-medium">Aktifkan jadwal untuk hari ini</label>
                </div>

                <div class="flex justify-end gap-2 pt-3 border-t border-[var(--border-subtle)]">
                    <button type="button" @click="editModal = false" class="px-3.5 py-1.5 rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface)] hover:bg-[var(--surface-secondary)] text-xs font-semibold text-[var(--text-secondary)] transition-colors cursor-pointer">
                        Batal
                    </button>
                    <button type="submit" class="px-3.5 py-1.5 rounded-[var(--radius-sm)] bg-[var(--primary)] text-xs font-semibold text-white hover:brightness-95 transition-colors cursor-pointer">
                        Simpan Perubahan
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Edit Hari Libur Khusus -->
    <div x-cloak x-show="editHolidayModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-[#0f1623]/60 backdrop-blur-xs">
        <div @click.away="editHolidayModal = false" class="bg-[var(--surface)] rounded-[var(--radius-lg)] border border-[var(--border)] shadow-xl w-full max-w-md p-5">
            <div class="flex items-center justify-between pb-3 border-b border-[var(--border-subtle)] mb-4">
                <div>
                    <h3 class="text-sm font-bold text-[var(--text)]">
                        Edit Hari Libur: <span x-text="activeHoliday.name"></span>
                    </h3>
                    <p class="text-[11px] text-[var(--text-secondary)] mt-0.5">Perbarui tanggal atau status keaktifan hari libur</p>
                </div>
                <button type="button" @click="editHolidayModal = false" class="text-[var(--text-muted)] hover:text-[var(--text)] p-1 rounded transition-colors" aria-label="Tutup">
                    <svg class="size-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>

            <form :action="'{{ url('schedules/holidays') }}/' + activeHoliday.id" method="POST" class="space-y-3.5 text-xs">
                @csrf
                @method('PUT')
                <div>
                    <label class="block font-semibold text-[var(--text)] mb-1">Nama Hari Libur</label>
                    <input type="text" name="name" x-model="activeHoliday.name" required
                        class="h-9 w-full rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] px-3 text-xs text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none focus:ring-1 focus:ring-[var(--primary-accessible)]">
                </div>

                <div class="grid grid-cols-2 gap-2.5">
                    <div>
                        <label class="block font-semibold text-[var(--text)] mb-1">Tanggal Mulai</label>
                        <input type="date" name="start_date" x-model="activeHoliday.start_date" required
                            class="h-9 w-full rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] px-2.5 text-xs text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none focus:ring-1 focus:ring-[var(--primary-accessible)]">
                    </div>
                    <div>
                        <label class="block font-semibold text-[var(--text)] mb-1">Tanggal Selesai</label>
                        <input type="date" name="end_date" x-model="activeHoliday.end_date" required
                            class="h-9 w-full rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] px-2.5 text-xs text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none focus:ring-1 focus:ring-[var(--primary-accessible)]">
                    </div>
                </div>

                <div>
                    <label class="block font-semibold text-[var(--text)] mb-1">Keterangan / Catatan</label>
                    <textarea name="description" rows="2" x-model="activeHoliday.description"
                        class="w-full rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] p-2.5 text-xs text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none focus:ring-1 focus:ring-[var(--primary-accessible)]"></textarea>
                </div>

                <div class="flex items-center gap-2 pt-1">
                    <input type="hidden" name="is_active" value="0">
                    <input type="checkbox" id="edit_holiday_active" name="is_active" value="1" x-model="activeHoliday.is_active" class="size-4 rounded border-[var(--border)] text-[var(--primary-accessible)] focus:ring-[var(--primary-accessible)]">
                    <label for="edit_holiday_active" class="text-[var(--text)] text-xs font-medium">Libur Aktif Berlaku</label>
                </div>

                <div class="flex justify-end gap-2 pt-3 border-t border-[var(--border-subtle)]">
                    <button type="button" @click="editHolidayModal = false" class="px-3.5 py-1.5 rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface)] hover:bg-[var(--surface-secondary)] text-xs font-semibold text-[var(--text-secondary)] transition-colors cursor-pointer">
                        Batal
                    </button>
                    <button type="submit" class="px-3.5 py-1.5 rounded-[var(--radius-sm)] bg-rose-600 hover:bg-rose-700 text-xs font-semibold text-white transition-colors cursor-pointer">
                        Simpan Perubahan
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Edit Periode Jadwal Khusus -->
    <div x-cloak x-show="editPeriodModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-[#0f1623]/60 backdrop-blur-xs">
        <div @click.away="editPeriodModal = false" class="bg-[var(--surface)] rounded-[var(--radius-lg)] border border-[var(--border)] shadow-xl w-full max-w-md p-5">
            <div class="flex items-center justify-between pb-3 border-b border-[var(--border-subtle)] mb-4">
                <div>
                    <h3 class="text-sm font-bold text-[var(--text)]">
                        Edit Periode: <span x-text="activePeriod.name"></span>
                    </h3>
                    <p class="text-[11px] text-[var(--text-secondary)] mt-0.5">Ubah nama, rentang tanggal, atau status periode</p>
                </div>
                <button type="button" @click="editPeriodModal = false" class="text-[var(--text-muted)] hover:text-[var(--text)] p-1 rounded transition-colors" aria-label="Tutup">
                    <svg class="size-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>

            <form :action="'{{ url('schedules/periods') }}/' + activePeriod.id" method="POST" class="space-y-3.5 text-xs">
                @csrf
                @method('PUT')
                <div>
                    <label class="block font-semibold text-[var(--text)] mb-1">Nama Periode</label>
                    <input type="text" name="name" x-model="activePeriod.name" required maxlength="150"
                        class="h-9 w-full rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] px-3 text-xs text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none focus:ring-1 focus:ring-[var(--primary-accessible)]">
                </div>

                <div class="grid grid-cols-2 gap-2.5">
                    <div>
                        <label class="block font-semibold text-[var(--text)] mb-1">Tanggal Mulai</label>
                        <input type="date" name="start_date" x-model="activePeriod.start_date" required
                            class="h-9 w-full rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] px-2.5 text-xs text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none focus:ring-1 focus:ring-[var(--primary-accessible)]">
                    </div>
                    <div>
                        <label class="block font-semibold text-[var(--text)] mb-1">Tanggal Selesai</label>
                        <input type="date" name="end_date" x-model="activePeriod.end_date" required
                            class="h-9 w-full rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] px-2.5 text-xs text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none focus:ring-1 focus:ring-[var(--primary-accessible)]">
                    </div>
                </div>

                <div>
                    <label class="block font-semibold text-[var(--text)] mb-1">Catatan (Opsional)</label>
                    <textarea name="notes" rows="2" x-model="activePeriod.notes" maxlength="500"
                        class="w-full rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] p-2.5 text-xs text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none focus:ring-1 focus:ring-[var(--primary-accessible)]"></textarea>
                </div>

                <div class="flex items-center gap-2 pt-1">
                    <input type="hidden" name="is_active" value="0">
                    <input type="checkbox" id="edit_period_active" name="is_active" value="1" x-model="activePeriod.is_active" class="size-4 rounded border-[var(--border)] text-[var(--primary-accessible)] focus:ring-[var(--primary-accessible)]">
                    <label for="edit_period_active" class="text-[var(--text)] text-xs font-medium">Periode Aktif Berlaku</label>
                </div>

                <div class="flex justify-end gap-2 pt-3 border-t border-[var(--border-subtle)]">
                    <button type="button" @click="editPeriodModal = false" class="px-3.5 py-1.5 rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface)] hover:bg-[var(--surface-secondary)] text-xs font-semibold text-[var(--text-secondary)] transition-colors cursor-pointer">
                        Batal
                    </button>
                    <button type="submit" class="px-3.5 py-1.5 rounded-[var(--radius-sm)] bg-amber-600 hover:bg-amber-700 text-xs font-semibold text-white transition-colors cursor-pointer">
                        Simpan Perubahan
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

