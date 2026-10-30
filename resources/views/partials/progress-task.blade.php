{{--
  Progress bar untuk proses yang berjalan lama (import Excel, sinkronisasi massal).

  Cara pakai:
    <div x-data="taskRunner({ progressUrl: '...' })">
        <form @submit.prevent="submit($el, 'Label Opsi')"> ... </form>
        @include('partials.progress-task')
    </div>

  Variabel opsional saat inisialisasi:
    - reloadOnDone : reload halaman setelah sukses (default true)
    - interval     : jeda polling dalam ms (default 800)
    - inline       : true bila proses berjalan di dalam modal; bar global di
                     topbar tidak dipakai, progress cukup tampil di sini

  Metode Alpine yang tersedia:
    - submit(form, label) : jalankan proses dari sebuah <form>
    - run(url, label, body): jalankan proses dari URL biasa
--}}

<div x-show="running || finished || failed" x-cloak class="space-y-2">
    <div class="flex items-start justify-between gap-3 text-xs">
        <div class="min-w-0">
            <p class="font-semibold text-[var(--text)] truncate" x-text="label"></p>
            <p class="text-[11px] text-[var(--text-secondary)] mt-0.5 break-words" x-text="message"></p>
        </div>
        <span class="shrink-0 font-mono font-bold text-[var(--text)] tabular-nums"
              x-text="failed ? 'Gagal' : (finished ? '100%' : (running && total <= 0 ? '...' : percent + '%'))"></span>
    </div>

    <div class="h-2 w-full rounded-full bg-[var(--surface-secondary)] border border-[var(--border)] overflow-hidden">
        <div class="h-full rounded-full transition-all duration-300 ease-out"
             :class="failed ? 'bg-rose-500' : (finished ? 'bg-emerald-500' : (running && total <= 0 ? 'bg-[var(--primary)] animate-pulse opacity-60' : 'bg-[var(--primary)]'))"
             :style="`width: ${failed || finished || (running && total <= 0) ? 100 : percent}%`"></div>
    </div>

    <p class="text-[11px] font-mono text-[var(--text-muted)]"
       x-show="total > 0"
       x-text="`${processed} / ${total} ${unit} diproses`"></p>

    <p class="text-[11px] text-[var(--text-muted)]" x-show="!total && running">
        Sedang berjalan, menunggu mesin menyelesaikan proses...
    </p>
</div>
