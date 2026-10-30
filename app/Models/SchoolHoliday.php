<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SchoolHoliday extends Model
{
    use HasFactory;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'start_date',
        'end_date',
        'description',
        'is_active',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'is_active' => 'boolean',
    ];

    /**
     * Scope untuk hari libur yang sedang aktif.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope untuk hari libur yang akan datang (dari hari ini ke depan).
     */
    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where('end_date', '>=', now()->toDateString());
    }

    /**
     * Cek apakah tanggal tertentu merupakan hari libur aktif.
     */
    public static function isHoliday(string|Carbon $date): bool
    {
        $dateStr = is_string($date) ? $date : $date->toDateString();

        return static::where('is_active', true)
            ->whereDate('start_date', '<=', $dateStr)
            ->whereDate('end_date', '>=', $dateStr)
            ->exists();
    }

    /**
     * Ambil objek hari libur untuk tanggal tertentu.
     */
    public static function getHolidayForDate(string|Carbon $date): ?self
    {
        $dateStr = is_string($date) ? $date : $date->toDateString();

        return static::where('is_active', true)
            ->whereDate('start_date', '<=', $dateStr)
            ->whereDate('end_date', '>=', $dateStr)
            ->first();
    }
}
