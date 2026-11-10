<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Pengaturan global periode absensi.
 *
 * Hanya ada satu baris (singleton). Kolom `attendance_start_date` bernilai
 * null berarti tidak ada batas tanggal (perilaku lama tetap berlaku).
 */
class AttendanceSetting extends Model
{
    protected $fillable = [
        'attendance_start_date',
    ];

    protected $casts = [
        'attendance_start_date' => 'date',
    ];

    public static function current(): self
    {
        return self::query()->orderBy('id')->first() ?? self::query()->create([]);
    }

    public static function startDate(): ?Carbon
    {
        return self::query()->orderBy('id')->first()?->attendance_start_date?->copy()->startOfDay();
    }
}
