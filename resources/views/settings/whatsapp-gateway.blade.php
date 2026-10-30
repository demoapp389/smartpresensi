@extends('layouts.app')

@section('title', 'Pengaturan Gateway')
@section('page-pretitle', 'WhatsApp')
@section('page-title', 'Pengaturan Gateway WhatsApp')
@section('page-subtitle', 'Konfigurasi driver, kredensial, dan parameter validasi nomor wali (hanya baca)')

@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="transition-colors hover:text-[var(--text)]">Beranda</a>
    <span aria-hidden="true">/</span>
    <span>WhatsApp</span>
    <span aria-hidden="true">/</span>
    <span class="font-medium text-[var(--text)]" aria-current="page">Pengaturan Gateway</span>
@endsection

@section('content')
<div class="space-y-4">

    {{-- Info: konfigurasi hanya baca --}}
    <div class="rounded-[var(--radius)] border border-sky-200 bg-sky-50/60 p-3 text-xs text-sky-900 flex items-start gap-2.5">
        <x-icon name="info" class="size-4 shrink-0 mt-0.5 text-sky-600" />
        <p class="leading-relaxed">
            Nilai di bawah dibaca dari file <code class="font-mono">config/whatsapp.php</code> yang bersumber dari variabel
            lingkungan (<code class="font-mono">.env</code>) dan <strong>tidak dapat diubah dari halaman ini</strong>.
            Token rahasia ditampilkan dalam bentuk tersamar; gunakan tombol Uji Kirim untuk memverifikasi konektivitas.
        </p>
    </div>

    {{-- Endpoint webhook publik --}}
    <section class="min-w-0 rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] shadow-[var(--shadow)] overflow-hidden"
             x-data="{ copied: false }">
        <div class="flex items-center justify-between gap-3 border-b border-[var(--border-subtle)] px-4 py-3">
            <div class="min-w-0">
                <h2 class="text-xs font-bold text-[var(--text)]">Endpoint Webhook Publik</h2>
                <p class="text-[11px] text-[var(--text-muted)]">Salin URL ini ke pengaturan webhook Fonnte.</p>
            </div>
            <span class="shrink-0 px-2 py-0.5 rounded text-[10px] font-bold
                         {{ $gateway['webhook_url_is_public'] ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-amber-50 text-amber-700 border border-amber-200' }}">
                {{ $gateway['webhook_url_is_public'] ? 'Publik' : 'Lokal' }}
            </span>
        </div>

        <div class="p-4 space-y-3 text-xs">
            <div class="flex flex-col gap-2 sm:flex-row">
                <input id="webhook-url" type="text" readonly value="{{ $gateway['webhook_url'] }}" x-ref="webhookUrl"
                    class="h-9 min-w-0 flex-1 rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface-secondary)] px-3 font-mono text-[11px] text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none focus:ring-1 focus:ring-[var(--primary-accessible)]">
                <button type="button"
                    x-on:click="
                        $refs.webhookUrl.select();
                        $refs.webhookUrl.setSelectionRange(0, $refs.webhookUrl.value.length);
                        if (navigator.clipboard && window.isSecureContext) {
                            navigator.clipboard.writeText($refs.webhookUrl.value);
                        } else {
                            document.execCommand('copy');
                        }
                        copied = true;
                        setTimeout(() => copied = false, 2000);
                    "
                    class="inline-flex h-9 shrink-0 cursor-pointer items-center justify-center gap-1.5 rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface-secondary)] px-3 font-semibold text-[var(--text)] transition-colors hover:border-[var(--primary-accessible)] hover:text-[var(--primary-accessible)]">
                    <x-icon name="copy" class="size-3.5" />
                    <span x-text="copied ? 'Tersalin' : 'Salin URL'">Salin URL</span>
                </button>
            </div>

            <div class="grid grid-cols-1 gap-2 sm:grid-cols-3">
                <div class="rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface-secondary)] px-3 py-2">
                    <p class="text-[10px] font-semibold uppercase tracking-wide text-[var(--text-muted)]">Mode Akses</p>
                    <p class="mt-0.5 font-semibold text-[var(--text)]">{{ $gateway['webhook_public_only'] ? 'Hanya webhook' : 'Semua route' }}</p>
                </div>
                <div class="rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface-secondary)] px-3 py-2">
                    <p class="text-[10px] font-semibold uppercase tracking-wide text-[var(--text-muted)]">Secret Webhook</p>
                    <p class="mt-0.5 font-semibold {{ $gateway['webhook_secret_configured'] ? 'text-emerald-700' : 'text-rose-700' }}">
                        {{ $gateway['webhook_secret_configured'] ? 'Terkonfigurasi' : 'Belum diatur' }}
                    </p>
                </div>
                <div class="rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface-secondary)] px-3 py-2">
                    <p class="text-[10px] font-semibold uppercase tracking-wide text-[var(--text-muted)]">Skema URL</p>
                    <p class="mt-0.5 font-semibold {{ $gateway['webhook_url_is_https'] ? 'text-emerald-700' : 'text-amber-700' }}">
                        {{ $gateway['webhook_url_is_https'] ? 'HTTPS' : 'HTTP / lokal' }}
                    </p>
                </div>
            </div>

            @if(! $gateway['webhook_url_is_public'])
                <p class="rounded-[var(--radius-sm)] border border-amber-200 bg-amber-50 px-3 py-2 text-[11px] text-amber-900">
                    Isi <code class="font-mono">WA_WEBHOOK_BASE_URL</code> di <code class="font-mono">.env</code> agar URL publik tampil di sini.
                </p>
            @elseif(! $gateway['webhook_url_is_https'])
                <p class="rounded-[var(--radius-sm)] border border-amber-200 bg-amber-50 px-3 py-2 text-[11px] text-amber-900">
                    URL webhook sebaiknya memakai HTTPS. Periksa kembali <code class="font-mono">WA_WEBHOOK_BASE_URL</code>.
                </p>
            @endif

            <p class="text-[11px] leading-relaxed text-[var(--text-muted)]">
                Jika domain berubah, ubah <code class="font-mono">WA_WEBHOOK_BASE_URL</code>, jalankan
                <code class="font-mono">php artisan optimize:clear</code> dan <code class="font-mono">php artisan optimize</code>,
                lalu perbarui URL yang sama di dashboard Fonnte.
            </p>
        </div>
    </section>

    {{-- Status driver --}}
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
        <x-stat-card label="Driver Aktif" :value="strtoupper($gateway['provider'])" tone="success"
                     hint="Provider WhatsApp yang dipakai sistem" />
        <x-stat-card label="Nama Sekolah" :value="$gateway['school_name']" hint="Dipakai pada pesan notifikasi" />
        <x-stat-card label="Batas Retry" :value="$gateway['retry_limit']" hint="Percobaan kirim ulang per pesan" />
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">

        {{-- Kredensial --}}
        <section class="min-w-0 rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] shadow-[var(--shadow)] overflow-hidden"
                 x-data="{ revealed: {} }">
            <div class="flex items-center justify-between border-b border-[var(--border-subtle)] px-4 py-3">
                <h2 class="text-xs font-bold text-[var(--text)]">Kredensial Provider</h2>
                <span class="text-[11px] text-[var(--text-muted)]">Tersamar secara bawaan</span>
            </div>

            <div class="p-4 space-y-3 text-xs">
                @php
                    $metaConfigured = (bool) config('whatsapp.meta.phone_number_id') && (bool) config('whatsapp.meta.access_token');
                    $fonnteConfigured = (bool) config('whatsapp.fonnte.token');
                @endphp

                <div class="flex items-center justify-between rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface-secondary)] px-3 py-2">
                    <div class="min-w-0">
                        <p class="font-semibold text-[var(--text)]">Meta Cloud API ({{ $gateway['meta_version'] }})</p>
                        <p class="text-[11px] text-[var(--text-muted)]">Phone Number ID: {{ $metaConfigured ? 'terkonfigurasi' : 'belum diatur' }}</p>
                    </div>
                    <span class="shrink-0 ml-2 px-2 py-0.5 rounded text-[10px] font-bold
                                 {{ $metaConfigured ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-slate-100 text-slate-600 border border-slate-200' }}">
                        {{ $metaConfigured ? 'Aktif' : 'Nonaktif' }}
                    </span>
                </div>

                <div class="flex items-center justify-between rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface-secondary)] px-3 py-2">
                    <div class="min-w-0">
                        <p class="font-semibold text-[var(--text)]">Fonnte WA Gateway</p>
                        <p class="text-[11px] text-[var(--text-muted)] truncate">{{ $gateway['fonnte_url'] }}</p>
                    </div>
                    <span class="shrink-0 ml-2 px-2 py-0.5 rounded text-[10px] font-bold
                                 {{ $fonnteConfigured ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-slate-100 text-slate-600 border border-slate-200' }}">
                        {{ $fonnteConfigured ? 'Aktif' : 'Nonaktif' }}
                    </span>
                </div>

                {{-- Token dengan aksi buka/tutup --}}
                @foreach([
                    'token' => ['label' => 'Fonnte Token', 'value' => config('whatsapp.fonnte.token')],
                    'meta_access' => ['label' => 'Meta Access Token', 'value' => config('whatsapp.meta.access_token')],
                    'webhook_token' => ['label' => 'Webhook Verify Token', 'value' => config('whatsapp.meta.webhook_verify_token')],
                ] as $key => $field)
                    <div class="rounded-[var(--radius-sm)] border border-[var(--border)] px-3 py-2">
                        <div class="flex items-center justify-between gap-2">
                            <p class="font-semibold text-[var(--text)]">{{ $field['label'] }}</p>
                            @if($field['value'])
                                <button type="button"
                                        x-on:click="revealed['{{ $key }}'] = ! revealed['{{ $key }}']"
                                        class="inline-flex items-center gap-1 text-[11px] font-semibold text-[var(--primary-accessible)] hover:underline cursor-pointer">
                                    <x-icon name="eye" class="size-3" x-show="! revealed['{{ $key }}']" />
                                    <x-icon name="eye-off" class="size-3" x-show="revealed['{{ $key }}']" x-cloak />
                                    <span x-text="revealed['{{ $key }}'] ? 'Sembunyikan' : 'Tampilkan'"></span>
                                </button>
                            @endif
                        </div>
                        <p class="mt-1 font-mono text-[11px] break-all {{ $field['value'] ? 'text-[var(--text)]' : 'text-[var(--text-muted)] italic' }}">
                            @if($field['value'])
                                <span x-show="! revealed['{{ $key }}']" class="select-none">••••••••••••••••</span>
                                <span x-show="revealed['{{ $key }}']" x-cloak>{{ $field['value'] }}</span>
                            @else
                                Belum dikonfigurasi
                            @endif
                        </p>
                    </div>
                @endforeach
            </div>
        </section>

        {{-- Parameter validasi --}}
        <section class="min-w-0 rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] shadow-[var(--shadow)] overflow-hidden">
            <div class="border-b border-[var(--border-subtle)] px-4 py-3">
                <h2 class="text-xs font-bold text-[var(--text)]">Parameter Validasi Nomor Wali</h2>
            </div>

            <dl class="p-4 space-y-2.5 text-xs">
                @foreach([
                    'Validasi otomatis saat simpan' => ($gateway['validation']['auto_validate_on_save'] ? 'Aktif' : 'Nonaktif'),
                    'Jeda antar batch' => $gateway['validation']['batch_delay_ms'].' ms',
                    'Ukuran chunk' => $gateway['validation']['chunk_size'].' nomor',
                    'Masa tahan notifikasi' => $gateway['validation']['expiration_hours'].' jam',
                    'Validasi ulang berkala' => ($gateway['revalidation']['enabled'] ? 'Aktif' : 'Nonaktif'),
                    'Interval validasi ulang' => $gateway['revalidation']['interval_days'].' hari',
                    'Endpoint validasi' => $gateway['fonnte_validate_url'],
                ] as $key => $value)
                    <div class="flex items-center justify-between gap-3 border-b border-[var(--border-subtle)] pb-2 last:border-0 last:pb-0">
                        <dt class="text-[var(--text-secondary)] shrink-0">{{ $key }}</dt>
                        <dd class="font-semibold text-[var(--text)] text-right truncate" title="{{ $value }}">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>
        </section>
    </div>

    {{-- Uji kirim pesan --}}
    <section class="min-w-0 rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] shadow-[var(--shadow)] overflow-hidden">
        <div class="border-b border-[var(--border-subtle)] px-4 py-3">
            <h2 class="text-xs font-bold text-[var(--text)]">Uji Kirim Pesan</h2>
            <p class="text-[11px] text-[var(--text-secondary)]">Nomor divalidasi dulu; pesan tidak dikirim bila nomor tidak valid.</p>
        </div>

        <form method="POST" action="{{ route('notifications.test') }}" class="p-4 grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
            @csrf
            <div>
                <label for="test-phone" class="block font-semibold text-[var(--text)] mb-1">Nomor WhatsApp Tujuan</label>
                <input id="test-phone" type="text" name="test_phone" placeholder="081234567890" required
                    class="h-9 w-full rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] px-3 text-xs font-mono text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none focus:ring-1 focus:ring-[var(--primary-accessible)]">
            </div>

            <div>
                <label for="test-message" class="block font-semibold text-[var(--text)] mb-1">Isi Pesan</label>
                <textarea id="test-message" name="test_message" rows="2" required
                    class="w-full rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] p-2.5 text-xs text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none focus:ring-1 focus:ring-[var(--primary-accessible)]">Halo! Ini pesan uji coba konektivitas WhatsApp Gateway.</textarea>
            </div>

            <div class="sm:col-span-2 flex justify-end">
                <button type="submit"
                    class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-[var(--radius-sm)] bg-[var(--primary)] text-xs font-semibold text-white hover:brightness-95 transition-colors">
                    <x-icon name="send" class="size-3.5" />
                    <span>Kirim Pesan Uji</span>
                </button>
            </div>
        </form>
    </section>
</div>
@endsection
