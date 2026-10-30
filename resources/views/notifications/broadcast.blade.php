@extends('layouts.app')

@section('title', 'Siaran Pengumuman WhatsApp')
@section('page-pretitle', 'WhatsApp Gateway')
@section('page-title', 'Siaran Pengumuman Massal')
@section('page-subtitle', 'Kirimkan pesan pengumuman atau informasi sekolah secara serentak ke nomor WhatsApp orang tua/wali murid')

@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="transition-colors hover:text-[var(--text)]">Beranda</a>
    <span aria-hidden="true">/</span>
    <span>WhatsApp</span>
    <span aria-hidden="true">/</span>
    <span class="font-medium text-[var(--text)]" aria-current="page">Siaran Pengumuman</span>
@endsection



@section('content')
<div class="space-y-4" x-data="broadcastManager()">

    <!-- Progress Bar Task Runner (Jika Sedang Berjalan) -->
    <div x-data="taskRunner({
            progressUrl: '/api/v1/progress',
            reloadOnDone: true
         })"
         @start-broadcast-task.window="watch($event.detail.uuid, $event.detail.token, 'Mengirim Siaran Pengumuman')"
         id="broadcast-task-container">
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-12 gap-5 items-start">
        <!-- Kolom Form Siaran (7 Kolom) -->
        <div class="lg:col-span-7">
            <form method="POST"
                  action="{{ route('whatsapp.broadcast.store') }}"
                  @submit.prevent="handleSubmit($el)"
                  class="rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] shadow-[var(--shadow)] overflow-hidden">
                @csrf

                <div class="border-b border-[var(--border-subtle)] px-5 py-3.5">
                    <h2 class="text-xs font-bold text-[var(--text)] uppercase tracking-wider">Komposer Pesan Siaran</h2>
                    <p class="text-[11px] text-[var(--text-muted)] mt-0.5">Pilih target kelas dan ketikkan materi pengumuman yang ingin disiarkan.</p>
                </div>

                <div class="p-5 space-y-4 text-xs">
                    <!-- Pilihan Target Penerima -->
                    <div>
                        <label class="block font-semibold text-[var(--text)] mb-2">Target Penerima <span class="text-rose-500">*</span></label>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                            <label class="flex items-center gap-2.5 p-3 rounded-[var(--radius-sm)] border cursor-pointer transition-colors"
                                   :class="targetType === 'all' ? 'border-[var(--primary)] bg-[var(--primary)]/5 text-[var(--text)] font-semibold' : 'border-[var(--border)] bg-[var(--surface)] text-[var(--text-secondary)]'">
                                <input type="radio" name="target_type" value="all" x-model="targetType" @change="fetchCounts()" class="text-[var(--primary)] focus:ring-[var(--primary)]">
                                <div>
                                    <div class="text-xs">Seluruh Sekolah</div>
                                    <div class="text-[10px] text-[var(--text-muted)] font-normal">Semua siswa aktif di semua kelas</div>
                                </div>
                            </label>

                            <label class="flex items-center gap-2.5 p-3 rounded-[var(--radius-sm)] border cursor-pointer transition-colors"
                                   :class="targetType === 'class' ? 'border-[var(--primary)] bg-[var(--primary)]/5 text-[var(--text)] font-semibold' : 'border-[var(--border)] bg-[var(--surface)] text-[var(--text-secondary)]'">
                                <input type="radio" name="target_type" value="class" x-model="targetType" @change="fetchCounts()" class="text-[var(--primary)] focus:ring-[var(--primary)]">
                                <div>
                                    <div class="text-xs">Pilih Kelas Tertentu</div>
                                    <div class="text-[10px] text-[var(--text-muted)] font-normal">Spesifik pada satu atau beberapa kelas</div>
                                </div>
                            </label>
                        </div>
                    </div>

                    <!-- Checklist Kelas (Hanya tampil jika targetType === 'class') -->
                    <div x-show="targetType === 'class'" x-cloak x-transition class="space-y-2 p-3.5 rounded-[var(--radius-sm)] bg-[var(--surface-secondary)]/50 border border-[var(--border-subtle)]">
                        <div class="flex items-center justify-between">
                            <span class="font-semibold text-[var(--text)] text-[11px]">Pilih Kelas Tujuan:</span>
                            <div class="flex items-center gap-2 text-[10px]">
                                <button type="button" @click="selectAllClasses(true)" class="text-[var(--primary)] hover:underline cursor-pointer">Pilih Semua</button>
                                <span class="text-[var(--text-muted)]">&bull;</span>
                                <button type="button" @click="selectAllClasses(false)" class="text-[var(--text-secondary)] hover:underline cursor-pointer">Batal Semua</button>
                            </div>
                        </div>

                        <div class="grid grid-cols-2 sm:grid-cols-3 gap-2 max-h-48 overflow-y-auto p-1" data-autohide-scrollbar>
                            @foreach($classes as $c)
                            <label class="flex items-center gap-2 text-xs p-1.5 rounded hover:bg-[var(--surface)] transition-colors cursor-pointer select-none">
                                <input type="checkbox"
                                       name="class_ids[]"
                                       value="{{ $c->id }}"
                                       x-model="selectedClasses"
                                       @change="fetchCounts()"
                                       class="rounded border-[var(--border)] text-[var(--primary)] focus:ring-[var(--primary)]">
                                <span class="truncate text-[var(--text)]">{{ $c->name }}</span>
                                <span class="text-[10px] text-[var(--text-muted)]">({{ $c->students_count }})</span>
                            </label>
                            @endforeach
                        </div>
                    </div>

                    <!-- Template Cepat / Draf Pengumuman -->
                    <div>
                        <div class="flex items-center justify-between mb-1">
                            <label class="font-semibold text-[var(--text)]">Gunakan Draf Pengumuman Cepat:</label>
                            <span class="text-[10px] text-[var(--text-muted)]">Opsional</span>
                        </div>
                        <select @change="applyDraft($event.target.value)" class="w-full rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface)] px-3 py-2 text-xs text-[var(--text)] focus:border-[var(--primary)] focus:outline-none focus:ring-1 focus:ring-[var(--primary)]">
                            <option value="">-- Ketik Pengumuman Baru / Pilih Contoh Draf --</option>
                            <option value="libur">Pengumuman Libur Hari Raya / Nasional</option>
                            <option value="ujian">Pemberitahuan Ujian Penilaian Akhir Semester (PAS)</option>
                            <option value="rapat">Undangan Rapat / Pertemuan Orang Tua Wali Murid</option>
                            <option value="disiplin">Imbauan Ketertiban, Jam Masuk &amp; Seragam Sekolah</option>
                        </select>
                    </div>

                    <!-- Kotak Pesan Pengumuman -->
                    <div>
                        <label for="broadcast_message" class="block font-semibold text-[var(--text)] mb-1">
                            Isi Pesan Pengumuman <span class="text-rose-500">*</span>
                        </label>
                        <textarea id="broadcast_message"
                                  name="message"
                                  rows="6"
                                  x-model="messageText"
                                  required
                                  class="w-full rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface)] p-3 text-xs font-mono text-[var(--text)] leading-relaxed focus:border-[var(--primary)] focus:outline-none focus:ring-1 focus:ring-[var(--primary)]"
                                  placeholder="Tuliskan isi pengumuman sekolah di sini..."></textarea>
                        <div class="mt-1 flex items-center justify-between text-[11px] text-[var(--text-muted)]">
                            <span>Format tebal: *kata*, miring: _kata_</span>
                            <span x-text="messageText.length + ' / 2000 karakter'"></span>
                        </div>
                    </div>

                    <!-- Estimasi Penerima Ringkas -->
                    <div class="p-3.5 rounded-[var(--radius-sm)] bg-[var(--surface-secondary)]/50 border border-[var(--border-subtle)] flex items-center justify-between text-xs">
                        <div class="flex items-center gap-3">
                            <div class="size-8 rounded-full bg-[var(--primary)]/10 text-[var(--primary)] flex items-center justify-center font-bold">
                                <x-icon name="whatsapp" class="size-4" />
                            </div>
                            <div>
                                <p class="font-semibold text-[var(--text)]">Estimasi Penerima Siaran</p>
                                <p class="text-[11px] text-[var(--text-secondary)]">
                                    <span class="font-bold text-emerald-600 dark:text-emerald-400" x-text="stats.valid_guardians"></span> nomor wali valid dari total
                                    <span class="font-bold text-[var(--text)]" x-text="stats.total_students"></span> siswa
                                </p>
                            </div>
                        </div>

                        <div class="text-right">
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-semibold bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300 border border-emerald-200">
                                <span class="size-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                                Siap Kirim
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Footer Aksi -->
                <div class="flex items-center justify-end gap-2.5 border-t border-[var(--border-subtle)] bg-[var(--surface-secondary)]/40 px-5 py-3">
                    <button type="submit"
                            :disabled="submitting || (targetType === 'class' && selectedClasses.length === 0) || messageText.trim().length === 0"
                            class="inline-flex h-8.5 items-center justify-center gap-1.5 rounded-[var(--radius-sm)] bg-[var(--primary)] px-4 text-xs font-semibold text-white shadow-2xs transition-colors hover:brightness-95 disabled:opacity-50 disabled:cursor-not-allowed cursor-pointer">
                        <x-icon name="send" class="size-3.5" />
                        <span x-text="submitting ? 'Mendaftarkan Antrean...' : 'Kirim Siaran Sekarang'"></span>
                    </button>
                </div>
            </form>
        </div>

        <!-- Kolom Live Mockup Preview WhatsApp (5 Kolom) -->
        <div class="lg:col-span-5 space-y-4">
            <div class="rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] shadow-[var(--shadow)] overflow-hidden">
                <div class="border-b border-[var(--border-subtle)] bg-[#075e54] px-4 py-3 text-white flex items-center justify-between">
                    <div class="flex items-center gap-2.5">
                        <div class="size-7.5 rounded-full bg-white/20 flex items-center justify-center text-xs font-bold text-white">
                            WA
                        </div>
                        <div>
                            <p class="text-xs font-bold leading-tight">Pratinjau Pesan Wali Murid</p>
                            <p class="text-[10px] text-emerald-200">Simulasi Tampilan WhatsApp</p>
                        </div>
                    </div>
                    <span class="text-[10px] bg-white/20 px-2 py-0.5 rounded text-white font-medium">Broadcast</span>
                </div>

                <!-- Chat Wallpaper & Bubble Area -->
                <div class="p-4 bg-[#efeae2] dark:bg-[#0b141a] min-h-[300px] flex flex-col justify-end">
                    <div class="max-w-[94%] self-start rounded-lg rounded-tl-none bg-white dark:bg-[#202c33] p-3 shadow-xs text-xs text-[#111b21] dark:text-[#e9edef] border border-black/5 relative">
                        <p class="font-bold text-[11px] text-[#075e54] dark:text-emerald-400 mb-1 flex items-center gap-1">
                            <x-icon name="whatsapp" class="size-3.5" />
                            <span>{{ config('whatsapp.school_name', 'SMA Negeri 1') }}</span>
                        </p>
                        <div class="whitespace-pre-wrap leading-relaxed text-[11.5px]" x-text="renderBroadcastPreview()"></div>
                        <div class="mt-2.5 flex items-center justify-end gap-1 text-[10px] text-[var(--text-muted)]">
                            <span>{{ now()->format('H:i') }}</span>
                            <svg class="size-3 text-sky-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7m-5-4l4 4L22 7"/></svg>
                        </div>
                    </div>
                </div>

                <div class="p-3 border-t border-[var(--border-subtle)] bg-[var(--surface-secondary)]/30 text-[11px] text-[var(--text-secondary)]">
                    <p class="flex items-center gap-1.5 font-medium text-[var(--text)]">
                        <x-icon name="info" class="size-3.5 text-emerald-600" />
                        <span>Pesan akan disesuaikan otomatis dengan nama masing-masing siswa dan kelasnya.</span>
                    </p>
                </div>
            </div>

            <!-- Kartu Kebijakan & Keamanan Anti-Spam -->
            <div class="rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] p-4 shadow-[var(--shadow)] text-xs space-y-2">
                <h3 class="font-bold text-[var(--text)] flex items-center gap-1.5">
                    <svg class="size-4 text-[var(--primary)]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                    <span>Kebijakan Anti-Spam &amp; Keamanan</span>
                </h3>
                <ul class="space-y-1.5 text-[11px] text-[var(--text-secondary)] list-disc list-inside">
                    <li>Sistem otomatis menduplikasi nomor: 1 nomor telepon orang tua hanya akan menerima 1 pesan broadcast per sesi.</li>
                    <li>Pengiriman dilakukan asinkron di latar belakang (*background queue*) dengan jeda aman agar nomor sekolah tidak diblokir.</li>
                    <li>Hanya nomor dengan status WhatsApp <strong>Valid</strong> yang diproses untuk menghemat kuota gateway.</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<script>
function broadcastManager() {
    return {
        targetType: 'all',
        selectedClasses: [],
        messageText: '',
        submitting: false,
        schoolName: @json(config('whatsapp.school_name', 'SMA Negeri 1')),
        allClassIds: @json($classes->pluck('id')),
        stats: {
            total_students: {{ $totalActiveStudents }},
            total_guardians: {{ $validGuardiansCount }},
            valid_guardians: {{ $validGuardiansCount }},
        },
        drafts: {
            libur: "Diberitahukan kepada seluruh Bapak/Ibu Wali Murid bahwa dalam rangka memperingati Hari Libur Nasional, kegiatan pembelajaran diliburkan pada tanggal " + new Date().toLocaleDateString('id-ID') + ". Siswa masuk kembali pada hari kerja berikutnya.",
            ujian: "Diberitahukan bahwa pelaksanaan Penilaian Akhir Semester (PAS) akan diselenggarakan mulai tanggal " + new Date().toLocaleDateString('id-ID') + ". Dimohon kerja sama orang tua untuk memantau waktu belajar siswa di rumah.",
            rapat: "Undangan Rapat Orang Tua Wali Murid yang akan dilaksanakan pada hari Sabtu pukul 09.00 WIB bertempat di Aula Sekolah. Kehadiran Bapak/Ibu sangat diharapkan demi kelancaran program sekolah.",
            disiplin: "Mengingatkan kembali perihal ketertiban sekolah: Siswa wajib hadir sebelum pukul 07.00 WIB dengan mengenakan atribut seragam lengkap. Keterlambatan scan sidik jari akan tercatat otomatis pada sistem presensi."
        },
        selectAllClasses(check) {
            this.selectedClasses = check ? [...this.allClassIds] : [];
            this.fetchCounts();
        },
        applyDraft(key) {
            if (this.drafts[key]) {
                this.messageText = this.drafts[key];
            }
        },
        async fetchCounts() {
            try {
                const res = await fetch("{{ route('whatsapp.broadcast.recipient-count') }}", {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify({
                        target_type: this.targetType,
                        class_ids: this.selectedClasses,
                    })
                });
                if (res.ok) {
                    this.stats = await res.json();
                }
            } catch (e) {
                console.error('Failed to fetch counts', e);
            }
        },
        renderBroadcastPreview() {
            const rawPesan = this.messageText.trim() || '[Isi pesan pengumuman sekolah akan muncul di sini...]';
            return "Assalamu'alaikum / Salam Sejahtera Bapak/Ibu Wali dari Muhammad Rizky Pratama (Kelas X-IPA-1).\n\nPEMBERITAHUAN DARI " + this.schoolName.toUpperCase() + ":\n" + rawPesan + "\n\nDemikian informasi ini disampaikan. Terima kasih atas perhatian dan kerja samanya.";
        },
        async handleSubmit(form) {
            if (this.submitting) return;

            const approved = await window.askConfirm({
                title: 'Konfirmasi Pengiriman Siaran',
                message: 'Apakah Anda yakin ingin mengirimkan pesan siaran pengumuman ini kepada ' + this.stats.valid_guardians + ' orang tua siswa?',
                confirmLabel: 'Ya, Kirim Sekarang',
                variant: 'primary',
            });

            if (!approved) return;

            this.submitting = true;

            try {
                const formData = new FormData(form);
                const response = await fetch(form.action, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: formData,
                });

                const data = await response.json();

                if (response.ok && data.task_uuid) {
                    window.toast('Siaran pengumuman berhasil didaftarkan. Mengirim pesan...', 'success');
                    window.dispatchEvent(new CustomEvent('start-broadcast-task', {
                        detail: { uuid: data.task_uuid, token: data.progress_token }
                    }));
                } else {
                    window.toast(data.message || 'Gagal mengirim pengumuman.', 'error');
                }
            } catch (err) {
                window.toast('Terjadi kesalahan koneksi saat mengirim pengumuman.', 'error');
            } finally {
                this.submitting = false;
            }
        }
    };
}
</script>
@endsection
