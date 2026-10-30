@extends('layouts.app')

@section('title', 'Manajemen Pengguna')
@section('page-pretitle', 'Pengaturan & Keamanan')
@section('page-title', 'Manajemen Pengguna Sistem')
@section('page-subtitle', 'Kelola akun hak akses Administrator, Operator Absensi, dan Wali Kelas')

@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="transition-colors hover:text-[var(--text)]">Beranda</a>
    <span aria-hidden="true">/</span>
    <span>Pengaturan &amp; Keamanan</span>
    <span aria-hidden="true">/</span>
    <span class="font-medium text-[var(--text)]" aria-current="page">Manajemen Pengguna</span>
@endsection

@section('content')
<div class="space-y-4" x-data="{
    addModal: false,
    editModal: false,
    activeUser: { role: 'operator', class_id: '' },
    openEdit(user) {
        this.activeUser = {
            id: user.id,
            name: user.name,
            email: user.email,
            role: user.role,
            class_id: user.class_id || '',
            is_active: user.is_active ? 1 : 0
        };
        this.editModal = true;
    }
}" @open-add-user.window="addModal = true">

    <!-- User Table Card -->
    <section class="min-w-0 rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] shadow-[var(--shadow)] overflow-hidden">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--border-subtle)] px-4 py-3">
            <h2 class="text-xs font-bold text-[var(--text)]">Daftar Pengguna Akun Terdaftar</h2>

            <button type="button" @click="$dispatch('open-add-user')"
                class="inline-flex h-8.5 items-center justify-center gap-1.5 rounded-[var(--radius-sm)] bg-[var(--primary)] px-3 text-xs font-semibold text-white transition-colors hover:brightness-90">
                <svg class="size-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/></svg>
                <span>Tambah Pengguna</span>
            </button>
        </div>

        <div>
            <table class="min-w-[750px] w-full text-left text-xs" data-datatable data-page-length="10" data-length-change="true" data-empty-message="Tidak ada data pengguna yang sesuai">
                <thead class="bg-[var(--surface-secondary)] text-[11px] font-semibold text-[var(--text-secondary)] uppercase tracking-wider border-b border-[var(--border-subtle)]">
                    <tr>
                        <th scope="col" class="px-4 py-2.5">Nama &amp; Email</th>
                        <th scope="col" class="px-4 py-2.5">Peran (Role)</th>
                        <th scope="col" class="px-4 py-2.5">Kelas yang Diampu</th>
                        <th scope="col" class="px-4 py-2.5">Status</th>
                        <th scope="col" class="px-4 py-2.5">Dibuat Pada</th>
                        <th scope="col" data-orderable="false" class="px-4 py-2.5 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--border-subtle)] text-[13px]">
                    @foreach($users as $u)
                    <tr class="hover:bg-[var(--surface-secondary)]/50 transition-colors">
                        <td class="px-4 py-2.5">
                            <div class="flex items-center gap-2.5">
                                <span class="size-7 rounded-full flex items-center justify-center font-bold text-xs uppercase text-white shrink-0
                                    {{ $u->role === 'admin' ? 'bg-slate-700' : ($u->role === 'operator' ? 'bg-purple-600' : 'bg-emerald-600') }}">
                                    {{ substr($u->name, 0, 2) }}
                                </span>
                                <div>
                                    <div class="font-semibold text-[var(--text)] flex items-center gap-1.5">
                                        <span>{{ $u->name }}</span>
                                        @if($u->id === Auth::id())
                                            <span class="text-[9px] px-1.5 py-0.2 rounded font-bold bg-blue-100 text-blue-800 border border-blue-200">Anda</span>
                                        @endif
                                    </div>
                                    <div class="text-[11px] text-[var(--text-muted)] font-mono">{{ $u->email }}</div>
                                </div>
                            </div>
                        </td>

                        <td class="px-4 py-2.5">
                            @if($u->role === 'admin')
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold bg-slate-100 text-slate-800 border border-slate-300">
                                    Administrator
                                </span>
                            @elseif($u->role === 'operator')
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold bg-purple-100 text-purple-800 border border-purple-300">
                                    Operator Absensi
                                </span>
                            @elseif($u->role === 'wali_kelas')
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold bg-emerald-100 text-emerald-800 border border-emerald-300">
                                    Wali Kelas
                                </span>
                            @else
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold bg-gray-100 text-gray-800">
                                    {{ ucfirst($u->role) }}
                                </span>
                            @endif
                        </td>

                        <td class="px-4 py-2.5">
                            @if($u->role === 'wali_kelas')
                                @if($u->schoolClass)
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                        Kelas {{ $u->schoolClass->name }}
                                    </span>
                                @else
                                    <span class="text-xs text-amber-600 font-medium">Belum Ditugaskan</span>
                                @endif
                            @else
                                <span class="text-[var(--text-muted)]">-</span>
                            @endif
                        </td>

                        <td class="px-4 py-2.5">
                            @if($u->is_active)
                                <span class="inline-flex items-center gap-1 text-[11px] font-medium text-emerald-600">
                                    <span class="size-1.5 rounded-full bg-emerald-500"></span> Aktif
                                </span>
                            @else
                                <span class="inline-flex items-center gap-1 text-[11px] font-medium text-rose-600">
                                    <span class="size-1.5 rounded-full bg-rose-500"></span> Nonaktif
                                </span>
                            @endif
                        </td>

                        <td class="px-4 py-2.5 text-xs text-[var(--text-muted)] font-mono">
                            {{ $u->created_at ? $u->created_at->format('d/m/Y') : '-' }}
                        </td>

                        <td class="px-4 py-2.5 text-right space-x-1">
                            <button type="button" @click="openEdit({{ $u->toJson() }})"
                                class="inline-flex items-center px-2.5 py-1 rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface)] hover:bg-[var(--surface-secondary)] text-xs font-semibold text-[var(--text)] transition-colors">
                                Edit
                            </button>

                            @if($u->id !== Auth::id())
                            <form action="{{ route('users.destroy', $u) }}" method="POST" class="inline"
                                data-confirm="Apakah Anda yakin ingin menghapus akun {{ $u->name }}?"
                                data-confirm-title="Hapus Akun Pengguna"
                                data-confirm-label="Hapus Akun"
                                data-confirm-variant="danger">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="inline-flex items-center px-2.5 py-1 rounded-[var(--radius-sm)] border border-rose-200 bg-rose-50 hover:bg-rose-100 text-xs font-semibold text-rose-700 transition-colors">
                                    Hapus
                                </button>
                            </form>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <!-- Modal Tambah Pengguna -->
    <div x-cloak x-show="addModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-[#0f1623]/60 backdrop-blur-xs overflow-y-auto">
        <div @click.away="addModal = false" class="bg-[var(--surface)] rounded-[var(--radius-lg)] border border-[var(--border)] shadow-xl w-full max-w-lg p-5 my-8"
             x-data="{ roleSelected: 'operator' }">
            <h3 class="text-sm font-bold text-[var(--text)] mb-1">Tambah Pengguna Baru</h3>
            <p class="text-[11px] text-[var(--text-secondary)] mb-4">Lengkapi data akun, hak akses, dan kelas yang diampu</p>

            <form action="{{ route('users.store') }}" method="POST" class="space-y-3 text-xs">
                @csrf
                <div>
                    <label class="block font-semibold text-[var(--text)] mb-1">Nama Lengkap *</label>
                    <input type="text" name="name" required placeholder="Contoh: Dra. Sri Wahyuni"
                        class="h-9 w-full rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] px-3 text-xs text-[var(--text)] placeholder:text-[var(--text-muted)] focus:border-[var(--primary-accessible)] focus:outline-none focus:ring-1 focus:ring-[var(--primary-accessible)]">
                </div>

                <div>
                    <label class="block font-semibold text-[var(--text)] mb-1">Alamat Email *</label>
                    <input type="email" name="email" required placeholder="nama@sekolah.sch.id"
                        class="h-9 w-full rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] px-3 text-xs text-[var(--text)] placeholder:text-[var(--text-muted)] focus:border-[var(--primary-accessible)] focus:outline-none focus:ring-1 focus:ring-[var(--primary-accessible)]">
                </div>

                <div>
                    <label class="block font-semibold text-[var(--text)] mb-1">Kata Sandi (Password) *</label>
                    <input type="password" name="password" required minlength="8" placeholder="Minimal 8 karakter"
                        class="h-9 w-full rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] px-3 text-xs text-[var(--text)] placeholder:text-[var(--text-muted)] focus:border-[var(--primary-accessible)] focus:outline-none focus:ring-1 focus:ring-[var(--primary-accessible)]">
                </div>

                <div>
                    <label class="block font-semibold text-[var(--text)] mb-1">Peran Hak Akses (Role) *</label>
                    <select name="role" x-model="roleSelected" required
                        class="h-9 w-full rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] px-3 text-xs text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none focus:ring-1 focus:ring-[var(--primary-accessible)] cursor-pointer">
                        <option value="operator">Operator Absensi (Koreksi, Siswa, Outbox)</option>
                        <option value="wali_kelas">Wali Kelas (Khusus Absensi Kelas yang Diampu)</option>
                        <option value="admin">Administrator (Akses Penuh Semua Modul)</option>
                    </select>
                </div>

                <!-- Dropdown Kelas (Muncul jika role === 'wali_kelas') -->
                <div x-show="roleSelected === 'wali_kelas'" x-transition class="p-2.5 rounded-[var(--radius)] bg-emerald-50 border border-emerald-200 space-y-1.5">
                    <label class="block font-semibold text-emerald-900">Kelas yang Diampu *</label>
                    <select name="class_id" :required="roleSelected === 'wali_kelas'"
                        class="h-9 w-full rounded-[var(--radius)] border border-emerald-300 bg-white px-3 text-xs text-slate-900 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500 cursor-pointer">
                        <option value="">-- Pilih Kelas Siswa --</option>
                        @foreach($classes as $c)
                            <option value="{{ $c->id }}">Kelas {{ $c->name }} ({{ $c->academic_year }})</option>
                        @endforeach
                    </select>
                    <p class="text-[11px] text-emerald-700">Wali kelas hanya dapat mencatat dan melihat presensi untuk siswa pada kelas ini.</p>
                </div>

                <div class="flex items-center gap-2 pt-1">
                    <input type="checkbox" id="add_is_active" name="is_active" value="1" checked
                        class="size-4 rounded border-[var(--border)] text-[var(--primary-accessible)] focus:ring-[var(--primary-accessible)] cursor-pointer">
                    <label for="add_is_active" class="text-xs text-[var(--text-secondary)] cursor-pointer">Akun Aktif (Dapat Login)</label>
                </div>

                <div class="flex justify-end gap-2 pt-3 border-t border-[var(--border-subtle)]">
                    <button type="button" @click="addModal = false" class="px-3.5 py-1.5 rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface)] hover:bg-[var(--surface-secondary)] text-xs font-semibold text-[var(--text-secondary)] transition-colors">
                        Batal
                    </button>
                    <button type="submit" class="px-3.5 py-1.5 rounded-[var(--radius-sm)] bg-[var(--primary)] text-xs font-semibold text-white hover:brightness-95 transition-colors">
                        Simpan Pengguna
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Edit Pengguna -->
    <div x-cloak x-show="editModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-xs">
        <div @click.away="editModal = false" class="max-w-md w-full rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] p-5 shadow-xl space-y-4">
            <div class="flex items-center justify-between border-b border-[var(--border-subtle)] pb-3">
                <h3 class="text-sm font-bold text-[var(--text)]">Edit Data Pengguna</h3>
                <button type="button" @click="editModal = false" class="text-[var(--text-muted)] hover:text-[var(--text)]">
                    <svg class="size-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>

            <form :action="'{{ url('users') }}/' + activeUser.id" method="POST" class="space-y-3.5 text-xs">
                @csrf
                @method('PUT')

                <div>
                    <label class="block font-medium text-[var(--text-secondary)] mb-1">Nama Lengkap *</label>
                    <input type="text" name="name" x-model="activeUser.name" required
                        class="w-full rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface-secondary)] px-3 py-1.5 text-xs text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none">
                </div>

                <div>
                    <label class="block font-medium text-[var(--text-secondary)] mb-1">Alamat Email *</label>
                    <input type="email" name="email" x-model="activeUser.email" required
                        class="w-full rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface-secondary)] px-3 py-1.5 text-xs text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none">
                </div>

                <div>
                    <label class="block font-medium text-[var(--text-secondary)] mb-1">Kata Sandi Baru (Opsional)</label>
                    <input type="password" name="password" minlength="8" placeholder="Kosongkan jika tidak ingin mengubah sandi"
                        class="w-full rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface-secondary)] px-3 py-1.5 text-xs text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none">
                </div>

                <div>
                    <label class="block font-medium text-[var(--text-secondary)] mb-1">Peran Hak Akses (Role) *</label>
                    <select name="role" x-model="activeUser.role" required
                        class="w-full rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface-secondary)] px-3 py-1.5 text-xs text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none cursor-pointer">
                        <option value="operator">Operator Absensi</option>
                        <option value="wali_kelas">Wali Kelas</option>
                        <option value="admin">Administrator</option>
                    </select>
                </div>

                <!-- Dropdown Kelas (Muncul jika role === 'wali_kelas') -->
                <div x-show="activeUser.role === 'wali_kelas'" x-transition class="p-2.5 rounded-[var(--radius-sm)] bg-emerald-50 border border-emerald-200 space-y-1">
                    <label class="block font-semibold text-emerald-900">Kelas yang Diampu *</label>
                    <select name="class_id" x-model="activeUser.class_id" :required="activeUser.role === 'wali_kelas'"
                        class="w-full rounded-[var(--radius-sm)] border border-emerald-300 bg-white px-2.5 py-1.5 text-xs text-slate-900 focus:outline-none cursor-pointer">
                        <option value="">-- Pilih Kelas Siswa --</option>
                        @foreach($classes as $c)
                            <option value="{{ $c->id }}">Kelas {{ $c->name }} ({{ $c->academic_year }})</option>
                        @endforeach
                    </select>
                    <p class="text-[10px] text-emerald-700">Wali kelas hanya dapat mencatat dan melihat presensi untuk siswa pada kelas ini.</p>
                </div>

                <div class="flex items-center gap-2 pt-1">
                    <input type="checkbox" id="edit_is_active" name="is_active" value="1" :checked="activeUser.is_active"
                        class="rounded border-[var(--border)] text-[var(--primary-accessible)] focus:ring-0 cursor-pointer">
                    <label for="edit_is_active" class="text-xs text-[var(--text)] cursor-pointer">Akun Aktif (Dapat Login)</label>
                </div>

                <div class="flex items-center justify-end gap-2 pt-3 border-t border-[var(--border-subtle)]">
                    <button type="button" @click="editModal = false" class="px-3 py-1.5 rounded-[var(--radius-sm)] border border-[var(--border)] text-xs text-[var(--text-secondary)] hover:bg-[var(--surface-secondary)]">
                        Batal
                    </button>
                    <button type="submit" class="px-4 py-1.5 rounded-[var(--radius-sm)] bg-[var(--primary)] text-xs font-semibold text-white hover:brightness-95 shadow-xs">
                        Perbarui Pengguna
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

