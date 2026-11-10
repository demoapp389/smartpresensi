<?php

namespace App\Services;

use App\Models\AttendanceSetting;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * Sumber tunggal batas tanggal mulai sistem absensi.
 *
 * Semua modul yang menampilkan atau membuat data presensi wajib memakai
 * service ini agar data sebelum tanggal mulai tidak pernah muncul maupun
 * menghasilkan catatan otomatis (Alpa/perlu tinjauan).
 */
class AttendancePeriodService
{
    private bool $loaded = false;

    private ?Carbon $startDate = null;

    public function startDate(): ?Carbon
    {
        if (! $this->loaded) {
            $this->startDate = AttendanceSetting::startDate();
            $this->loaded = true;
        }

        return $this->startDate?->copy();
    }

    public function isConfigured(): bool
    {
        return $this->startDate() !== null;
    }

    public function isBeforeStart(Carbon|string $date): bool
    {
        $start = $this->startDate();

        if ($start === null) {
            return false;
        }

        $value = $date instanceof Carbon
            ? $date->copy()->setTimezone('Asia/Jakarta')
            : Carbon::parse($date, 'Asia/Jakarta');

        return $value->startOfDay()->lt($start);
    }

    public function clamp(Carbon $date): Carbon
    {
        $start = $this->startDate();

        if ($start !== null && $date->copy()->startOfDay()->lt($start)) {
            return $start->copy();
        }

        return $date;
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function applyTo(Builder $query, string $column = 'school_date'): Builder
    {
        $start = $this->startDate();

        return $start === null
            ? $query
            : $query->whereDate($column, '>=', $start->toDateString());
    }

    public function assertNotBeforeStart(string $date, string $field = 'school_date'): void
    {
        if (! $this->isBeforeStart($date)) {
            return;
        }

        throw ValidationException::withMessages([
            $field => 'Tanggal berada sebelum tanggal mulai sistem absensi ('
                .$this->startDate()?->format('d/m/Y').').',
        ]);
    }

    /**
     * Bersihkan cache setelah pengaturan diubah pada request yang sama.
     */
    public function forget(): void
    {
        $this->loaded = false;
        $this->startDate = null;
    }
}
