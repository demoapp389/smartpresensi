@extends('layouts.app')

@section('title', 'Template Pesan WhatsApp')
@section('page-pretitle', 'Pengaturan & Integrasi')
@section('page-title', 'Kustomisasi Template WhatsApp')
@section('page-subtitle', 'Sesuaikan format redaksi pesan otomatis presensi dan siaran pengumuman ke orang tua siswa')

@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="transition-colors hover:text-[var(--text)]">Beranda</a>
    <span aria-hidden="true">/</span>
    <span>WhatsApp</span>
    <span aria-hidden="true">/</span>
    <span class="font-medium text-[var(--text)]" aria-current="page">Template Pesan</span>
@endsection



@section('content')
<div class="space-y-4" x-data="whatsappTemplateManager()">

    <!-- Tab Pilihan Template (Pola .tabs-pill Gentelella dalam Card bg) -->
    <div class="rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] p-2.5 shadow-[var(--shadow)] flex flex-wrap items-center justify-between gap-3 text-xs">
        <div class="overflow-x-auto w-full sm:w-auto">
            <div class="tabs-pill" role="tablist" aria-label="Daftar Template Pesan">
                @foreach($templates as $tpl)
                    <button type="button"
                            role="tab"
                            @click="activeTab = '{{ $tpl->event_type }}'"
                            :class="activeTab === '{{ $tpl->event_type }}' ? 'active' : ''"
                            class="tab inline-flex items-center gap-2 cursor-pointer">
                        <span>{{ $tpl->name }}</span>
                        @if(!$tpl->is_active)
                            <span class="size-1.5 rounded-full bg-amber-400" title="Nonaktif"></span>
                        @endif
                    </button>
                @endforeach
            </div>
        </div>

        <div class="hidden sm:flex items-center gap-1.5 text-[11px] text-[var(--text-muted)] pr-2">
            <x-icon name="info" class="size-3.5 text-[var(--primary)]" />
            <span>Pilih tab di atas untuk mengedit format & melihat live preview</span>
        </div>
    </div>

    <!-- Area Konten Form & Preview -->
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-5 items-start">

        <!-- Kolom Form Editor (7 Kolom) -->
        <div class="lg:col-span-7">
            @foreach($templates as $tpl)
            <div x-show="activeTab === '{{ $tpl->event_type }}'" x-cloak>
                <form method="POST" action="{{ route('whatsapp.templates.update', $tpl) }}" class="rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] shadow-[var(--shadow)] overflow-hidden">
                    @csrf
                    @method('PUT')

                    <div class="border-b border-[var(--border-subtle)] px-5 py-3.5 flex items-center justify-between bg-[var(--surface)]">
                        <div>
                            <div class="flex items-center gap-2">
                                <h2 class="text-xs font-bold text-[var(--text)] uppercase tracking-wider">Format: {{ $tpl->name }}</h2>
                                <span class="rounded bg-[var(--surface-secondary)] px-2 py-0.5 text-[10px] font-mono text-[var(--primary)] border border-[var(--border)]">
                                    {{ $tpl->event_type }}
                                </span>
                            </div>
                            <p class="text-[11px] text-[var(--text-muted)] mt-0.5">{{ $tpl->description }}</p>
                        </div>
                        <label class="flex items-center gap-2 cursor-pointer select-none text-xs">
                            <input type="checkbox" name="is_active" value="1" {{ $tpl->is_active ? 'checked' : '' }} class="rounded border-[var(--border)] text-[var(--primary)] focus:ring-[var(--primary)]">
                            <span class="text-[var(--text-secondary)] font-medium">Aktif</span>
                        </label>
                    </div>

                    <div class="p-5 space-y-4 text-xs">
                        <div>
                            <label for="name_{{ $tpl->event_type }}" class="block font-semibold text-[var(--text)] mb-1">
                                Nama Label Template <span class="text-rose-500">*</span>
                            </label>
                            <input type="text"
                                   id="name_{{ $tpl->event_type }}"
                                   name="name"
                                   x-model="names['{{ $tpl->event_type }}']"
                                   required
                                   class="w-full rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface)] px-3 py-2 text-xs text-[var(--text)] focus:border-[var(--primary)] focus:outline-none focus:ring-1 focus:ring-[var(--primary)]">
                        </div>

                        <!-- Variable Chips Picker -->
                        <div>
                            <div class="flex items-center justify-between mb-1.5">
                                <span class="font-semibold text-[var(--text)]">Sisipkan Variabel Otomatis:</span>
                                <span class="text-[10px] text-[var(--text-muted)]">Klik variabel untuk menyisipkan ke kursor</span>
                            </div>
                            <div class="flex flex-wrap gap-1.5 p-2.5 rounded-[var(--radius-sm)] bg-[var(--surface-secondary)]/50 border border-[var(--border-subtle)]">
                                <button type="button" @click="insertVariable('{{ $tpl->event_type }}', ':nama_siswa')" class="px-2 py-1 rounded bg-[var(--surface)] border border-[var(--border)] text-[11px] font-mono text-[var(--primary)] hover:border-[var(--primary)] hover:bg-[var(--surface)] transition-colors cursor-pointer" title="Nama lengkap siswa">:nama_siswa</button>
                                <button type="button" @click="insertVariable('{{ $tpl->event_type }}', ':kelas')" class="px-2 py-1 rounded bg-[var(--surface)] border border-[var(--border)] text-[11px] font-mono text-[var(--primary)] hover:border-[var(--primary)] hover:bg-[var(--surface)] transition-colors cursor-pointer" title="Kelas binaan siswa">:kelas</button>
                                <button type="button" @click="insertVariable('{{ $tpl->event_type }}', ':tanggal')" class="px-2 py-1 rounded bg-[var(--surface)] border border-[var(--border)] text-[11px] font-mono text-[var(--primary)] hover:border-[var(--primary)] hover:bg-[var(--surface)] transition-colors cursor-pointer" title="Tanggal presensi">:tanggal</button>
                                <button type="button" @click="insertVariable('{{ $tpl->event_type }}', ':waktu')" class="px-2 py-1 rounded bg-[var(--surface)] border border-[var(--border)] text-[11px] font-mono text-[var(--primary)] hover:border-[var(--primary)] hover:bg-[var(--surface)] transition-colors cursor-pointer" title="Waktu scan">:waktu</button>
                                <button type="button" @click="insertVariable('{{ $tpl->event_type }}', ':nama_sekolah')" class="px-2 py-1 rounded bg-[var(--surface)] border border-[var(--border)] text-[11px] font-mono text-[var(--primary)] hover:border-[var(--primary)] hover:bg-[var(--surface)] transition-colors cursor-pointer" title="Nama sekolah">:nama_sekolah</button>
                                <button type="button" @click="insertVariable('{{ $tpl->event_type }}', ':nama_wali')" class="px-2 py-1 rounded bg-[var(--surface)] border border-[var(--border)] text-[11px] font-mono text-[var(--primary)] hover:border-[var(--primary)] hover:bg-[var(--surface)] transition-colors cursor-pointer" title="Nama wali murid">:nama_wali</button>
                                <button type="button" @click="insertVariable('{{ $tpl->event_type }}', ':status')" class="px-2 py-1 rounded bg-[var(--surface)] border border-[var(--border)] text-[11px] font-mono text-[var(--primary)] hover:border-[var(--primary)] hover:bg-[var(--surface)] transition-colors cursor-pointer" title="Status kehadiran">:status</button>
                                @if($tpl->event_type === 'broadcast')
                                    <button type="button" @click="insertVariable('{{ $tpl->event_type }}', ':pesan')" class="px-2 py-1 rounded bg-[var(--primary)]/10 border border-[var(--primary)]/30 text-[11px] font-mono font-bold text-[var(--primary)] hover:brightness-95 transition-colors cursor-pointer" title="Isi pengumuman">:pesan</button>
                                @endif
                            </div>
                        </div>

                        <!-- Editor Textarea -->
                        <div>
                            <label for="textarea_{{ $tpl->event_type }}" class="block font-semibold text-[var(--text)] mb-1">
                                Redaksi Pesan <span class="text-rose-500">*</span>
                            </label>
                            <textarea id="textarea_{{ $tpl->event_type }}"
                                      name="content"
                                      rows="8"
                                      x-model="contents['{{ $tpl->event_type }}']"
                                      required
                                      class="w-full rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface)] p-3 text-xs font-mono text-[var(--text)] leading-relaxed focus:border-[var(--primary)] focus:outline-none focus:ring-1 focus:ring-[var(--primary)]"
                                      placeholder="Tuliskan format pesan..."></textarea>
                            <div class="mt-1 flex items-center justify-between text-[11px] text-[var(--text-muted)]">
                                <span>Mendukung format WhatsApp: *tebal*, _miring_, ~coret~</span>
                                <span x-text="(contents['{{ $tpl->event_type }}'] ? contents['{{ $tpl->event_type }}'].length : 0) + ' karakter'"></span>
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center justify-end gap-2.5 border-t border-[var(--border-subtle)] bg-[var(--surface-secondary)]/40 px-5 py-3">
                        <button type="submit"
                                class="inline-flex h-8.5 items-center justify-center gap-1.5 rounded-[var(--radius-sm)] bg-[var(--primary)] px-4 text-xs font-semibold text-white shadow-2xs transition-colors hover:brightness-95 cursor-pointer">
                            <svg class="size-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                            </svg>
                            <span>Simpan Perubahan Template</span>
                        </button>
                    </div>
                </form>
            </div>
            @endforeach
        </div>

        <!-- Kolom Live Mockup Preview WhatsApp & Kamus Variabel (5 Kolom) -->
        <div class="lg:col-span-5 space-y-4">
            <!-- Mockup WhatsApp -->
            <div class="rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] shadow-[var(--shadow)] overflow-hidden">
                <div class="border-b border-[var(--border-subtle)] bg-[#075e54] px-4 py-3 text-white flex items-center justify-between">
                    <div class="flex items-center gap-2.5">
                        <div class="size-7.5 rounded-full bg-white/20 flex items-center justify-center text-xs font-bold text-white">
                            WA
                        </div>
                        <div>
                            <p class="text-xs font-bold leading-tight">Pratinjau Pesan Wali Murid</p>
                            <p class="text-[10px] text-emerald-200">Online &bull; Live Preview</p>
                        </div>
                    </div>
                    <span class="text-[10px] bg-white/20 px-2 py-0.5 rounded text-white font-medium">WhatsApp UI</span>
                </div>

                <!-- Chat Wallpaper & Bubble Area -->
                <div class="p-4 bg-[#efeae2] dark:bg-[#0b141a] min-h-[260px] flex flex-col justify-end">
                    <div class="max-w-[92%] self-start rounded-lg rounded-tl-none bg-white dark:bg-[#202c33] p-3 shadow-xs text-xs text-[#111b21] dark:text-[#e9edef] border border-black/5 relative">
                        <p class="font-bold text-[11px] text-[#075e54] dark:text-emerald-400 mb-1 flex items-center gap-1">
                            <x-icon name="whatsapp" class="size-3.5" />
                            <span>{{ config('whatsapp.school_name', 'SMA Negeri 1') }}</span>
                        </p>
                        <div class="whitespace-pre-wrap leading-relaxed text-[11.5px]" x-text="getRendered()"></div>
                        <div class="mt-2 flex items-center justify-end gap-1 text-[10px] text-[var(--text-muted)]">
                            <span>{{ now()->format('H:i') }}</span>
                            <svg class="size-3 text-sky-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7m-5-4l4 4L22 7"/></svg>
                        </div>
                    </div>
                </div>

                <div class="p-3 border-t border-[var(--border-subtle)] bg-[var(--surface-secondary)]/30 text-[11px] text-[var(--text-secondary)]">
                    <p class="flex items-center gap-1.5 font-medium text-[var(--text)]">
                        <svg class="size-3.5 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        <span>Data pada simulasi diperbarui otomatis saat Anda mengedit template.</span>
                    </p>
                </div>
            </div>

            <!-- Kartu Kamus Variabel -->
            <div class="rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] p-4 shadow-[var(--shadow)] text-xs">
                <h3 class="font-bold text-[var(--text)] mb-2 flex items-center gap-1.5">
                    <x-icon name="info" class="size-3.5 text-[var(--primary)]" />
                    <span>Daftar Parameter yang Didukung</span>
                </h3>
                <div class="divide-y divide-[var(--border-subtle)] text-[11px]">
                    @foreach($placeholders as $ph => $desc)
                    <div class="py-1.5 flex items-start justify-between gap-2">
                        <code class="font-mono text-[var(--primary)] font-semibold shrink-0">{{ $ph }}</code>
                        <span class="text-[var(--text-secondary)] text-right">{{ $desc }}</span>
                    </div>
                    @endforeach
                </div>
            </div>
        </div>

    </div>
</div>

<script>
function whatsappTemplateManager() {
    return {
        activeTab: '{{ $templates->first()?->event_type ?? 'check_in' }}',
        contents: @json($templates->pluck('content', 'event_type')),
        names: @json($templates->pluck('name', 'event_type')),
        schoolName: @json(config('whatsapp.school_name', 'SMA Negeri 1')),
        insertVariable(eventType, placeholder) {
            const textarea = document.getElementById('textarea_' + eventType);
            if (!textarea) return;

            const start = textarea.selectionStart;
            const end = textarea.selectionEnd;
            const text = this.contents[eventType] || '';
            this.contents[eventType] = text.substring(0, start) + placeholder + text.substring(end);

            this.$nextTick(() => {
                textarea.focus();
                textarea.selectionStart = textarea.selectionEnd = start + placeholder.length;
            });
        },
        getRendered() {
            const eventType = this.activeTab;
            const raw = this.contents[eventType] || '';
            return raw
                .replace(/:nama_siswa/g, 'Muhammad Rizky Pratama')
                .replace(/:kelas/g, 'X-IPA-1')
                .replace(/:tanggal/g, '{{ now()->translatedFormat('d F Y') }}')
                .replace(/:waktu/g, '{{ now()->format('H:i') }} WIB')
                .replace(/:status/g, eventType === 'late' ? 'Hadir Terlambat' : (eventType === 'check_out' ? 'Pulang' : 'Hadir Tepat Waktu'))
                .replace(/:nama_sekolah/g, this.schoolName)
                .replace(/:nama_wali/g, 'Bapak Hendra Saputra')
                .replace(/:pesan/g, 'Diberitahukan bahwa besok kegiatan belajar mengajar dimulai pukul 07.00 WIB sehubungan pelaksanaan Upacara Bendera.');
        }
    };
}
</script>
@endsection
