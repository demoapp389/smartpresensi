<?php

namespace App\Http\Controllers;

use App\Concerns\LogsAuditTrail;
use App\Models\SchoolHoliday;
use App\Models\SchoolSchedule;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SchoolScheduleController extends Controller
{
    use LogsAuditTrail;

    /**
     * Tampilkan jadwal mingguan dan kalender hari libur khusus.
     */
    public function index(Request $request): View
    {
        $schedules = SchoolSchedule::orderBy('day_of_week')->get();
        $holidays = SchoolHoliday::orderBy('start_date', 'desc')->paginate($this->resolvePerPage(10))->withQueryString();

        return view('schedules.index', compact('schedules', 'holidays'));
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

        return redirect()->route('schedules.index')
            ->with('success', 'Jadwal hari '.$schedule->day_name.' berhasil diperbarui.');
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
