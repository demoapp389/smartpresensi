<?php

namespace App\Models;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SchoolSchedule extends Model
{
    use HasFactory;

    protected $fillable = [
        'day_pattern',
        'day_name',
        'day_of_week',
        'check_in_start',
        'check_in_end',
        'start_at',
        'late_after',
        'late_tolerance_minutes',
        'check_out_start',
        'check_out_end',
        'end_at',
        'early_leave_tolerance_minutes',
        'alpa_cutoff',
        'effective_date',
        'is_active',
    ];

    protected $casts = [
        'day_of_week' => 'integer',
        'late_tolerance_minutes' => 'integer',
        'early_leave_tolerance_minutes' => 'integer',
        'effective_date' => 'date',
        'is_active' => 'boolean',
    ];

    /**
     * Ambil daftar nomor hari kerja aktif (ISO: 1 = Senin ... 7 = Minggu).
     *
     * @return array<int>
     */
    public static function getActiveDayNumbers(): array
    {
        return static::where('is_active', true)
            ->pluck('day_of_week')
            ->map(fn ($d) => (int) $d)
            ->all();
    }

    /**
     * Cek apakah suatu tanggal / hari adalah hari aktif sekolah.
     */
    public static function isSchoolDay(CarbonInterface|string|int $day): bool
    {
        if ($day instanceof CarbonInterface) {
            $dayOfWeek = (int) $day->dayOfWeekIso;
        } elseif (is_numeric($day)) {
            $dayOfWeek = (int) $day;
        } else {
            $dayOfWeek = (int) Carbon::parse($day, 'Asia/Jakarta')->dayOfWeekIso;
        }

        $activeDays = static::getActiveDayNumbers();

        // Jika belum ada jadwal yang dikonfigurasi sama sekali di database, fallback Senin-Sabtu (1-6)
        if (empty($activeDays)) {
            return in_array($dayOfWeek, [1, 2, 3, 4, 5, 6], true);
        }

        return in_array($dayOfWeek, $activeDays, true);
    }

    /**
     * Cek apakah suatu tanggal / hari merupakan libur mingguan / non-aktif.
     */
    public static function isWeekendOrOffDay(CarbonInterface|string|int $day): bool
    {
        return ! static::isSchoolDay($day);
    }

    /**
     * Akhir jendela scan masuk. Data lama yang belum memiliki kolom ini
     * memakai awal jendela pulang agar perilakunya tidak berubah.
     */
    public function effectiveCheckInEnd(): ?string
    {
        return $this->check_in_end ?? $this->check_out_start;
    }

    /**
     * Akhir jendela scan pulang. Data lama yang belum memiliki kolom ini
     * memakai jam pulang resmi.
     */
    public function effectiveCheckOutEnd(): ?string
    {
        return $this->check_out_end ?? $this->end_at;
    }

    /**
     * Toleransi keterlambatan dalam menit. Bila kolom baru masih kosong,
     * turunkan dari selisih late_after - start_at (perilaku lama).
     */
    public function lateToleranceMinutes(): int
    {
        if ($this->late_tolerance_minutes !== null) {
            return max(0, (int) $this->late_tolerance_minutes);
        }

        if (! $this->start_at || ! $this->late_after) {
            return 0;
        }

        $startAt = Carbon::parse('1970-01-01 '.$this->start_at);
        $lateAfter = Carbon::parse('1970-01-01 '.$this->late_after);

        return max(0, (int) floor(abs($lateAfter->diffInMinutes($startAt))));
    }

    /**
     * Toleransi pulang cepat dalam menit.
     */
    public function earlyLeaveToleranceMinutes(): int
    {
        return max(0, (int) ($this->early_leave_tolerance_minutes ?? 0));
    }

    /**
     * Batas waktu scan masuk sebelum siswa dianggap Alpa.
     */
    public function alpaCutoffForDate(CarbonInterface $date): Carbon
    {
        $date = Carbon::parse($date->toDateString(), $date->getTimezone());

        if ($this->alpa_cutoff) {
            return $date->copy()->setTimeFromTimeString($this->alpa_cutoff);
        }

        $bufferMinutes = max(1, (int) config('attendance.alpa_buffer_minutes', 5));

        return $date->copy()
            ->setTimeFromTimeString($this->effectiveCheckOutEnd() ?: $this->end_at)
            ->addMinutes($bufferMinutes);
    }
}
