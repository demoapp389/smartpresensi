@extends('layouts.app')

@section('title', 'Data Kelas')
@section('page-pretitle', 'Master Data')
@section('page-title', 'Master Data Kelas')
@section('page-subtitle', 'Kelola daftar kelas dan tahun ajaran aktif')

@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="transition-colors hover:text-[var(--text)]">Beranda</a>
    <span aria-hidden="true">/</span>
    <span>Master Data</span>
    <span aria-hidden="true">/</span>
    <span class="font-medium text-[var(--text)]" aria-current="page">Data Kelas</span>
@endsection

@section('content')
<div class="space-y-4" x-data="{ addModal: false, editModal: false, activeClass: {} }" @open-add-class.window="addModal = true">
    <section class="min-w-0 rounded-[var(--radius-lg)] border border-[var(--border)] bg-[var(--surface)] shadow-[var(--shadow)] overflow-hidden">

        {{-- Card header (.card-header Gentelella): judul + aksi, di atas baris pencarian --}}
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--border)] px-4 py-3">
            <div>
                <div class="card-title text-sm font-semibold text-[var(--text)]">Daftar Kelas Terdaftar</div>
                <div class="card-subtitle mt-0.5 text-xs text-[var(--text-muted)]">{{ $classes->count() }} kelas terdaftar</div>
            </div>

            <div class="card-options flex flex-wrap items-center gap-2">
                <button type="button" @click="addModal = true"
                    class="inline-flex h-8.5 items-center justify-center gap-1.5 rounded-[var(--radius-sm)] bg-[var(--primary)] px-3 text-xs font-semibold text-white transition-colors hover:brightness-90 cursor-pointer">
                    <svg class="size-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    <span>Tambah Kelas</span>
                </button>
            </div>
        </div>

        {{-- .table-responsive: hanya baris tabel yang scroll horizontal, sedangkan
             baris pencarian dan pagination tetap menempel di lebar card
             (lihat .dt-layout-row.dt-layout-table di resources/css/datatables.css) --}}
        <div>
            <table class="w-full text-left" data-datatable data-page-length="10" data-length-change="true" data-empty-message="Belum ada data kelas yang terdaftar.">
                <thead class="bg-[var(--surface-secondary)]">
                    <tr>
                        <th style="width:48px" class="px-4 py-2 text-[11px] font-bold tracking-wider text-[var(--text-muted)] uppercase text-center">No</th>
                        <th class="px-4 py-2 text-[11px] font-bold tracking-wider text-[var(--text-muted)] uppercase">Nama Kelas</th>
                        <th class="px-4 py-2 text-[11px] font-bold tracking-wider text-[var(--text-muted)] uppercase">Wali Kelas</th>
                        <th class="px-4 py-2 text-[11px] font-bold tracking-wider text-[var(--text-muted)] uppercase">Tahun Ajaran</th>
                        <th class="px-4 py-2 text-right text-[11px] font-bold tracking-wider text-[var(--text-muted)] uppercase">Jumlah Siswa</th>
                        <th style="width:140px" class="px-4 py-2 text-right text-[11px] font-bold tracking-wider text-[var(--text-muted)] uppercase" data-orderable="false">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--border-subtle)] text-[13px]">
                    @foreach($classes as $c)
                    <tr class="transition-colors hover:bg-[var(--surface-secondary)]">
                        <td class="px-4 py-2.5 text-center font-mono text-xs text-[var(--text-muted)]">
                            {{ $loop->iteration }}
                        </td>
                        <td class="px-4 py-2.5">
                            <div class="font-medium text-[var(--text)]">{{ $c->name }}</div>
                        </td>
                        <td class="px-4 py-2.5">
                            @if($c->homeroomTeacher)
                                <div class="flex items-center gap-2">
                                    <span class="flex size-6 shrink-0 items-center justify-center rounded-full bg-[var(--primary-soft)] text-[10px] font-bold text-[var(--primary-accessible)]">
                                        {{ strtoupper(substr($c->homeroomTeacher->name, 0, 2)) }}
                                    </span>
                                    <div class="min-w-0">
                                        <div class="font-medium text-[var(--text)] text-xs truncate">{{ $c->homeroomTeacher->name }}</div>
                                        <div class="text-[10px] text-[var(--text-muted)] truncate">{{ $c->homeroomTeacher->email }}</div>
                                    </div>
                                </div>
                            @else
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-semibold bg-amber-50 text-amber-700 border border-amber-200">
                                    Belum Ditugaskan
                                </span>
                            @endif
                        </td>
                        <td class="px-4 py-2.5 font-mono text-xs whitespace-nowrap text-[var(--text-secondary)]">
                            {{ $c->academic_year }}
                        </td>
                        <td class="px-4 py-2.5 text-right">
                            <a href="{{ route('students.index', ['class_id' => $c->id]) }}"
                                class="font-medium text-[var(--primary-accessible)] hover:underline">
                                {{ $c->students_count }} Siswa
                            </a>
                        </td>
                        <td class="px-4 py-2.5">
                            <div class="flex items-center justify-end gap-1.5">
                                <button type="button" @click="activeClass = {{ $c->toJson() }}; editModal = true"
                                    class="inline-flex items-center px-2.5 py-1 rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface)] hover:bg-[var(--surface-secondary)] text-xs font-semibold text-[var(--text)] transition-colors shadow-2xs cursor-pointer">
                                    Edit
                                </button>
                                <form action="{{ route('classes.destroy', $c) }}" method="POST" class="inline"
                                    data-confirm="Yakin ingin menghapus kelas {{ addslashes($c->name) }}?"
                                    data-confirm-title="Hapus Kelas"
                                    data-confirm-label="Hapus"
                                    data-confirm-variant="danger">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="inline-flex items-center px-2.5 py-1 rounded-[var(--radius-sm)] border border-rose-200 bg-rose-50 hover:bg-rose-100 text-xs font-semibold text-rose-700 transition-colors shadow-2xs cursor-pointer">
                                        Hapus
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <!-- Modal Tambah Kelas -->
    <div x-cloak x-show="addModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-[#0f1623]/60 backdrop-blur-xs">
        <div @click.away="addModal = false" class="bg-[var(--surface)] rounded-[var(--radius-lg)] border border-[var(--border)] shadow-xl w-full max-w-md p-5">
            <div class="flex items-center justify-between pb-3 border-b border-[var(--border-subtle)] mb-4">
                <div>
                    <h3 class="text-sm font-bold text-[var(--text)]">Tambah Kelas Baru</h3>
                    <p class="text-[11px] text-[var(--text-secondary)] mt-0.5">Tambahkan kelas baru ke dalam sistem absensi</p>
                </div>
                <button type="button" @click="addModal = false" class="text-[var(--text-muted)] hover:text-[var(--text)] p-1 rounded transition-colors" aria-label="Tutup">
                    <svg class="size-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>

            <form action="{{ route('classes.store') }}" method="POST" class="space-y-3.5 text-xs">
                @csrf
                <div>
                    <label class="block font-semibold text-[var(--text)] mb-1">Nama Kelas</label>
                    <input type="text" name="name" required placeholder="Contoh: X MIPA 1"
                        class="h-9 w-full rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] px-3 text-xs text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none focus:ring-1 focus:ring-[var(--primary-accessible)]">
                </div>
                <div>
                    <label class="block font-semibold text-[var(--text)] mb-1">Tahun Ajaran</label>
                    <input type="text" name="academic_year" required value="2026/2027" placeholder="Contoh: 2026/2027"
                        class="h-9 w-full rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] px-3 text-xs text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none focus:ring-1 focus:ring-[var(--primary-accessible)]">
                </div>
                <div class="flex justify-end gap-2 pt-3 border-t border-[var(--border-subtle)]">
                    <button type="button" @click="addModal = false" class="px-3.5 py-1.5 rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface)] hover:bg-[var(--surface-secondary)] text-xs font-semibold text-[var(--text-secondary)] transition-colors cursor-pointer">
                        Batal
                    </button>
                    <button type="submit" class="px-3.5 py-1.5 rounded-[var(--radius-sm)] bg-[var(--primary)] text-xs font-semibold text-white hover:brightness-95 transition-colors cursor-pointer">
                        Simpan Kelas
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Edit Kelas -->
    <div x-cloak x-show="editModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-[#0f1623]/60 backdrop-blur-xs">
        <div @click.away="editModal = false" class="bg-[var(--surface)] rounded-[var(--radius-lg)] border border-[var(--border)] shadow-xl w-full max-w-md p-5">
            <div class="flex items-center justify-between pb-3 border-b border-[var(--border-subtle)] mb-4">
                <div>
                    <h3 class="text-sm font-bold text-[var(--text)]">Edit Informasi Kelas</h3>
                    <p class="text-[11px] text-[var(--text-secondary)] mt-0.5">Perbarui informasi nama kelas atau tahun ajaran</p>
                </div>
                <button type="button" @click="editModal = false" class="text-[var(--text-muted)] hover:text-[var(--text)] p-1 rounded transition-colors" aria-label="Tutup">
                    <svg class="size-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>

            <form :action="'{{ url('classes') }}/' + activeClass.id" method="POST" class="space-y-3.5 text-xs">
                @csrf
                @method('PUT')
                <div>
                    <label class="block font-semibold text-[var(--text)] mb-1">Nama Kelas</label>
                    <input type="text" name="name" x-model="activeClass.name" required
                        class="h-9 w-full rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] px-3 text-xs text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none focus:ring-1 focus:ring-[var(--primary-accessible)]">
                </div>
                <div>
                    <label class="block font-semibold text-[var(--text)] mb-1">Tahun Ajaran</label>
                    <input type="text" name="academic_year" x-model="activeClass.academic_year" required
                        class="h-9 w-full rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] px-3 text-xs text-[var(--text)] focus:border-[var(--primary-accessible)] focus:outline-none focus:ring-1 focus:ring-[var(--primary-accessible)]">
                </div>
                <div class="flex justify-end gap-2 pt-3 border-t border-[var(--border-subtle)]">
                    <button type="button" @click="editModal = false" class="px-3.5 py-1.5 rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface)] hover:bg-[var(--surface-secondary)] text-xs font-semibold text-[var(--text-secondary)] transition-colors cursor-pointer">
                        Batal
                    </button>
                    <button type="submit" class="px-3.5 py-1.5 rounded-[var(--radius-sm)] bg-[var(--primary)] text-xs font-semibold text-white hover:brightness-95 transition-colors cursor-pointer">
                        Perbarui Kelas
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

