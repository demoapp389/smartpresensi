@extends('layouts.app')

@section('title', 'Profil Saya')
@section('page-pretitle', 'Akun Pengguna')
@section('page-title', 'Profil Pengguna')
@section('page-subtitle', 'Kelola informasi identitas pribadi, kredensial akses, dan keamanan akun Anda')

@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="transition-colors hover:text-[var(--text)]">Beranda</a>
    <span aria-hidden="true">/</span>
    <span>Akun</span>
    <span aria-hidden="true">/</span>
    <span class="font-medium text-[var(--text)]" aria-current="page">Profil Saya</span>
@endsection

@section('content')
<div class="space-y-5">
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-5 items-start">
        <!-- Kolom Kiri: Profil Summary Card (4 Kolom ala Gentelella col-4-8) -->
        <div class="lg:col-span-4 space-y-4">
            <!-- Kartu Ringkasan Akun -->
            <div class="rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] shadow-[var(--shadow)] overflow-hidden">
                <div class="p-6 text-center">
                    <!-- Avatar Lingkaran Besar -->
                    <div class="flex size-22 items-center justify-center rounded-full bg-[var(--primary)] text-white text-2xl font-bold shadow-md mx-auto mb-3.5 ring-4 ring-[var(--surface-secondary)]">
                        {{ strtoupper(substr($user->name, 0, 2)) }}
                    </div>

                    <h2 class="text-base font-bold text-[var(--text)] leading-tight">{{ $user->name }}</h2>
                    <p class="text-xs text-[var(--text-muted)] mt-1 break-all">{{ $user->email }}</p>

                    <div class="mt-3 flex items-center justify-center gap-1.5 flex-wrap">
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[11px] font-semibold bg-[var(--primary)]/10 text-[var(--primary)] border border-[var(--primary)]/20">
                            @if($user->isAdmin())
                                Administrator
                            @elseif($user->isHomeroomTeacher())
                                Wali Kelas {{ $user->schoolClass?->name }}
                            @else
                                Operator Absensi
                            @endif
                        </span>
                        <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[11px] font-semibold bg-emerald-500/10 text-emerald-700 dark:text-emerald-400 border border-emerald-500/20">
                            <span class="size-1.5 rounded-full bg-emerald-500"></span>
                            Akun Aktif
                        </span>
                    </div>
                </div>

                <!-- Informasi Detail Ringkas -->
                <div class="border-t border-[var(--border-subtle)] bg-[var(--surface-secondary)]/40 px-4 py-3 text-xs divide-y divide-[var(--border-subtle)]">
                    <div class="flex items-center justify-between py-2">
                        <span class="text-[var(--text-muted)]">Peran Sistem</span>
                        <span class="font-medium text-[var(--text)] uppercase">{{ $user->role }}</span>
                    </div>
                    @if($user->isHomeroomTeacher())
                    <div class="flex items-center justify-between py-2">
                        <span class="text-[var(--text-muted)]">Kelas Binaan</span>
                        <span class="font-medium text-[var(--text)]">{{ $user->schoolClass?->name ?? 'Belum Ditentukan' }}</span>
                    </div>
                    @endif
                    <div class="flex items-center justify-between py-2">
                        <span class="text-[var(--text-muted)]">Terdaftar Sejak</span>
                        <span class="font-medium text-[var(--text)]">{{ $user->created_at ? $user->created_at->isoFormat('D MMMM Y') : '-' }}</span>
                    </div>
                    <div class="flex items-center justify-between py-2">
                        <span class="text-[var(--text-muted)]">Terakhir Diperbarui</span>
                        <span class="font-medium text-[var(--text)]">{{ $user->updated_at ? $user->updated_at->isoFormat('D MMM Y, HH:mm') : '-' }} WIB</span>
                    </div>
                </div>
            </div>

            <!-- Kartu Petunjuk Keamanan -->
            <div class="rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] p-4 shadow-[var(--shadow)]">
                <div class="flex items-start gap-2.5">
                    <div class="flex size-7 shrink-0 items-center justify-center rounded-full bg-[var(--primary)]/10 text-[var(--primary)]">
                        <svg class="size-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                        </svg>
                    </div>
                    <div class="min-w-0">
                        <h3 class="text-xs font-bold text-[var(--text)]">Keamanan Kredensial</h3>
                        <p class="text-[11px] text-[var(--text-secondary)] mt-1 leading-relaxed">
                            Pastikan kata sandi Anda memiliki panjang minimal 8 karakter dan tidak dibagikan kepada orang lain demi menjaga integritas data presensi sekolah.
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Kolom Kanan: Form Edit Profil & Kata Sandi (8 Kolom ala Gentelella) -->
        <div class="lg:col-span-8">
            <form method="POST" action="{{ route('profile.update') }}" class="space-y-5">
                @csrf
                @method('PUT')

                <!-- Card 1: Informasi Pribadi & Akun -->
                <div class="rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] shadow-[var(--shadow)] overflow-hidden">
                    <div class="border-b border-[var(--border-subtle)] px-5 py-3.5">
                        <h2 class="text-xs font-bold text-[var(--text)] uppercase tracking-wider">Informasi Pribadi &amp; Akun</h2>
                        <p class="text-[11px] text-[var(--text-muted)] mt-0.5">Perbarui nama pengguna dan alamat email Anda yang terdaftar pada sistem.</p>
                    </div>

                    <div class="p-5 space-y-4">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <!-- Nama Lengkap -->
                            <div>
                                <label for="name" class="block text-xs font-semibold text-[var(--text)] mb-1">
                                    Nama Lengkap <span class="text-rose-500">*</span>
                                </label>
                                <input type="text"
                                       id="name"
                                       name="name"
                                       value="{{ old('name', $user->name) }}"
                                       required
                                       class="w-full rounded-[var(--radius-sm)] border @error('name') border-rose-500 @else border-[var(--border)] @enderror bg-[var(--surface)] px-3 py-2 text-xs text-[var(--text)] focus:border-[var(--primary)] focus:outline-none focus:ring-1 focus:ring-[var(--primary)] transition-colors"
                                       placeholder="Masukkan nama lengkap Anda">
                                @error('name')
                                    <p class="mt-1 text-[11px] text-rose-500">{{ $message }}</p>
                                @enderror
                            </div>

                            <!-- Alamat Email -->
                            <div>
                                <label for="email" class="block text-xs font-semibold text-[var(--text)] mb-1">
                                    Alamat Email <span class="text-rose-500">*</span>
                                </label>
                                <input type="email"
                                       id="email"
                                       name="email"
                                       value="{{ old('email', $user->email) }}"
                                       required
                                       class="w-full rounded-[var(--radius-sm)] border @error('email') border-rose-500 @else border-[var(--border)] @enderror bg-[var(--surface)] px-3 py-2 text-xs text-[var(--text)] focus:border-[var(--primary)] focus:outline-none focus:ring-1 focus:ring-[var(--primary)] transition-colors"
                                       placeholder="contoh@sekolah.sch.id">
                                @error('email')
                                    <p class="mt-1 text-[11px] text-rose-500">{{ $message }}</p>
                                @enderror
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 pt-2">
                            <!-- Peran Akun (Hanya Baca / Read-Only) -->
                            <div>
                                <label class="block text-xs font-semibold text-[var(--text-secondary)] mb-1">
                                    Peran Akun (Role)
                                </label>
                                <div class="w-full rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface-secondary)]/70 px-3 py-2 text-xs text-[var(--text-muted)] cursor-not-allowed">
                                    @if($user->isAdmin())
                                        Administrator Sistem (Full Access)
                                    @elseif($user->isHomeroomTeacher())
                                        Wali Kelas (Akses Monitoring Kelas)
                                    @else
                                        Operator Absensi (Operasional Presensi)
                                    @endif
                                </div>
                                <p class="mt-1 text-[10px] text-[var(--text-muted)]">Peran akun hanya dapat diubah oleh Administrator melalui menu Manajemen Pengguna.</p>
                            </div>

                            <!-- Kelas yang Diampu (Jika Wali Kelas) / Status Akun -->
                            <div>
                                @if($user->isHomeroomTeacher())
                                    <label class="block text-xs font-semibold text-[var(--text-secondary)] mb-1">
                                        Kelas yang Diampu
                                    </label>
                                    <div class="w-full rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface-secondary)]/70 px-3 py-2 text-xs text-[var(--text-muted)] cursor-not-allowed">
                                        {{ $user->schoolClass?->name ?? 'Belum Ditugaskan' }}
                                    </div>
                                    <p class="mt-1 text-[10px] text-[var(--text-muted)]">Penetapan kelas diatur oleh Administrator pada data kelas.</p>
                                @else
                                    <label class="block text-xs font-semibold text-[var(--text-secondary)] mb-1">
                                        Status Keaktifan
                                    </label>
                                    <div class="w-full rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface-secondary)]/70 px-3 py-2 text-xs text-[var(--text-muted)] cursor-not-allowed flex items-center gap-1.5">
                                        <span class="size-2 rounded-full bg-emerald-500"></span>
                                        <span>Aktif &amp; Terverifikasi</span>
                                    </div>
                                    <p class="mt-1 text-[10px] text-[var(--text-muted)]">Akun Anda aktif dan memiliki izin operasional.</p>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Card 2: Keamanan & Kata Sandi -->
                <div class="rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] shadow-[var(--shadow)] overflow-hidden">
                    <div class="border-b border-[var(--border-subtle)] px-5 py-3.5">
                        <h2 class="text-xs font-bold text-[var(--text)] uppercase tracking-wider">Keamanan &amp; Kata Sandi</h2>
                        <p class="text-[11px] text-[var(--text-muted)] mt-0.5">Biarkan kolom di bawah ini kosong jika Anda tidak bermaksud mengubah kata sandi.</p>
                    </div>

                    <div class="p-5 space-y-4">
                        <!-- Kata Sandi Saat Ini -->
                        <div class="max-w-md">
                            <label for="current_password" class="block text-xs font-semibold text-[var(--text)] mb-1">
                                Kata Sandi Saat Ini
                            </label>
                            <div class="relative" x-data="{ show: false }">
                                <input type="password"
                                       :type="show ? 'text' : 'password'"
                                       id="current_password"
                                       name="current_password"
                                       autocomplete="current-password"
                                       class="w-full rounded-[var(--radius-sm)] border @error('current_password') border-rose-500 @else border-[var(--border)] @enderror bg-[var(--surface)] pl-3 pr-10 py-2 text-xs text-[var(--text)] focus:border-[var(--primary)] focus:outline-none focus:ring-1 focus:ring-[var(--primary)] transition-colors"
                                       placeholder="Ketik kata sandi lama Anda">
                                <button type="button"
                                        @click="show = !show"
                                        tabindex="-1"
                                        class="absolute inset-y-0 right-0 pr-3 flex items-center text-[var(--text-muted)] hover:text-[var(--text)] transition-colors focus:outline-none cursor-pointer"
                                        :aria-label="show ? 'Sembunyikan kata sandi' : 'Tampilkan kata sandi'">
                                    <x-icon name="eye" class="size-4" x-show="!show" />
                                    <x-icon name="eye-off" class="size-4" x-show="show" x-cloak />
                                </button>
                            </div>
                            <p class="mt-1 text-[11px] text-[var(--text-muted)]">Wajib diisi jika Anda mengisi kolom kata sandi baru.</p>
                            @error('current_password')
                                <p class="mt-1 text-[11px] text-rose-500">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 pt-1">
                            <!-- Kata Sandi Baru -->
                            <div>
                                <label for="password" class="block text-xs font-semibold text-[var(--text)] mb-1">
                                    Kata Sandi Baru
                                </label>
                                <div class="relative" x-data="{ show: false }">
                                    <input type="password"
                                           :type="show ? 'text' : 'password'"
                                           id="password"
                                           name="password"
                                           autocomplete="new-password"
                                           class="w-full rounded-[var(--radius-sm)] border @error('password') border-rose-500 @else border-[var(--border)] @enderror bg-[var(--surface)] pl-3 pr-10 py-2 text-xs text-[var(--text)] focus:border-[var(--primary)] focus:outline-none focus:ring-1 focus:ring-[var(--primary)] transition-colors"
                                           placeholder="Minimal 8 karakter">
                                    <button type="button"
                                            @click="show = !show"
                                            tabindex="-1"
                                            class="absolute inset-y-0 right-0 pr-3 flex items-center text-[var(--text-muted)] hover:text-[var(--text)] transition-colors focus:outline-none cursor-pointer"
                                            :aria-label="show ? 'Sembunyikan kata sandi' : 'Tampilkan kata sandi'">
                                        <x-icon name="eye" class="size-4" x-show="!show" />
                                        <x-icon name="eye-off" class="size-4" x-show="show" x-cloak />
                                    </button>
                                </div>
                                <p class="mt-1 text-[11px] text-[var(--text-muted)]">Gunakan minimal 8 karakter kombinasi huruf dan angka.</p>
                                @error('password')
                                    <p class="mt-1 text-[11px] text-rose-500">{{ $message }}</p>
                                @enderror
                            </div>

                            <!-- Konfirmasi Kata Sandi Baru -->
                            <div>
                                <label for="password_confirmation" class="block text-xs font-semibold text-[var(--text)] mb-1">
                                    Konfirmasi Kata Sandi Baru
                                </label>
                                <div class="relative" x-data="{ show: false }">
                                    <input type="password"
                                           :type="show ? 'text' : 'password'"
                                           id="password_confirmation"
                                           name="password_confirmation"
                                           autocomplete="new-password"
                                           class="w-full rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface)] pl-3 pr-10 py-2 text-xs text-[var(--text)] focus:border-[var(--primary)] focus:outline-none focus:ring-1 focus:ring-[var(--primary)] transition-colors"
                                           placeholder="Ulangi kata sandi baru">
                                    <button type="button"
                                            @click="show = !show"
                                            tabindex="-1"
                                            class="absolute inset-y-0 right-0 pr-3 flex items-center text-[var(--text-muted)] hover:text-[var(--text)] transition-colors focus:outline-none cursor-pointer"
                                            :aria-label="show ? 'Sembunyikan kata sandi' : 'Tampilkan kata sandi'">
                                        <x-icon name="eye" class="size-4" x-show="!show" />
                                        <x-icon name="eye-off" class="size-4" x-show="show" x-cloak />
                                    </button>
                                </div>
                                <p class="mt-1 text-[11px] text-[var(--text-muted)]">Pastikan sama persis dengan kata sandi baru.</p>
                            </div>
                        </div>
                    </div>

                    <!-- Tombol Aksi Simpan -->
                    <div class="flex items-center justify-end gap-2.5 border-t border-[var(--border-subtle)] bg-[var(--surface-secondary)]/40 px-5 py-3">
                        <button type="submit"
                                class="inline-flex h-8.5 items-center justify-center gap-1.5 rounded-[var(--radius-sm)] bg-[var(--primary)] px-5 text-xs font-semibold text-white shadow-2xs transition-colors hover:brightness-95">
                            <svg class="size-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                            </svg>
                            <span>Simpan Perubahan</span>
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
