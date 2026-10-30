{{--
    Modal konfirmasi global (pengganti confirm() bawaan browser).

    Dirender sekali di layout. State dan antrean Promise dikelola oleh
    Alpine store "confirmDialog" (lihat resources/js/app.js):
    panggil window.askConfirm({ title, message, confirmLabel, cancelLabel, variant })
    atau tambahkan atribut data-confirm pada <form> biasa.
--}}
<div x-cloak
     x-data
     x-show="$store.confirmDialog.open"
     x-on:keydown.escape.window="$store.confirmDialog.cancel()"
     x-effect="if ($store.confirmDialog.open) { $nextTick(() => $refs.confirmButton && $refs.confirmButton.focus()); }"
     class="fixed inset-0 z-[60] flex items-center justify-center p-4"
     role="alertdialog"
     aria-modal="true"
     aria-labelledby="confirm-dialog-title"
     aria-describedby="confirm-dialog-message">

    <!-- Backdrop -->
    <div x-show="$store.confirmDialog.open"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         x-on:click="$store.confirmDialog.cancel()"
         class="absolute inset-0 bg-[#0f1623]/60 backdrop-blur-xs"></div>

    <!-- Dialog -->
    <div x-show="$store.confirmDialog.open"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0 translate-y-2 scale-95"
         x-transition:enter-end="opacity-100 translate-y-0 scale-100"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100 scale-100"
         x-transition:leave-end="opacity-0 scale-95"
         class="relative w-full max-w-md overflow-hidden rounded-[var(--radius-lg)] border border-[var(--border)] bg-[var(--surface)] shadow-xl">

        <div class="flex items-start gap-3 px-4 py-3.5">
            <span class="mt-0.5 inline-flex size-9 shrink-0 items-center justify-center rounded-full"
                  :class="{
                      'bg-rose-100 text-rose-600 dark:bg-rose-950/50 dark:text-rose-400': $store.confirmDialog.variant === 'danger',
                      'bg-amber-100 text-amber-600 dark:bg-amber-950/50 dark:text-amber-400': $store.confirmDialog.variant === 'warning',
                      'bg-blue-100 text-blue-600 dark:bg-blue-950/50 dark:text-blue-400': $store.confirmDialog.variant === 'info',
                      'bg-[var(--primary-soft)] text-[var(--primary)]': $store.confirmDialog.variant === 'primary'
                  }">
                <template x-if="$store.confirmDialog.variant === 'danger'">
                    <svg class="size-4.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/></svg>
                </template>
                <template x-if="$store.confirmDialog.variant === 'warning'">
                    <svg class="size-4.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v3.75m0 3.75h.008v.008H12v-.008zM21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                </template>
                <template x-if="$store.confirmDialog.variant === 'info'">
                    <svg class="size-4.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z"/></svg>
                </template>
                <template x-if="$store.confirmDialog.variant === 'primary'">
                    <svg class="size-4.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9 5.25h.008v.008H12v-.008z"/></svg>
                </template>
            </span>

            <div class="min-w-0 flex-1">
                <h3 id="confirm-dialog-title"
                    class="text-sm font-semibold text-[var(--text)]"
                    x-text="$store.confirmDialog.title"></h3>
                <p id="confirm-dialog-message"
                   class="mt-1 text-xs leading-relaxed text-[var(--text-secondary)]"
                   x-text="$store.confirmDialog.message"></p>
            </div>
        </div>

        <div class="flex flex-wrap items-center justify-end gap-2 border-t border-[var(--border)] bg-[var(--surface-secondary)] px-4 py-2.5">
            <button type="button"
                    x-on:click="$store.confirmDialog.cancel()"
                    x-text="$store.confirmDialog.cancelLabel"
                    class="inline-flex h-8.5 items-center rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface)] px-3 text-xs font-semibold text-[var(--text-secondary)] transition-colors hover:bg-[var(--surface-secondary)]"></button>
            <button type="button"
                    x-ref="confirmButton"
                    x-on:click="$store.confirmDialog.confirm()"
                    x-text="$store.confirmDialog.confirmLabel"
                    class="inline-flex h-8.5 items-center rounded-[var(--radius-sm)] px-3 text-xs font-semibold text-white transition-colors"
                    :class="{
                        'bg-[var(--danger)] hover:brightness-95': $store.confirmDialog.variant === 'danger',
                        'bg-[var(--warning)] hover:brightness-95': $store.confirmDialog.variant === 'warning',
                        'bg-[var(--info)] hover:brightness-95': $store.confirmDialog.variant === 'info',
                        'bg-[var(--primary)] hover:bg-[var(--primary-dark)]': $store.confirmDialog.variant === 'primary'
                    }"></button>
        </div>
    </div>
</div>
