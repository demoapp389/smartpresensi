<!DOCTYPE html>
<html lang="id" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login Petugas - Smart Presensi</title>
    <!-- Favicon -->
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <link rel="alternate icon" type="image/png" href="{{ asset('logo_smart_presensi.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('logo_smart_presensi.png') }}">

    <!-- Inter Font -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>[x-cloak] { display: none !important; }</style>
</head>
<body class="h-full flex items-center justify-center p-4 bg-[var(--body-bg)] text-[var(--text)] font-[var(--font-admin)] text-sm antialiased">
    <div class="w-full max-w-sm">
        <!-- Login Card -->
        <div class="bg-[var(--surface)] rounded-[var(--radius)] border border-[var(--border)] shadow-[var(--shadow)] p-6">
            <!-- Brand & Icon Header inside Card -->
            <div class="text-center mb-6">
                <div class="inline-flex size-11 rounded-lg bg-[var(--primary)]/10 border border-[var(--primary)]/30 text-[var(--primary)] items-center justify-center shadow-xs mb-3">
                    <x-icon name="fingerprint" class="size-6 text-[var(--primary)]" />
                </div>
                <div class="flex items-center justify-center text-xl font-bold text-[var(--primary)] leading-none select-none tracking-tight">
                    <span>Smart Presensi</span>
                </div>
                <p class="text-xs text-[var(--text-secondary)] mt-1.5">Integrasi Mesin Fingerprint &amp; WhatsApp Gateway</p>
            </div>

            <div class="border-t border-[var(--border-subtle)] pt-4 mb-4">
                <h2 class="text-sm font-bold text-[var(--text)]">Masuk ke Portal</h2>
                <p class="text-[11px] text-[var(--text-secondary)] mt-0.5">Gunakan akun Administrator atau Operator sekolah.</p>
            </div>

            @if(session('info'))
                <div class="mb-4 p-2.5 rounded-[var(--radius-sm)] bg-blue-50/80 border border-blue-200 text-blue-800 text-xs font-medium">
                    {{ session('info') }}
                </div>
            @endif

            @if($errors->any())
                <div class="mb-4 p-2.5 rounded-[var(--radius-sm)] bg-rose-50/80 border border-rose-200 text-rose-800 text-xs font-medium">
                    {{ $errors->first() }}
                </div>
            @endif

            <form method="POST" action="{{ route('login.process') }}" class="space-y-3.5 text-xs">
                @csrf
                <div>
                    <label for="email" class="block font-semibold text-[var(--text)] mb-1">Alamat Email</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-[var(--text-muted)]">
                            <svg class="size-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 12a4 4 0 10-8 0 4 4 0 008 0zm0 0v1.5a2.5 2.5 0 005 0V12a9 9 0 10-9 9m4.5-1.206a8.959 8.959 0 01-4.5 1.207" />
                            </svg>
                        </div>
                        <input type="email" id="email" name="email" value="{{ old('email') }}" required autofocus
                            class="h-9 w-full pl-9 pr-3 rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] text-xs text-[var(--text)] placeholder:text-[var(--text-muted)] focus:border-[var(--primary-accessible)] focus:outline-none focus:ring-1 focus:ring-[var(--primary-accessible)]"
                            placeholder="nama@sekolah.sch.id">
                    </div>
                </div>

                <div x-data="{ showPassword: false }">
                    <label for="password" class="block font-semibold text-[var(--text)] mb-1">Kata Sandi</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-[var(--text-muted)]">
                            <svg class="size-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                            </svg>
                        </div>
                        <input :type="showPassword ? 'text' : 'password'" id="password" name="password" required
                            class="h-9 w-full pl-9 pr-10 rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] text-xs text-[var(--text)] placeholder:text-[var(--text-muted)] focus:border-[var(--primary-accessible)] focus:outline-none focus:ring-1 focus:ring-[var(--primary-accessible)]"
                            placeholder="••••••••">
                        <button type="button" @click="showPassword = !showPassword"
                            :aria-label="showPassword ? 'Sembunyikan kata sandi' : 'Tampilkan kata sandi'"
                            :aria-pressed="showPassword ? 'true' : 'false'"
                            class="absolute inset-y-0 right-0 pr-3 flex items-center text-[var(--text-muted)] hover:text-[var(--text)] transition-colors cursor-pointer">
                            <svg x-show="! showPassword" class="size-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                            </svg>
                            <svg x-show="showPassword" x-cloak class="size-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l18 18" />
                            </svg>
                        </button>
                    </div>
                </div>

                <div class="flex items-center justify-between text-xs pt-0.5">
                    <label class="flex items-center text-[var(--text-secondary)] cursor-pointer select-none">
                        <input type="checkbox" name="remember" class="size-3.5 rounded border-[var(--border)] text-[var(--primary-accessible)] focus:ring-[var(--primary-accessible)]">
                        <span class="ml-2 text-[11px]">Ingat sesi saya</span>
                    </label>
                    <a href="{{ route('password.request') }}" class="text-[11px] font-semibold text-[var(--primary-accessible)] hover:underline">Lupa kata sandi?</a>
                </div>

                <button type="submit"
                    class="w-full py-2 px-3.5 rounded-[var(--radius-sm)] bg-[var(--primary)] hover:brightness-95 text-white font-semibold text-xs shadow-xs transition-colors flex items-center justify-center gap-1.5 mt-1">
                    <span>Masuk ke Dashboard</span>
                    <svg class="size-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                    </svg>
                </button>
            </form>
        </div>

        <!-- Footer note -->
        <p class="text-center text-xs text-[var(--text-muted)] mt-5">
            SmartPresensi Biometric Gateway
        </p>
    </div>
</body>
</html>
