<?php

namespace App\Http\Controllers;

use App\Concerns\LogsAuditTrail;
use App\Models\AttendanceSetting;
use App\Models\SchedulePeriod;
use App\Models\SchoolHoliday;
use App\Models\SchoolSchedule;
use App\Services\AttendancePeriodService;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SchoolScheduleController extends Controller
{
    use LogsAuditTrail;

    /**
     * Tampilkan jadwal mingguan, jadwal khusus, dan kalender hari libur.
     */
    public function index(Request $request, AttendancePeriodService $periodService): View
    {
        $schedules = SchoolSchedule::whereNull('period_id')
            ->orderBy('day_of_week')
            ->get();
        $periods = SchedulePeriod::with([
            'schedules' => fn ($query) => $query->orderBy('day_of_week'),
        ])
            ->orderByDesc('start_date')
            ->get();
        $holidays = SchoolHoliday::orderBy('start_date', 'desc')->paginate($this->resolvePerPage(10))->withQueryString();
        $attendanceStartDate = $periodService->startDate();

        return view('schedules.index', compact('schedules', 'periods', 'holidays', 'attendanceStartDate'));
    }

    /**
     * Simpan tanggal mulai sistem absensi (global).
     */
    public function updateAttendanceStart(Request $request, AttendancePeriodService $periodService): RedirectResponse
    {
        $validated = $request->validate([
            'attendance_start_date' => ['nullable', 'date'],
        ], [
            'attendance_start_date.date' => 'Tanggal mulai sistem tidak valid.',
        ]);

        $setting = AttendanceSetting::current();
        $before = $setting->toArray();
        $setting->update([
            'attendance_start_date' => $validated['attendance_start_date'] ?? null,
        ]);
        $periodService->forget();

        $this->logAudit('update_attendance_start_date', 'AttendanceSetting', $setting->id, $before, $setting->toArray());

        $message = $setting->attendance_start_date
            ? 'Tanggal mulai sistem absensi disimpan: '.$setting->attendance_start_date->format('d/m/Y').'.'
            : 'Batas tanggal mulai sistem absensi dihapus. Seluruh data lama kembali ditampilkan.';

        return redirect()->route('schedules.index', ['tab' => 'mulai'])
            ->with('success', $message);
    }

    /**
     * Perbarui jadwal jam masuk dan pulang mingguan.
     */
    public function update(Request $request, SchoolSchedule $schedule): RedirectResponse
    {
        $validated = $request->validate([
            'check_in_start' => ['required', 'date_format:H:i,H:i:s'],
            'check_in_end' => ['nullable', 'date_format:H:i,H:i:s'],
            'start_at' => ['required', 'date_format:H:i,H:i:s'],
            'late_after' => ['nullable', 'date_format:H:i,H:i:s'],
            'late_tolerance_minutes' => ['nullable', 'integer', 'min:0', 'max:240'],
            'check_out_start' => ['required', 'date_format:H:i,H:i:s'],
            'check_out_end' => ['nullable', 'date_format:H:i,H:i:s'],
            'end_at' => ['required', 'date_format:H:i,H:i:s'],
            'early_leave_tolerance_minutes' => ['nullable', 'integer', 'min:0', 'max:240'],
            'alpa_cutoff' => ['nullable', 'date_format:H:i,H:i:s', function (string $attribute, mixed $value, Closure $fail) use ($request): void {
                $checkOutEnd = $request->input('check_out_end') ?: $request->input('end_at');

                if (is_string($value) && is_string($checkOutEnd) && $value !== '' && $checkOutEnd !== '' && strtotime($value) <= strtotime($checkOutEnd)) {
                    $fail('Batas waktu Alpa harus setelah batas akhir scan pulang.');
                }
            }],
            'day_pattern' => ['required', 'string', 'max:50'],
            'is_active' => ['boolean'],
        ]);

        $this->assertScheduleTimeOrder($validated, $schedule);

        $before = $schedule->toArray();
        $validated['is_active'] = $request->boolean('is_active');
        $schedule->update($validated);

        $this->logAudit('update_schedule', 'SchoolSchedule', $schedule->id, $before, $schedule->toArray());

        return redirect()->route('schedules.index', [
            'tab' => $schedule->period_id ? 'special' : 'schedules',
        ])->with('success', 'Jadwal hari '.$schedule->day_name.' berhasil diperbarui.');
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function assertScheduleTimeOrder(array $validated, SchoolSchedule $schedule): void
    {
        $checkInEnd = $validated['check_in_end'] ?? $schedule->check_in_end ?? $validated['check_out_start'];
        $checkOutEnd = $validated['check_out_end'] ?? $schedule->check_out_end ?? $validated['end_at'];

        $times = [
            'check_in_start' => strtotime((string) $validated['check_in_start']),
            'start_at' => strtotime((string) $validated['start_at']),
            'check_in_end' => strtotime((string) $checkInEnd),
            'check_out_start' => strtotime((string) $validated['check_out_start']),
            'end_at' => strtotime((string) $validated['end_at']),
            'check_out_end' => strtotime((string) $checkOutEnd),
        ];

        $isOrdered = $times['check_in_start'] <= $times['start_at']
            && $times['start_at'] <= $times['check_in_end']
            && $times['check_in_end'] <= $times['check_out_start']
            && $times['check_out_start'] <= $times['end_at']
            && $times['end_at'] <= $times['check_out_end'];

        if (! $isOrdered) {
            throw ValidationException::withMessages([
                'check_in_start' => 'Urutan jam harus: awal scan masuk <= jam masuk <= akhir scan masuk <= awal scan pulang <= jam pulang <= akhir scan pulang.',
            ]);
        }
    }

    /**
     * Tambahkan periode jadwal khusus (mis. Ramadan) dan salin jadwal reguler
     * sebagai titik awal. Scan & presensi lama tidak diubah.
     */
    public function storePeriod(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'notes' => ['nullable', 'string', 'max:500'],
            'is_active' => ['boolean'],
        ], [
            'name.required' => 'Nama periode wajib diisi.',
            'start_date.required' => 'Tanggal mulai wajib diisi.',
            'end_date.required' => 'Tanggal selesai wajib diisi.',
            'end_date.after_or_equal' => 'Tanggal selesai tidak boleh sebelum tanggal mulai.',
        ]);

        $this->assertNoActivePeriodOverlap($validated['start_date'], $validated['end_date']);

        $period = DB::transaction(function () use ($validated, $request): SchedulePeriod {
            $period = SchedulePeriod::create([
                'name' => $validated['name'],
                'start_date' => $validated['start_date'],
                'end_date' => $validated['end_date'],
                'notes' => $validated['notes'] ?? null,
                'is_active' => $request->boolean('is_active', true),
            ]);

            $this->cloneRegularSchedulesInto($period);

            return $period;
        });

        $this->logAudit('create_schedule_period', 'SchedulePeriod', $period->id, null, $period->toArray());

        return redirect()->route('schedules.index', ['tab' => 'special'])
            ->with('success', 'Periode jadwal khusus "'.$period->name.'" berhasil ditambahkan.');
    }

    /**
     * Perbarui identitas dan rentang tanggal periode jadwal khusus.
     */
    public function updatePeriod(Request $request, SchedulePeriod $period): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'notes' => ['nullable', 'string', 'max:500'],
            'is_active' => ['boolean'],
        ], [
            'end_date.after_or_equal' => 'Tanggal selesai tidak boleh sebelum tanggal mulai.',
        ]);

        $this->assertNoActivePeriodOverlap(
            $validated['start_date'],
            $validated['end_date'],
            $request->boolean('is_active'),
            $period->id,
        );

        $before = $period->toArray();
        $period->update([
            'name' => $validated['name'],
            'start_date' => $validated['start_date'],
            'end_date' => $validated['end_date'],
            'notes' => $validated['notes'] ?? null,
            'is_active' => $request->boolean('is_active'),
        ]);

        $this->logAudit('update_schedule_period', 'SchedulePeriod', $period->id, $before, $period->toArray());

        return redirect()->route('schedules.index', ['tab' => 'special'])
            ->with('success', 'Periode jadwal khusus "'.$period->name.'" berhasil diperbarui.');
    }

    /**
     * Hapus periode jadwal khusus beserta baris jadwalnya.
     */
    public function destroyPeriod(Request $request, SchedulePeriod $period): RedirectResponse
    {
        $before = $period->toArray();
        $name = $period->name;
        $period->delete();

        $this->logAudit('delete_schedule_period', 'SchedulePeriod', $period->id, $before, null);

        return redirect()->route('schedules.index', ['tab' => 'special'])
            ->with('success', 'Periode jadwal khusus "'.$name.'" berhasil dihapus. Scan baru akan kembali memakai jadwal reguler.');
    }

    private function assertNoActivePeriodOverlap(
        string $startDate,
        string $endDate,
        bool $isActive = true,
        ?int $ignorePeriodId = null,
    ): void {
        if (! $isActive) {
            return;
        }

        $overlaps = SchedulePeriod::query()
            ->where('is_active', true)
            ->whereDate('start_date', '<=', $endDate)
            ->whereDate('end_date', '>=', $startDate)
            ->when($ignorePeriodId !== null, fn ($query) => $query->whereKeyNot($ignorePeriodId))
            ->exists();

        if ($overlaps) {
            throw ValidationException::withMessages([
                'start_date' => 'Rentang tanggal bertumpuk dengan periode jadwal khusus lain yang masih aktif.',
            ]);
        }
    }

    private function cloneRegularSchedulesInto(SchedulePeriod $period): void
    {
        $regularSchedules = SchoolSchedule::whereNull('period_id')
            ->orderBy('day_of_week')
            ->get();

        if ($regularSchedules->isEmpty()) {
            $this->createDefaultSchedulesFor($period);

            return;
        }

        foreach ($regularSchedules as $regular) {
            SchoolSchedule::create([
                'period_id' => $period->id,
                'day_pattern' => $regular->day_pattern,
                'day_name' => $regular->day_name,
                'day_of_week' => $regular->day_of_week,
                'check_in_start' => $regular->check_in_start,
                'check_in_end' => $regular->check_in_end,
                'start_at' => $regular->start_at,
                'late_after' => $regular->late_after,
                'late_tolerance_minutes' => $regular->late_tolerance_minutes,
                'check_out_start' => $regular->check_out_start,
                'check_out_end' => $regular->check_out_end,
                'end_at' => $regular->end_at,
                'early_leave_tolerance_minutes' => $regular->early_leave_tolerance_minutes,
                'alpa_cutoff' => $regular->alpa_cutoff,
                'effective_date' => null,
                'is_active' => $regular->is_active,
            ]);
        }
    }

    private function createDefaultSchedulesFor(SchedulePeriod $period): void
    {
        $dayNames = [1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat', 6 => 'Sabtu', 7 => 'Minggu'];

        foreach ($dayNames as $dayOfWeek => $dayName) {
            SchoolSchedule::create([
                'period_id' => $period->id,
                'day_pattern' => 'regular',
                'day_name' => $dayName,
                'day_of_week' => $dayOfWeek,
                'check_in_start' => '06:00:00',
                'check_in_end' => '14:00:00',
                'start_at' => '07:00:00',
                'late_after' => '07:15:00',
                'late_tolerance_minutes' => 15,
                'check_out_start' => '14:00:00',
                'check_out_end' => '16:00:00',
                'end_at' => '16:00:00',
                'early_leave_tolerance_minutes' => 0,
                'alpa_cutoff' => null,
                'effective_date' => null,
                'is_active' => $dayOfWeek !== 7,
            ]);
        }
    }

    /**
     * Tambahkan hari libur khusus / kalender libur baru.
     */
    public function storeHoliday(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'description' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ]);

        $validated['is_active'] = $request->boolean('is_active', true);
        $holiday = SchoolHoliday::create($validated);

        $this->logAudit('create_holiday', 'SchoolHoliday', $holiday->id, null, $holiday->toArray());

        return redirect()->route('schedules.index', ['tab' => 'holidays'])
            ->with('success', 'Hari libur "'.$holiday->name.'" berhasil ditambahkan.');
    }

    /**
     * Perbarui hari libur khusus.
     */
    public function updateHoliday(Request $request, SchoolHoliday $holiday): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'description' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ]);

        $before = $holiday->toArray();
        $validated['is_active'] = $request->boolean('is_active');
        $holiday->update($validated);

        $this->logAudit('update_holiday', 'SchoolHoliday', $holiday->id, $before, $holiday->toArray());

        return redirect()->route('schedules.index', ['tab' => 'holidays'])
            ->with('success', 'Hari libur "'.$holiday->name.'" berhasil diperbarui.');
    }

    /**
     * Hapus hari libur khusus.
     */
    public function destroyHoliday(Request $request, SchoolHoliday $holiday): RedirectResponse
    {
        $before = $holiday->toArray();
        $name = $holiday->name;
        $holiday->delete();

        $this->logAudit('delete_holiday', 'SchoolHoliday', $holiday->id, $before, null);

        return redirect()->route('schedules.index', ['tab' => 'holidays'])
            ->with('success', 'Hari libur "'.$name.'" berhasil dihapus.');
    }
}
