@extends('layouts.app')

@section('title', 'Outbox WhatsApp')
@section('page-pretitle', 'Notifikasi & Gateway')
@section('page-title', 'Outbox Notifikasi WhatsApp Wali Murid')
@section('page-subtitle', 'Monitoring antrean pesan otomatis, status pengiriman asinkron, dan idempotency key')

@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="transition-colors hover:text-[var(--text)]">Beranda</a>
    <span aria-hidden="true">/</span>
    <span>Notifikasi &amp; Gateway</span>
    <span aria-hidden="true">/</span>
    <span class="font-medium text-[var(--text)]" aria-current="page">Outbox WhatsApp</span>
@endsection

@section('content')
<div class="space-y-4" x-data="{
    selected: [],
    toggleAll(e) {
        if (e.target.checked) {
            this.selected = @js($notifications->pluck('id')->map(fn($id) => (string)$id)->values());
        } else {
            this.selected = [];
        }
    }
}">
    <!-- Gateway Info & Stat Highlights Grid -->
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-7 gap-3">
        <!-- Gateway Driver -->
        <article class="min-w-0 rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] p-3.5 shadow-[var(--shadow)] col-span-2 sm:col-span-1">
            <p class="text-[11px] font-semibold uppercase tracking-wider text-[var(--text-muted)] truncate">Driver Gateway</p>
            <p class="mt-1 text-sm font-bold uppercase tracking-wide text-[var(--text)]">{{ $activeProvider }}</p>
            <p class="text-[10px] text-[var(--text-muted)] mt-1 truncate">
                @if($activeProvider === 'log')
                    Simulasi Lokal (Log)
                @elseif($activeProvider === 'meta')
                    Meta Cloud API v20.0
                @elseif($activeProvider === 'fonnte')
                    Fonnte WA Gateway
                @endif
            </p>
        </article>

        <!-- Total Notifikasi -->
        <article class="min-w-0 rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] p-3.5 shadow-[var(--shadow)]">
            <p class="text-[11px] font-semibold uppercase tracking-wider text-[var(--text-muted)] truncate">Total Pesan</p>
            <p class="mt-1 text-2xl font-bold leading-none tabular-nums text-[var(--text)]">{{ $stats['total'] }}</p>
            <p class="mt-1.5 text-[11px] text-[var(--text-secondary)]">Semua Rekaman</p>
        </article>

        <!-- Antrean -->
        <article class="min-w-0 rounded-[var(--radius)] border border-amber-200/60 bg-[var(--surface)] p-3.5 shadow-[var(--shadow)]">
            <p class="text-[11px] font-semibold uppercase tracking-wider text-amber-700 truncate">Antrean</p>
            <p class="mt-1 text-2xl font-bold leading-none tabular-nums text-amber-800">{{ $stats['queued'] }}</p>
            <p class="mt-1.5 text-[11px] text-amber-600 font-medium">Dalam Antrean</p>
        </article>

        <!-- Terkirim -->
        <article class="min-w-0 rounded-[var(--radius)] border border-blue-200/60 bg-[var(--surface)] p-3.5 shadow-[var(--shadow)]">
            <p class="text-[11px] font-semibold uppercase tracking-wider text-blue-700 truncate">Terkirim</p>
            <p class="mt-1 text-2xl font-bold leading-none tabular-nums text-blue-800">{{ $stats['sent'] }}</p>
            <p class="mt-1.5 text-[11px] text-blue-600 font-medium">Diterima Gateway</p>
        </article>

        <!-- Diterima -->
        <article class="min-w-0 rounded-[var(--radius)] border border-emerald-200/60 bg-[var(--surface)] p-3.5 shadow-[var(--shadow)]">
            <p class="text-[11px] font-semibold uppercase tracking-wider text-emerald-700 truncate">Diterima</p>
            <p class="mt-1 text-2xl font-bold leading-none tabular-nums text-emerald-800">{{ $stats['delivered'] }}</p>
            <p class="mt-1.5 text-[11px] text-emerald-600 font-medium">Sampai ke HP Wali</p>
        </article>

        <!-- Dibaca -->
        <article class="min-w-0 rounded-[var(--radius)] border border-indigo-200/60 bg-[var(--surface)] p-3.5 shadow-[var(--shadow)]">
            <p class="text-[11px] font-semibold uppercase tracking-wider text-indigo-700 truncate">Dibaca</p>
            <p class="mt-1 text-2xl font-bold leading-none tabular-nums text-indigo-800">{{ $stats['read'] }}</p>
            <p class="mt-1.5 text-[11px] text-indigo-600 font-medium">Dibaca oleh Wali</p>
        </article>

        <!-- Gagal -->
        <article class="min-w-0 rounded-[var(--radius)] border border-rose-200/60 bg-[var(--surface)] p-3.5 shadow-[var(--shadow)]">
            <p class="text-[11px] font-semibold uppercase tracking-wider text-rose-700 truncate">Gagal</p>
            <p class="mt-1 text-2xl font-bold leading-none tabular-nums text-rose-800">{{ $stats['failed'] }}</p>
            <p class="mt-1.5 text-[11px] text-rose-600 font-medium">Perlu Dikirim Ulang</p>
        </article>
    </div>

    <!-- Table Card -->
    <section class="min-w-0 rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] shadow-[var(--shadow)] overflow-hidden">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--border-subtle)] px-4 py-3">
            <div>
                <h2 class="text-xs font-bold text-[var(--text)]">Daftar Log Notifikasi WhatsApp</h2>
                <div class="text-[11px] text-[var(--text-muted)]">Total: {{ $notifications->count() }} pesan tercatat</div>
            </div>

            @if($stats['failed'] > 0)
                <form action="{{ route('notifications.retry-all-failed') }}" method="POST" class="inline"
                    @submit.prevent="askConfirm({ title: 'Kirim Ulang Semua Gagal', message: 'Kirim ulang seluruh {{ $stats['failed'] }} notifikasi yang gagal ke antrean WhatsApp?', confirmLabel: 'Kirim Ulang Semua', variant: 'primary' }).then(ok => ok && $el.submit())">
                    @csrf
                    <button type="submit"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-[var(--radius-sm)] bg-amber-600 hover:bg-amber-700 text-xs font-semibold text-white transition-colors shadow-xs cursor-pointer">
                        <svg class="size-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                        <span>Kirim Ulang Semua Gagal ({{ $stats['failed'] }})</span>
                    </button>
                </form>
            @endif
        </div>

        <!-- Bulk Action Bar -->
        <div x-cloak x-show="selected.length > 0" x-transition
             class="flex flex-wrap items-center justify-between gap-3 border-b border-indigo-200 bg-indigo-50/80 dark:bg-indigo-950/40 dark:border-indigo-800/60 px-4 py-2.5">
            <div class="flex items-center gap-3">
                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold bg-indigo-100 text-indigo-800 dark:bg-indigo-900/60 dark:text-indigo-200 border border-indigo-300 dark:border-indigo-700">
                    <span x-text="selected.length"></span> pesan dipilih
                </span>
                <button type="button" @click="selected = []"
                    class="inline-flex items-center px-2.5 py-1 rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface)] hover:bg-[var(--surface-secondary)] text-xs font-medium text-[var(--text-secondary)] transition-colors cursor-pointer">
                    Batal Pilih
                </button>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <form action="{{ route('notifications.bulk-retry') }}" method="POST" class="inline"
                    @submit.prevent="askConfirm({ title: 'Kirim Ulang Pesan', message: 'Kirim ulang ' + selected.length + ' pesan terpilih ke antrean WhatsApp?', confirmLabel: 'Kirim Ulang', variant: 'primary' }).then(ok => ok && $el.submit())">
                    @csrf
                    <template x-for="id in selected" :key="id">
                        <input type="hidden" name="ids[]" :value="id">
                    </template>
                    <button type="submit"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-[var(--radius-sm)] bg-amber-600 hover:bg-amber-700 text-xs font-semibold text-white transition-colors shadow-xs cursor-pointer">
                        <svg class="size-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                        <span>Kirim Ulang Terpilih (<span x-text="selected.length"></span>)</span>
                    </button>
                </form>

                <form action="{{ route('notifications.bulk-destroy') }}" method="POST" class="inline"
                    @submit.prevent="askConfirm({ title: 'Hapus Log Terpilih', message: 'Hapus ' + selected.length + ' catatan riwayat notifikasi terpilih secara permanen?', confirmLabel: 'Hapus', variant: 'danger' }).then(ok => ok && $el.submit())">
                    @csrf
                    @method('DELETE')
                    <template x-for="id in selected" :key="id">
                        <input type="hidden" name="ids[]" :value="id">
                    </template>
                    <button type="submit"
                        class="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-[var(--radius-sm)] bg-rose-600 hover:bg-rose-700 text-xs font-semibold text-white transition-colors shadow-xs cursor-pointer">
                        <svg class="size-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                        <span>Hapus Terpilih</span>
                    </button>
                </form>
            </div>
        </div>

        {{-- Filter dipindah ke toolbar DataTables oleh tables.js: dropdown jumlah
             baris + filter di kiri, kotak pencarian bawaan DataTables di kanan
             (lengkap dengan ikon). Tetap berupa form GET biasa bila JS mati. --}}
        <form id="wa-filter" method="GET" action="{{ route('notifications.index') }}" class="flex flex-wrap items-center gap-2">
            <select name="status" onchange="this.form.submit()" class="h-8.5 rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface-secondary)] px-2.5 text-xs text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none cursor-pointer">
                <option value="">Semua Status</option>
                <option value="queued" {{ $status === 'queued' ? 'selected' : '' }}>Antrean</option>
                <option value="sent" {{ $status === 'sent' ? 'selected' : '' }}>Terkirim</option>
                <option value="delivered" {{ $status === 'delivered' ? 'selected' : '' }}>Diterima</option>
                <option value="read" {{ $status === 'read' ? 'selected' : '' }}>Dibaca</option>
                <option value="held" {{ $status === 'held' ? 'selected' : '' }}>Ditahan</option>
                <option value="cancelled" {{ $status === 'cancelled' ? 'selected' : '' }}>Dibatalkan</option>
                <option value="failed" {{ $status === 'failed' ? 'selected' : '' }}>Gagal</option>
            </select>

            <select name="event_type" onchange="this.form.submit()" class="h-8.5 rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface-secondary)] px-2.5 text-xs text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none cursor-pointer">
                <option value="">Semua Event</option>
                <option value="check_in" {{ $eventType === 'check_in' ? 'selected' : '' }}>Check-In (Masuk)</option>
                <option value="late" {{ $eventType === 'late' ? 'selected' : '' }}>Late (Terlambat)</option>
                <option value="check_out" {{ $eventType === 'check_out' ? 'selected' : '' }}>Check-Out (Pulang)</option>
            </select>

            @if($search || $status || $eventType)
                <a href="{{ route('notifications.index') }}" class="inline-flex h-8.5 items-center justify-center rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface)] px-3 text-xs font-medium text-[var(--text-secondary)] hover:bg-[var(--surface-secondary)] transition-colors">
                    Reset
                </a>
            @endif
        </form>

        <div>
            <table class="w-full text-left" data-datatable data-page-length="10" data-length-change="true" data-toolbar-filter="#wa-filter" data-empty-message="Belum ada riwayat notifikasi WhatsApp.">
                <thead class="bg-[var(--surface-secondary)] text-[11px] font-semibold text-[var(--text-secondary)] uppercase tracking-wider border-b border-[var(--border-subtle)]">
                    <tr>
                        <th scope="col" class="w-10 px-4 py-2.5 text-center" data-orderable="false">
                            <input type="checkbox" @change="toggleAll($event)" :checked="selected.length > 0 && selected.length === {{ $notifications->count() }}" class="size-4 rounded border-[var(--border)] text-[var(--primary-accessible)] focus:ring-[var(--primary-accessible)] cursor-pointer">
                        </th>
                        <th scope="col" class="px-4 py-2.5">Waktu</th>
                        <th scope="col" class="px-4 py-2.5">Siswa</th>
                        <th scope="col" class="px-4 py-2.5">Wali Murid</th>
                        <th scope="col" class="px-4 py-2.5">Event</th>
                        <th scope="col" class="px-4 py-2.5 font-mono">Idempotency Key</th>
                        <th scope="col" class="px-4 py-2.5 text-center">Status &amp; Percobaan</th>
                        <th scope="col" data-orderable="false" class="px-4 py-2.5 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--border-subtle)] text-[13px]">
                    @foreach($notifications as $notif)
                    <tr class="hover:bg-[var(--surface-secondary)]/50 transition-colors">
                        <td class="w-10 px-4 py-2.5 text-center">
                            <input type="checkbox" :value="'{{ $notif->id }}'" x-model="selected" class="size-4 rounded border-[var(--border)] text-[var(--primary-accessible)] focus:ring-[var(--primary-accessible)] cursor-pointer">
                        </td>
                        <td class="px-4 py-2.5 font-mono text-xs text-[var(--text-secondary)]">
                            <div>{{ $notif->created_at->format('H:i:s') }}</div>
                            <div class="text-[10px] text-[var(--text-muted)]">{{ $notif->created_at->format('d/m/Y') }}</div>
                        </td>
                        <td class="px-4 py-2.5">
                            <div class="font-semibold text-[var(--text)]">{{ $notif->attendance?->student?->name ?? '-' }}</div>
                            <div class="text-[10px] text-[var(--text-muted)]">{{ $notif->attendance?->student?->schoolClass?->name ?? '-' }}</div>
                        </td>
                        <td class="px-4 py-2.5">
                            <div class="font-medium text-[var(--text)]">{{ $notif->guardian?->name ?? '-' }}</div>
                            <div class="text-[11px] font-mono text-[var(--text-muted)]">+{{ $notif->recipient_phone }}</div>
                        </td>
                        <td class="px-4 py-2.5">
                            <span class="inline-flex items-center px-1.5 py-0.2 rounded text-[10px] font-semibold uppercase bg-[var(--surface-secondary)] text-[var(--text-secondary)] border border-[var(--border)]">
                                {{ $notif->event_type }}
                            </span>
                        </td>
                        <td class="px-4 py-2.5 font-mono text-[11px] text-[var(--text-muted)] truncate max-w-xs" title="{{ $notif->idempotency_key }}">
                            {{ $notif->idempotency_key }}
                        </td>
                        <td class="px-4 py-2.5 text-center">
                            <x-notification-status :status="$notif->status" />
                            <div class="text-[10px] text-[var(--text-muted)] mt-0.5">Coba: {{ $notif->attempts }}x</div>
                            @if($notif->error_message)
                                <div class="text-[10px] text-rose-500 truncate max-w-xs mt-0.5" title="{{ $notif->error_message }}">
                                    {{ Str::limit($notif->error_message, 25) }}
                                </div>
                            @endif
                        </td>
                        <td class="px-4 py-2.5 text-right">
                            @if(in_array($notif->status, ['failed', 'queued']))
                                <form method="POST" action="{{ route('notifications.retry', $notif->id) }}"
                                    data-confirm="Kirim ulang notifikasi ini ke WhatsApp?"
                                    data-confirm-title="Kirim Ulang Notifikasi"
                                    data-confirm-label="Kirim Ulang"
                                    data-confirm-variant="primary">
                                    @csrf
                                    <button type="submit" class="inline-flex items-center px-2.5 py-1 bg-amber-600 hover:bg-amber-700 text-white rounded-[var(--radius-sm)] text-[11px] font-semibold transition-colors shadow-xs">
                                        Kirim Ulang
                                    </button>
                                </form>
                            @else
                                <span class="text-[11px] text-[var(--text-muted)]">&mdash;</span>
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

