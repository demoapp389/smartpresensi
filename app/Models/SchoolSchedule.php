<?php

namespace App\Models;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class SchoolSchedule extends Model
{
    use HasFactory;

    protected $fillable = [
        'day_pattern',
        'day_name',
        'day_of_week',
        'period_id',
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
        'period_id' => 'integer',
        'late_tolerance_minutes' => 'integer',
        'early_leave_tolerance_minutes' => 'integer',
        'effective_date' => 'date',
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        $flushResolvedScheduleCache = fn () => Cache::increment('school-schedule:version');

        static::saved($flushResolvedScheduleCache);
        static::deleted($flushResolvedScheduleCache);
    }

    /**
     * @return BelongsTo<SchedulePeriod, $this>
     */
    public function period(): BelongsTo
    {
        return $this->belongsTo(SchedulePeriod::class, 'period_id');
    }

    /**
     * Ambil daftar nomor hari kerja aktif (ISO: 1 = Senin ... 7 = Minggu)
     * dari jadwal reguler saja. Jadwal khusus dinilai per tanggal.
     *
     * @return array<int>
     */
    public static function getActiveDayNumbers(): array
    {
        return static::whereNull('period_id')
            ->where('is_active', true)
            ->pluck('day_of_week')
            ->map(fn ($d) => (int) $d)
            ->all();
    }

    /**
     * Jadwal yang berlaku pada tanggal tertentu. Jadwal khusus pada periode
     * yang mencakup tanggal tersebut menimpa jadwal reguler.
     *
     * Cache hanya menyimpan ID jadwal (skalar), bukan objek model: karena
     * `cache.serializable_classes` bernilai false, objek yang disimpan ke
     * cache akan kembali sebagai __PHP_Incomplete_Class di store yang
     * men-serialize nilai (database/file/redis).
     */
    public static function resolveForDate(CarbonInterface $date): ?self
    {
        $dateKey = $date->toDateString();
        $version = (int) Cache::get('school-schedule:version', 0);

        // 0 dipakai sebagai penanda "tidak ada jadwal" agar null tidak
        // dianggap cache miss dan query diulang terus-menerus.
        $scheduleId = (int) Cache::remember(
            "school-schedule:resolved-id:{$version}:{$dateKey}",
            now()->addMinute(),
            fn (): int => (int) (static::resolveFrom(
                $date,
                static::query()->get(),
                SchedulePeriod::query()->where('is_active', true)->get(),
            )?->id ?? 0),
        );

        return $scheduleId > 0 ? static::find($scheduleId) : null;
    }

    /**
     * Versi resolver yang menerima koleksi yang sudah dimuat, untuk menghindari
     * query berulang saat menelusuri rentang tanggal (laporan, alpa, dll).
     *
     * @param  Collection<int, self>  $schedules
     * @param  Collection<int, SchedulePeriod>  $periods
     */
    public static function resolveFrom(
        CarbonInterface $date,
        Collection $schedules,
        Collection $periods,
    ): ?self {
        $dateString = $date->toDateString();
        $dayOfWeek = (int) $date->dayOfWeekIso;

        $period = $periods->first(
            fn (SchedulePeriod $candidate): bool => $candidate->is_active && $candidate->coversDate($date)
        );

        if ($period !== null) {
            return $schedules->first(
                fn (self $schedule): bool => (int) $schedule->period_id === (int) $period->id
                    && (int) $schedule->day_of_week === $dayOfWeek
            );
        }

        return $schedules->first(
            fn (self $schedule): bool => $schedule->period_id === null
                && (int) $schedule->day_of_week === $dayOfWeek
        );
    }

    /**
     * Jadwal aktif yang berlaku pada tanggal tertentu.
     */
    public static function forDate(CarbonInterface $date): ?self
    {
        $schedule = static::resolveForDate($date);

        return $schedule?->is_active ? $schedule : null;
    }

    /**
     * Apakah ada periode khusus (aktif) yang mencakup tanggal ini.
     */
    public static function hasPeriodCovering(CarbonInterface $date): bool
    {
        return SchedulePeriod::query()
            ->where('is_active', true)
            ->whereDate('start_date', '<=', $date->toDateString())
            ->whereDate('end_date', '>=', $date->toDateString())
            ->exists();
    }

    /**
     * Cek apakah suatu tanggal / hari adalah hari aktif sekolah.
     */
    public static function isSchoolDay(CarbonInterface|string|int $day): bool
    {
        if (is_numeric($day)) {
            $dayOfWeek = (int) $day;
            $activeDays = static::getActiveDayNumbers();

            if (empty($activeDays)) {
                return in_array($dayOfWeek, [1, 2, 3, 4, 5, 6], true);
            }

            return in_array($dayOfWeek, $activeDays, true);
        }

        $date = $day instanceof CarbonInterface
            ? $day
            : Carbon::parse($day, 'Asia/Jakarta');

        // Muat jadwal & periode aktif sekali, lalu resolusi di memori. Versi
        // lama memanggil resolveForDate(), hasPeriodCovering(), dan
        // getActiveDayNumbers() yang masing-masing menembak query terpisah.
        $schedules = static::query()->get();
        $periods = SchedulePeriod::query()->where('is_active', true)->get();

        $schedule = static::resolveFrom($date, $schedules, $periods);

        if ($schedule !== null) {
            return (bool) $schedule->is_active;
        }

        // Periode khusus yang tidak mendefinisikan hari ini dianggap libur,
        // bukan kembali ke jadwal reguler.
        if ($periods->contains(fn (SchedulePeriod $period): bool => $period->coversDate($date))) {
            return false;
        }

        $activeDays = $schedules
            ->whereNull('period_id')
            ->where('is_active', true)
            ->pluck('day_of_week')
            ->map(fn ($value): int => (int) $value)
            ->all();

        // Jika belum ada jadwal yang dikonfigurasi sama sekali di database, fallback Senin-Sabtu (1-6)
        if (empty($activeDays)) {
            return in_array($date->dayOfWeekIso, [1, 2, 3, 4, 5, 6], true);
        }

        return in_array($date->dayOfWeekIso, $activeDays, true);
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
