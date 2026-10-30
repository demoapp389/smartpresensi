<?php

namespace App\Http\Controllers;

abstract class Controller
{
    /**
     * Pilihan jumlah data per halaman yang boleh dipilih pengguna.
     *
     * @var list<int>
     */
    public const PER_PAGE_OPTIONS = [10, 15, 20, 25, 30, 50, 100];

    /**
     * Tentukan jumlah data per halaman dari query string `per_page`.
     *
     * Nilai di luar daftar {@see PER_PAGE_OPTIONS} diabaikan dan fallback ke
     * default halaman terkait, supaya query string tidak bisa membuat tabel
     * dimuat dengan jumlah baris sembarang.
     */
    protected function resolvePerPage(int $default): int
    {
        $requested = (int) request()->query('per_page', $default);

        return in_array($requested, self::PER_PAGE_OPTIONS, true) ? $requested : $default;
    }
}
