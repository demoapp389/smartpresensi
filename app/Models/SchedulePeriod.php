<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

/**
 * Periode jadwal khusus (mis. Ramadan) yang menimpa jadwal mingguan hanya
 * pada rentang tanggal tertentu. Presensi yang sudah tercatat tidak dihitung
 * ulang saat periode dibuat atau diubah.
 */
class SchedulePeriod extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'start_date',
        'end_date',
        'notes',
        'is_active',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        $flushResolvedScheduleCache = fn () => Cache::increment('school-schedule:version');

        static::saved($flushResolvedScheduleCache);
        static::deleted($flushResolvedScheduleCache);
    }

    /**
     * @return HasMany<SchoolSchedule, $this>
     */
    public function schedules(): HasMany
    {
        return $this->hasMany(SchoolSchedule::class, 'period_id');
    }

    public function coversDate(CarbonInterface $date): bool
    {
        $dateString = $date->toDateString();

        return $this->start_date->toDateString() <= $dateString
            && $this->end_date->toDateString() >= $dateString;
    }
}
