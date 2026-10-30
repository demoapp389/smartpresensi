<!DOCTYPE html>
<html lang="id" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Dashboard') - Smart Presensi Siswa</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <!-- Favicon -->
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <link rel="alternate icon" type="image/png" href="{{ asset('logo_smart_presensi.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('logo_smart_presensi.png') }}">

    <!-- Inter & JetBrains Mono Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        [x-cloak] { display: none !important; }
    </style>
</head>
<body class="min-h-full bg-[var(--body-bg)] text-[var(--text)] font-[var(--font-admin)] text-sm antialiased"
      x-data="{
          mobileOpen: false,
          railMode: localStorage.getItem('gentelella_rail') === 'true',
          toggleNav() {
              if (window.innerWidth < 1024) {
                  this.mobileOpen = !this.mobileOpen;
              } else {
                  this.railMode = !this.railMode;
                  localStorage.setItem('gentelella_rail', this.railMode);
              }
          }
      }"
      :class="{ 'sidebar-rail': railMode }">

    <div class="min-h-screen flex">
        <!-- Mobile Sidebar Backdrop -->
        <div x-cloak x-show="mobileOpen" @click="mobileOpen = false"
             class="fixed inset-0 z-40 bg-[#0f1623]/60 backdrop-blur-xs lg:hidden transition-opacity"></div>

        <!-- Sidebar Navigation (252px normal / 64px rail) -->
        <aside :class="mobileOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'"
               class="fixed inset-y-0 left-0 z-50 flex flex-col w-[var(--sidebar-w)] bg-[var(--sidebar-bg)] border-r border-[var(--sidebar-border)] transition-[width,transform] duration-200 ease-in-out">
            
            <!-- Brand / Logo Header (56px) -->
            <div class="h-14 flex items-center px-4 border-b border-[var(--sidebar-border)] shrink-0 rail-center">
                <a href="{{ route('dashboard') }}" class="flex items-center min-w-0 group" title="SmartPresensi">
                    <div class="size-8.5 rounded-lg bg-[var(--primary)]/15 border border-[var(--primary)]/40 text-[var(--primary)] flex items-center justify-center shrink-0 shadow-xs group-hover:border-[var(--primary)]/70 group-hover:scale-105 transition-all">
                        <x-icon name="fingerprint" class="size-5 text-[var(--primary)]" />
                    </div>
                    <div class="ml-2.5 flex items-center text-lg leading-none rail-hide select-none tracking-tight">
                        <span class="text-white font-bold">Smart</span><span class="text-[var(--primary)] font-extrabold ml-1">Presensi</span>
                    </div>
                </a>
            </div>

            <!-- Navigation Links Scrollable Area -->
            <nav class="sidebar-nav flex-1 overflow-y-auto px-2.5 py-3 space-y-4 text-xs" aria-label="Navigasi utama">
                @include('partials.sidebar-nav')
            </nav>
        </aside>

        <!-- Main Wrapper -->
        <div class="flex-1 flex flex-col min-w-0 lg:pl-[var(--sidebar-w)] transition-[padding] duration-200">
            <!-- Topbar (56px) dengan progress bar global di atasnya -->
            <div class="sticky top-0 z-30">
                <div x-data x-show="$store.taskProgress.visible" x-cloak
                     x-transition:enter="transition ease-out duration-200"
                     x-transition:enter-start="-translate-y-full opacity-0"
                     x-transition:enter-end="translate-y-0 opacity-100"
                     x-transition:leave="transition ease-in duration-150"
                     x-transition:leave-start="translate-y-0 opacity-100"
                     x-transition:leave-end="-translate-y-full opacity-0"
                     class="border-b bg-[var(--surface)]/95 backdrop-blur-xl"
                     :class="$store.taskProgress.failed ? 'border-rose-300' : ($store.taskProgress.finished ? 'border-emerald-300' : 'border-[var(--primary)]/30')"
                     role="status" aria-live="polite">
                    <div class="flex items-center justify-between gap-3 px-4 py-1.5 text-[11px] sm:px-6">
                        <div class="flex min-w-0 items-center gap-2">
                            <svg x-show="$store.taskProgress.running" class="size-3.5 shrink-0 animate-spin text-[var(--primary)]" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/>
                            </svg>
                            <svg x-show="!$store.taskProgress.running && $store.taskProgress.failed" class="size-3.5 shrink-0 text-rose-600" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>
                            </svg>
                            <svg x-show="!$store.taskProgress.running && !$store.taskProgress.failed && $store.taskProgress.finished" class="size-3.5 shrink-0 text-emerald-600" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                            </svg>
                            <span class="shrink-0 font-semibold text-[var(--text)]" x-text="$store.taskProgress.label"></span>
                            <span class="truncate text-[var(--text-muted)]" x-text="$store.taskProgress.message"></span>
                        </div>
                        <div class="flex shrink-0 items-center gap-2 font-mono tabular-nums">
                            <span x-show="$store.taskProgress.total > 0" class="text-[var(--text-muted)]" x-text="`${$store.taskProgress.processed}/${$store.taskProgress.total} ${$store.taskProgress.unit}`"></span>
                            <span x-show="$store.taskProgress.indeterminate" class="text-[var(--text-muted)]">...</span>
                            <span x-show="!$store.taskProgress.failed && !$store.taskProgress.indeterminate" class="font-semibold" :class="$store.taskProgress.finished ? 'text-emerald-700' : 'text-[var(--text)]'" x-text="`${Math.round($store.taskProgress.percent)}%`"></span>
                            <span x-show="$store.taskProgress.failed" class="font-semibold text-rose-600">Gagal</span>
                        </div>
                    </div>
                    <div class="h-1 w-full overflow-hidden bg-[var(--surface-secondary)]">
                        <div class="h-full transition-[width] duration-300 ease-out"
                             :class="$store.taskProgress.failed ? 'bg-rose-500' : ($store.taskProgress.finished ? 'bg-emerald-500' : ($store.taskProgress.indeterminate ? 'bg-[var(--primary)] animate-pulse opacity-60' : 'bg-[var(--primary)]'))"
                             :style="`width: ${$store.taskProgress.failed || $store.taskProgress.finished || $store.taskProgress.indeterminate ? 100 : Math.max(0, Math.min(100, $store.taskProgress.percent))}%`"></div>
                    </div>
                </div>

                <header class="flex h-14 items-center justify-between gap-3 border-b border-[var(--border)] bg-[var(--topbar-bg)] px-4 sm:px-6 backdrop-blur-xl">
                <div class="flex min-w-0 items-center gap-3">
                    <!-- Hamburger / Rail Toggle Button -->
                    <button type="button" @click="toggleNav()" aria-label="Buka atau tutup navigasi"
                            class="inline-flex size-8.5 items-center justify-center rounded text-[var(--text-secondary)] hover:bg-[var(--border-subtle)] focus-visible:outline-2 focus-visible:outline-[var(--primary-accessible)] transition-colors">
                        <svg class="size-4.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                    </button>

                    <!-- Breadcrumb Navigation -->
                    <nav aria-label="Breadcrumb" class="hidden sm:flex items-center text-[13px] text-[var(--text-secondary)] font-normal gap-1.5 truncate">
                        <a href="{{ route('dashboard') }}" class="hover:text-[var(--text)] transition-colors">Beranda</a>
                        <span class="text-[var(--text-muted)]">/</span>
                        <span class="font-medium text-[var(--text)] truncate">@yield('title', 'Dashboard')</span>
                    </nav>
                </div>

                <!-- Right Topbar Controls -->
                <div class="flex items-center gap-2.5">
                    <!-- LAN Status Badge -->
                    <div class="hidden md:flex items-center gap-1.5 px-2.5 py-1 rounded bg-[var(--surface-secondary)] border border-[var(--border)] text-xs text-[var(--text-secondary)]">
                        <span class="size-2 rounded-full bg-[var(--success)] animate-pulse"></span>
                        <span class="font-normal">LAN Mesin:</span>
                        <span class="font-semibold text-[var(--text)] font-mono text-[11px]">Port 4370</span>
                    </div>

                    <!-- Live Clock -->
                    <div class="flex items-center px-2.5 py-1 rounded bg-[var(--primary-soft)] text-[var(--primary-accessible)] border border-[var(--primary)]/20 text-xs font-mono font-semibold" id="top-clock">
                        {{ now('Asia/Jakarta')->format('H:i:s') }} WIB
                    </div>

                    <!-- User Quick Avatar / Profile Dropdown -->
                    @auth
                    <div class="relative flex items-center pl-1" x-data="{ open: false }">
                        <button type="button"
                                @click="open = !open"
                                @click.outside="open = false"
                                @keydown.escape.window="open = false"
                                aria-expanded="false"
                                :aria-expanded="open.toString()"
                                title="Menu Profil"
                                aria-label="Menu Profil"
                                class="flex items-center p-0.5 rounded-full hover:ring-2 hover:ring-[var(--primary)]/30 focus:outline-none transition-all cursor-pointer">
                            <span class="flex size-8 items-center justify-center rounded-full bg-[var(--primary)] text-xs font-bold text-white shadow-xs">
                                {{ strtoupper(substr(Auth::user()->name, 0, 2)) }}
                            </span>
                        </button>

                        <!-- Dropdown Menu -->
                        <div x-show="open"
                             x-cloak
                             x-transition:enter="transition ease-out duration-100"
                             x-transition:enter-start="transform opacity-0 scale-95"
                             x-transition:enter-end="transform opacity-100 scale-100"
                             x-transition:leave="transition ease-in duration-75"
                             x-transition:leave-start="transform opacity-100 scale-100"
                             x-transition:leave-end="transform opacity-0 scale-95"
                             class="absolute right-0 top-full mt-2 w-56 rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] shadow-lg py-1.5 z-50 text-xs">
                            <!-- User info header -->
                            <div class="px-3.5 py-2.5 border-b border-[var(--border)]">
                                <p class="font-semibold text-[var(--text)] truncate">{{ Auth::user()->name }}</p>
                                <p class="text-[11px] text-[var(--text-muted)] truncate">{{ Auth::user()->email }}</p>
                                <div class="mt-1.5">
                                    <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium bg-[var(--surface-secondary)] text-[var(--text-secondary)] border border-[var(--border)]">
                                        @if(Auth::user()->isAdmin())
                                            Administrator
                                        @elseif(Auth::user()->isHomeroomTeacher())
                                            Wali Kelas {{ Auth::user()->schoolClass?->name }}
                                        @else
                                            Operator
                                        @endif
                                    </span>
                                </div>
                            </div>

                            <!-- Menu Items -->
                            <div class="py-1">
                                <a href="{{ route('profile.edit') }}"
                                   class="flex items-center gap-2.5 px-3.5 py-2 text-[var(--text)] hover:bg-[var(--surface-secondary)] transition-colors">
                                    <svg class="size-4 text-[var(--text-muted)]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                                    </svg>
                                    <span class="font-medium">Profil Saya</span>
                                </a>
                            </div>

                            <!-- Divider & Logout Form -->
                            <div class="border-t border-[var(--border)] pt-1">
                                <form method="POST" action="{{ route('logout') }}"
                                      data-confirm="Apakah Anda yakin ingin keluar dari sistem Smart Presensi?"
                                      data-confirm-title="Konfirmasi Logout"
                                      data-confirm-label="Ya, Keluar"
                                      data-confirm-variant="danger">
                                    @csrf
                                    <button type="submit"
                                            class="w-full flex items-center gap-2.5 px-3.5 py-2 text-rose-600 dark:text-rose-400 hover:bg-rose-50 dark:hover:bg-rose-950/20 transition-colors text-left cursor-pointer">
                                        <x-icon name="logout" class="size-4 text-rose-500" />
                                        <span class="font-medium">Keluar (Logout)</span>
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                    @endauth
                </div>
                </header>
            </div>



            <!-- Flash Session to Toast Dispatcher -->
            @if(session('success'))
                <script>
                    window.addEventListener('DOMContentLoaded', () => {
                        window.toast(@json(session('success')), 'success');
                    });
                </script>
            @endif
            @if(session('error'))
                <script>
                    window.addEventListener('DOMContentLoaded', () => {
                        window.toast(@json(session('error')), 'error', 6000);
                    });
                </script>
            @endif
            @if(session('warning'))
                <script>
                    window.addEventListener('DOMContentLoaded', () => {
                        window.toast(@json(session('warning')), 'warning', 5000);
                    });
                </script>
            @endif
            @if(session('info'))
                <script>
                    window.addEventListener('DOMContentLoaded', () => {
                        window.toast(@json(session('info')), 'info', 4000);
                    });
                </script>
            @endif
            @if($errors->any())
                <script>
                    window.addEventListener('DOMContentLoaded', () => {
                        @foreach($errors->all() as $err)
                            window.toast(@json($err), 'error', 6000);
                        @endforeach
                    });
                </script>
            @endif

            <!-- Main Page Content -->
            <main id="main-content" class="flex-1 min-h-[calc(100vh-112px)] px-4 sm:px-6 py-4">
                <!-- Page Header (Pretitle, Judul 18px, & Actions) -->
                <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <p class="text-[11px] font-semibold uppercase tracking-wider text-[var(--text-muted)]">
                            @yield('page-pretitle', 'Overview')
                        </p>
                        <h1 class="text-[18px] font-semibold text-[var(--text)] leading-snug">
                            @yield('page-title', 'Dashboard')
                        </h1>
                        @hasSection('page-subtitle')
                            <p class="text-xs text-[var(--text-secondary)] mt-0.5">
                                @yield('page-subtitle')
                            </p>
                        @endif
                    </div>
                    <div class="flex flex-wrap items-center justify-end gap-x-3 gap-y-1.5">
                        @hasSection('page-actions')
                            <div class="flex items-center gap-2 flex-wrap">
                                @yield('page-actions')
                            </div>
                        @endif
                        @hasSection('breadcrumb')
                            <nav aria-label="Breadcrumb" class="flex flex-wrap items-center gap-1.5 text-[12px] text-[var(--text-muted)]">
                                @yield('breadcrumb')
                            </nav>
                        @endif
                    </div>
                </div>

                @yield('content')
            </main>

            <!-- Compact Gentelella Footer -->
            <footer class="flex flex-wrap items-center justify-between gap-2 border-t border-[var(--border-subtle)] bg-[var(--surface)] px-4 sm:px-6 py-3.5 text-xs text-[var(--text-muted)]">
                <div>
                    &copy; {{ date('Y') }} <strong class="text-[var(--text-secondary)]">SmartPresensi Biometric Gateway</strong>
                </div>
                <div class="font-medium">
                    Database MySQL: <span class="font-mono text-[var(--text-secondary)]">{{ config('database.connections.'.config('database.default').'.database') }}</span>
                    &bull; <span class="text-[var(--text-secondary)]">{{ config('whatsapp.school_name', 'SMA Negeri 1') }}</span> &bull; <span class="font-mono text-[var(--text-muted)]">v2.2</span>
                </div>
            </footer>
        </div>
    </div>

    <script>
        setInterval(() => {
            const clockEl = document.getElementById('top-clock');
            if (clockEl) {
                const now = new Date();
                const pad = (n) => n.toString().padStart(2, '0');
                clockEl.textContent = `${pad(now.getHours())}:${pad(now.getMinutes())}:${pad(now.getSeconds())} WIB`;
            }
        }, 1000);
    </script>

    <!-- Global Toast Notifications Container (Root Viewport Level) -->
    <div x-data class="fixed top-16 sm:top-18 inset-x-3 sm:inset-x-auto sm:right-5 z-[9999] flex flex-col gap-2 w-auto sm:w-full sm:max-w-sm pointer-events-none" aria-live="polite">
        <template x-for="item in $store.toasts.items" :key="item.id">
            <div class="pointer-events-auto rounded-[var(--radius)] border shadow-xl p-3 text-xs flex items-start gap-2.5 transition-all duration-300 transform backdrop-blur-xs"
                 :class="{
                     'bg-emerald-50 border-emerald-300 text-emerald-900 dark:bg-emerald-950/90 dark:border-emerald-800 dark:text-emerald-200': item.type === 'success',
                     'bg-rose-50 border-rose-300 text-rose-900 dark:bg-rose-950/90 dark:border-rose-800 dark:text-rose-200': item.type === 'error',
                     'bg-amber-50 border-amber-300 text-amber-900 dark:bg-amber-950/90 dark:border-amber-800 dark:text-amber-200': item.type === 'warning',
                     'bg-blue-50 border-blue-300 text-blue-900 dark:bg-blue-950/90 dark:border-blue-800 dark:text-blue-200': item.type === 'info'
                 }"
                 x-transition:enter="transition ease-out duration-300"
                 x-transition:enter-start="opacity-0 -translate-y-2 sm:translate-x-4"
                 x-transition:enter-end="opacity-100 translate-y-0 translate-x-0"
                 x-transition:leave="transition ease-in duration-200"
                 x-transition:leave-start="opacity-100 translate-x-0"
                 x-transition:leave-end="opacity-0 sm:translate-x-8 -translate-y-2">
                
                <!-- Toast Icon -->
                <span class="shrink-0 mt-0.5">
                    <template x-if="item.type === 'success'">
                        <svg class="size-4 text-emerald-600" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                    </template>
                    <template x-if="item.type === 'error'">
                        <svg class="size-4 text-rose-600" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/></svg>
                    </template>
                    <template x-if="item.type === 'warning'">
                        <svg class="size-4 text-amber-600" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>
                    </template>
                    <template x-if="item.type === 'info'">
                        <svg class="size-4 text-blue-600" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/></svg>
                    </template>
                </span>

                <!-- Message -->
                <div class="flex-1 font-medium leading-tight select-text" x-text="item.message"></div>

                <!-- Close button -->
                <button type="button" @click="$store.toasts.remove(item.id)" class="text-current opacity-60 hover:opacity-100 p-0.5 rounded transition-opacity" aria-label="Tutup notifikasi">
                    <svg class="size-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
        </template>
    </div>

    <x-confirm-dialog />

    @stack('modals')
</body>
</html>

