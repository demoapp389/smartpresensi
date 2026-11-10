<!DOCTYPE html>
<html lang="id" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lupa Kata Sandi - Smart Presensi</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <link rel="alternate icon" type="image/png" href="{{ asset('logo_smart_presensi.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('logo_smart_presensi.png') }}">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>[x-cloak] { display: none !important; }</style>
</head>
<body class="h-full flex items-center justify-center p-4 bg-[var(--body-bg)] text-[var(--text)] font-[var(--font-admin)] text-sm antialiased">
    <div class="w-full max-w-sm">
        <div class="bg-[var(--surface)] rounded-[var(--radius)] border border-[var(--border)] shadow-[var(--shadow)] p-6">
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
                <h2 class="text-sm font-bold text-[var(--text)]">Lupa Kata Sandi</h2>
                <p class="text-[11px] text-[var(--text-secondary)] mt-0.5">Masukkan email akun Anda. Kami akan mengirim tautan untuk membuat kata sandi baru.</p>
            </div>

            @if(session('status'))
                <div class="mb-4 p-2.5 rounded-[var(--radius-sm)] bg-emerald-50/80 border border-emerald-200 text-emerald-800 text-xs font-medium">
                    {{ session('status') }}
                </div>
            @endif

            @if($errors->any())
                <div class="mb-4 p-2.5 rounded-[var(--radius-sm)] bg-rose-50/80 border border-rose-200 text-rose-800 text-xs font-medium">
                    {{ $errors->first() }}
                </div>
            @endif

            <form method="POST" action="{{ route('password.email') }}" class="space-y-3.5 text-xs">
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

                <button type="submit"
                    class="w-full py-2 px-3.5 rounded-[var(--radius-sm)] bg-[var(--primary)] hover:brightness-95 text-white font-semibold text-xs shadow-xs transition-colors flex items-center justify-center gap-1.5 mt-1">
                    <span>Kirim Tautan Reset</span>
                    <svg class="size-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                    </svg>
                </button>
            </form>

            <div class="mt-4 pt-3 border-t border-[var(--border-subtle)] text-center">
                <a href="{{ route('login') }}" class="text-[11px] font-semibold text-[var(--primary-accessible)] hover:underline">Kembali ke halaman masuk</a>
            </div>
        </div>

        <p class="text-center text-xs text-[var(--text-muted)] mt-5">
            SmartPresensi Biometric Gateway &middot; Asia/Jakarta
        </p>
    </div>
</body>
</html>
