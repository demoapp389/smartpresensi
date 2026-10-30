@extends('layouts.app')

@section('title', $guideTitle)
@section('page-pretitle', 'Administrasi Sistem')
@section('page-title', $guideTitle)
@section('page-subtitle', $guideSubtitle)

@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="transition-colors hover:text-[var(--text)]">Beranda</a>
    <span aria-hidden="true">/</span>
    <a href="{{ route('documentation.index') }}" class="transition-colors hover:text-[var(--text)]">Dokumentasi</a>
    <span aria-hidden="true">/</span>
    <span class="font-medium text-[var(--text)]" aria-current="page">{{ $guideTitle }}</span>
@endsection

@section('content')
<div id="deploy-root" class="space-y-4">
    {{-- Bilah alat --}}
    <div class="flex flex-wrap items-center justify-between gap-3 rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] px-4 py-3 shadow-[var(--shadow)]">
        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('documentation.index') }}"
               class="inline-flex items-center gap-1.5 rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface)] px-3 py-1.5 text-xs font-medium text-[var(--text)] transition-colors hover:bg-[var(--surface-secondary)]">
                <x-icon name="chevron" class="size-3.5 rotate-180" />
                Kembali ke Dokumentasi
            </a>
            <nav aria-label="Pilih panduan deployment"
                 class="flex flex-wrap items-center gap-1 rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface-secondary)] p-1">
                @foreach($guides as $guide)
                    <a href="{{ $guide['route'] }}"
                       @if($guide['key'] === $activePlatform) aria-current="page" @endif
                       class="rounded-[var(--radius-sm)] px-2.5 py-1 text-xs font-medium transition-colors {{ $guide['key'] === $activePlatform ? 'bg-[var(--primary)] text-white' : 'text-[var(--text-secondary)] hover:bg-[var(--surface)] hover:text-[var(--text)]' }}">
                        {{ $guide['title'] }}
                    </a>
                @endforeach
            </nav>
        </div>
        <div class="flex items-center gap-3">
            @if($updatedAt)
                <span class="hidden text-xs text-[var(--text-muted)] sm:inline">Diperbarui {{ $updatedAt }}</span>
            @endif
            @if($html)
                <button type="button" onclick="window.print()"
                        class="inline-flex items-center gap-1.5 rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface)] px-3 py-1.5 text-xs font-medium text-[var(--text)] transition-colors hover:bg-[var(--surface-secondary)]">
                    <x-icon name="download" class="size-3.5" />
                    Cetak / Simpan PDF
                </button>
            @endif
        </div>
    </div>

    @if($html === null)
        <div class="rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] px-6 py-12 text-center shadow-[var(--shadow)]">
            <span class="mx-auto flex size-11 items-center justify-center rounded-xl bg-[var(--surface-secondary)] text-[var(--text-muted)]">
                <x-icon name="document" class="size-6" />
            </span>
            <p class="mt-3 text-sm font-semibold text-[var(--text)]">Berkas panduan tidak ditemukan</p>
            <p class="mx-auto mt-1 max-w-md text-xs leading-relaxed text-[var(--text-secondary)]">
                Berkas <code class="rounded bg-[var(--surface-secondary)] px-1 py-0.5 font-mono text-[11px] text-[var(--text)]">{{ $fileName }}</code>
                belum tersedia pada instalasi ini. Panduan ini disertakan pada paket rilis standar.
            </p>
        </div>
    @else
        <div class="grid grid-cols-1 gap-4 xl:grid-cols-[minmax(0,2.4fr)_minmax(0,1fr)]">
            <article class="doc-prose min-w-0 rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] p-5 shadow-[var(--shadow)] sm:p-7
                max-w-none text-sm leading-relaxed text-[var(--text-secondary)]
                [&_h1]:mb-3 [&_h1]:text-xl [&_h1]:font-bold [&_h1]:text-[var(--text)]
                [&_h2]:mt-7 [&_h2]:mb-3 [&_h2]:scroll-mt-24 [&_h2]:border-b [&_h2]:border-[var(--border-subtle)] [&_h2]:pb-1.5 [&_h2]:text-base [&_h2]:font-bold [&_h2]:text-[var(--text)]
                [&_h3]:mt-5 [&_h3]:mb-2 [&_h3]:text-sm [&_h3]:font-semibold [&_h3]:text-[var(--text)]
                [&_p]:mt-2
                [&_a]:font-medium [&_a]:text-[var(--primary-accessible)] [&_a]:underline-offset-2 hover:[&_a]:underline
                [&_strong]:font-semibold [&_strong]:text-[var(--text)]
                [&_ul]:mt-2 [&_ul]:list-disc [&_ul]:space-y-1 [&_ul]:pl-5
                [&_ol]:mt-2 [&_ol]:list-decimal [&_ol]:space-y-1 [&_ol]:pl-5
                [&_li]:marker:text-[var(--text-muted)]
                [&_code]:rounded [&_code]:bg-[var(--surface-secondary)] [&_code]:px-1 [&_code]:py-0.5 [&_code]:font-mono [&_code]:text-[12px] [&_code]:text-[var(--text)]
                [&_pre]:mt-3 [&_pre]:overflow-x-auto [&_pre]:rounded-[var(--radius-sm)] [&_pre]:border [&_pre]:border-[var(--border)] [&_pre]:bg-slate-900 [&_pre]:p-3 [&_pre]:text-[12px] [&_pre]:leading-relaxed
                [&_pre_code]:bg-transparent [&_pre_code]:p-0 [&_pre_code]:text-slate-100
                [&_blockquote]:mt-3 [&_blockquote]:rounded-r-[var(--radius-sm)] [&_blockquote]:border-l-4 [&_blockquote]:border-[var(--primary)] [&_blockquote]:bg-[var(--primary-soft)] [&_blockquote]:px-4 [&_blockquote]:py-2.5 [&_blockquote]:text-[var(--text)]
                [&_blockquote_p]:m-0
                [&_hr]:my-6 [&_hr]:border-[var(--border-subtle)]
                [&_.doc-table-wrap]:mt-3 [&_.doc-table-wrap]:overflow-x-auto
                [&_table]:w-full [&_table]:border-collapse [&_table]:text-left
                [&_th]:border [&_th]:border-[var(--border-subtle)] [&_th]:bg-[var(--surface-secondary)] [&_th]:px-3 [&_th]:py-1.5 [&_th]:text-xs [&_th]:font-semibold [&_th]:text-[var(--text)]
                [&_td]:border [&_td]:border-[var(--border-subtle)] [&_td]:px-3 [&_td]:py-1.5 [&_td]:align-top">
                {!! $html !!}
            </article>

            <aside id="doc-toc-wrap" class="hidden xl:block">
                <nav aria-label="Daftar isi" class="sticky top-4 rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] p-3 shadow-[var(--shadow)]">
                    <p class="px-2 pb-1.5 text-xs font-bold uppercase tracking-wide text-[var(--text-muted)]">Daftar Isi</p>
                    <div id="doc-toc" class="flex flex-col"></div>
                </nav>
            </aside>
        </div>
    @endif
</div>

<script>
    (function () {
        const article = document.getElementById('doc-prose') || document.querySelector('.doc-prose');
        const toc = document.getElementById('doc-toc');
        const wrap = document.getElementById('doc-toc-wrap');
        if (!article || !toc) {
            return;
        }

        const headings = Array.from(article.querySelectorAll('h2'));
        if (headings.length === 0) {
            if (wrap) {
                wrap.style.display = 'none';
            }
            return;
        }

        headings.forEach((heading, index) => {
            const id = 'sec-' + (index + 1);
            heading.id = id;

            const link = document.createElement('a');
            link.href = '#' + id;
            link.textContent = heading.textContent;
            link.className = 'block truncate rounded-[var(--radius-sm)] px-2.5 py-1.5 text-xs text-[var(--text-secondary)] transition-colors hover:bg-[var(--surface-secondary)] hover:text-[var(--text)]';
            toc.appendChild(link);
        });
    })();
</script>
@endsection
