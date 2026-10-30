<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\SchoolClass;
use App\Models\SchoolHoliday;
use App\Models\SchoolSchedule;
use App\Models\Student;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class AttendanceReportService
{
    public function __construct(
        protected AttendanceAlpaService $alpaService
    ) {}

    /**
     * Menghitung total hari efektif dalam rentang tanggal inklusif (mengabaikan hari Minggu dan hari libur nasional/sekolah).
     *
     * @return array{effective_days: int, holidays: Collection}
     */
    public function getEffectiveDaysInMonth(Carbon $startDate, Carbon $endDate): array
    {
        $holidays = SchoolHoliday::active()
            ->whereDate('start_date', '<=', $endDate->toDateString())
            ->whereDate('end_date', '>=', $startDate->toDateString())
            ->get();

        $activeDays = SchoolSchedule::getActiveDayNumbers();
        $hasScheduleConfig = ! empty($activeDays);

        $effectiveDays = 0;
        $rangeStart = $startDate->copy()->startOfDay();
        $totalDays = (int) $rangeStart->diffInDays($endDate->copy()->startOfDay()) + 1;

        for ($day = 1; $day <= $totalDays; $day++) {
            $cur = $rangeStart->copy()->addDays($day - 1);
            $dStr = $cur->toDateString();
            $isOffDay = $hasScheduleConfig ? ! in_array((int) $cur->dayOfWeekIso, $activeDays, true) : ($cur->dayOfWeekIso === 7);
            $isHoliday = $holidays->contains(fn ($h) => $dStr >= $h->start_date->toDateString() && $dStr <= $h->end_date->toDateString());

            if (! $isOffDay && ! $isHoliday) {
                $effectiveDays++;
            }
        }

        return [
            'effective_days' => $effectiveDays,
            'holidays' => $holidays,
        ];
    }

    /**
     * @return array{hadir: int, terlambat: int, pulang_cepat: int, izin: int, sakit: int, alpa: int, perlu_tinjauan: int, total_hadir: int}
     */
    private function summarizeAttendances(Collection $attendances): array
    {
        $hadir = $attendances->where('status', 'hadir')->count();
        $terlambat = $attendances->where('status', 'terlambat')->count();
        // Status pulang_cepat lama tetap dihitung Hadir untuk kompatibilitas total_hadir.
        $legacyPulangCepat = $attendances->where('status', 'pulang_cepat')->count();
        $pulangCepat = $attendances->filter(
            fn (Attendance $attendance): bool => $attendance->status === 'pulang_cepat'
                || $attendance->check_out_status === 'pulang_cepat'
        )->count();

        return [
            'hadir' => $hadir,
            'terlambat' => $terlambat,
            'pulang_cepat' => $pulangCepat,
            'izin' => $attendances->where('status', 'izin')->count(),
            'sakit' => $attendances->where('status', 'sakit')->count(),
            'alpa' => $attendances->where('status', 'alpa')->count(),
            'perlu_tinjauan' => $attendances->where('status', 'perlu_tinjauan')->count(),
            'total_hadir' => $hadir + $terlambat + $legacyPulangCepat,
        ];
    }

    /**
     * @param  array<string, Carbon>  $alpaDateKeys
     */
    private function countVirtualAlpa(Collection $attendances, array $alpaDateKeys): int
    {
        $attendanceDates = $attendances
            ->map(fn (Attendance $attendance): string => $attendance->school_date->toDateString())
            ->all();

        return count(array_diff(array_keys($alpaDateKeys), $attendanceDates));
    }

    private function attendanceNote(Attendance $attendance): string
    {
        if ($attendance->is_manual_override) {
            return '[Koreksi]: '.($attendance->override_reason ?? 'Manual');
        }

        if ($attendance->isAutomaticAlpa()) {
            return 'Alpa otomatis H+1';
        }

        if ($attendance->status === 'perlu_tinjauan') {
            if ($attendance->check_in_at !== null && $attendance->check_out_at !== null) {
                return 'Scan lengkap, perlu tinjauan';
            }

            if ($attendance->check_in_at !== null) {
                return 'Scan masuk tanpa scan pulang';
            }

            if ($attendance->check_out_at !== null) {
                return 'Scan pulang tanpa scan masuk';
            }

            return 'Tidak ada scan, perlu tinjauan';
        }

        if ($attendance->check_in_at !== null && $attendance->check_out_at === null) {
            return 'Scan masuk tanpa scan pulang';
        }

        if ($attendance->check_out_status === 'pulang_cepat' && $attendance->early_leave_minutes > 0) {
            return 'Pulang cepat '.$attendance->early_leave_minutes.' menit';
        }

        if ($attendance->status === 'terlambat' && $attendance->late_minutes > 0) {
            return 'Terlambat '.$attendance->late_minutes.' menit';
        }

        return '-';
    }

    private function attendanceDescription(bool $isSchoolHoliday, bool $isAlpa): string
    {
        if ($isSchoolHoliday) {
            return 'Libur';
        }

        return $isAlpa ? 'Alpa' : '-';
    }

    private function isAlpaWithoutAttendanceEvidence(?Attendance $attendance): bool
    {
        if ($attendance === null) {
            return true;
        }

        if ($attendance->check_in_at !== null || $attendance->check_out_at !== null) {
            return false;
        }

        return $attendance->status === 'alpa';
    }

    /**
     * Peta jam masuk/pulang resmi per hari (ISO: 1=Senin .. 7=Minggu) dari jadwal aktif.
     *
     * @return array<int, array{in: string, out: string}>
     */
    private function getScheduleMap(): array
    {
        return SchoolSchedule::where('is_active', true)
            ->get()
            ->mapWithKeys(fn ($schedule) => [
                (int) $schedule->day_of_week => [
                    'in' => $schedule->start_at ? substr($schedule->start_at, 0, 5) : '-',
                    'out' => $schedule->end_at ? substr($schedule->end_at, 0, 5) : '-',
                ],
            ])
            ->all();
    }

    /**
     * Laporan Presensi Harian.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function getDailyReport(array $filters, int $perPage = 0): array
    {
        $date = $filters['date'] ?? Carbon::today('Asia/Jakarta')->toDateString();
        $classId = $filters['class_id'] ?? null;
        $studentId = $filters['student_id'] ?? null;
        $status = $filters['status'] ?? null;

        $parsedDate = Carbon::parse($date, 'Asia/Jakarta');

        $recordQuery = Attendance::with(['student.schoolClass', 'operator'])
            ->whereDate('school_date', $date);

        if ($classId) {
            $recordQuery->whereHas('student', fn ($q) => $q->where('class_id', $classId));
        }

        if ($studentId) {
            $recordQuery->where('student_id', $studentId);
        }

        $studentQuery = Student::with('schoolClass')->where('active', true);
        if ($classId) {
            $studentQuery->where('class_id', $classId);
        }
        if ($studentId) {
            $studentQuery->where('id', $studentId);
        }
        $targetStudents = $studentQuery->get();
        $totalTargetStudents = $targetStudents->count();

        $allRecords = (clone $recordQuery)->orderBy('student_id')->get();
        $storedCounts = $this->summarizeAttendances($allRecords);

        $alpaDateKeys = $this->alpaService->getAlpaDateKeys($parsedDate, $parsedDate);
        $recordedStudentIds = $allRecords->pluck('student_id')->flip();
        $studentsWithoutRecords = $targetStudents
            ->reject(fn (Student $student): bool => $recordedStudentIds->has($student->id))
            ->values();

        $virtualAlpaCount = empty($alpaDateKeys) ? 0 : $studentsWithoutRecords->count();

        $virtualRows = empty($alpaDateKeys)
            ? collect()
            : $studentsWithoutRecords->map(fn (Student $student): array => [
                'id' => null,
                'student_id' => $student->id,
                'school_date' => $parsedDate->copy(),
                'nis' => $student->nis ?? '-',
                'student_name' => $student->name,
                'class_name' => $student->schoolClass?->name ?? '-',
                'check_in_at' => '-',
                'check_out_at' => '-',
                'status' => 'alpa',
                'check_out_status' => null,
                'late_minutes' => 0,
                'early_leave_minutes' => 0,
                'late_minutes_display' => null,
                'early_leave_minutes_display' => null,
                'is_virtual_alpa' => true,
                'is_manual_override' => false,
                'override_reason' => null,
                'operator_name' => '-',
                'note' => 'Alpa',
            ]);

        $totalRecorded = $allRecords->count();
        $totalHadir = $storedCounts['total_hadir'];
        $alpa = $storedCounts['alpa'] + $virtualAlpaCount;
        $isEffectiveDate = SchoolSchedule::isSchoolDay($parsedDate)
            && ! SchoolHoliday::isHoliday($parsedDate);
        $hasDailySchedule = SchoolSchedule::where('day_of_week', $parsedDate->dayOfWeekIso)
            ->where('is_active', true)
            ->exists();
        $hasMinuteCalculation = $isEffectiveDate && $hasDailySchedule;
        $belumHadir = $isEffectiveDate
            ? max(0, $totalTargetStudents - $totalRecorded - $virtualAlpaCount)
            : 0;

        $percentage = $totalTargetStudents > 0
            ? round(($totalHadir / $totalTargetStudents) * 100, 1)
            : 0;

        $summary = [
            'total_students' => $totalTargetStudents,
            'recorded' => $totalRecorded,
            'hadir' => $storedCounts['hadir'],
            'terlambat' => $storedCounts['terlambat'],
            'pulang_cepat' => $storedCounts['pulang_cepat'],
            'izin' => $storedCounts['izin'],
            'sakit' => $storedCounts['sakit'],
            'alpa' => $alpa,
            'perlu_tinjauan' => $storedCounts['perlu_tinjauan'],
            'virtual_alpa' => $virtualAlpaCount,
            'belum_hadir' => $belumHadir,
            'total_hadir' => $totalHadir,
            'percentage' => $percentage,
        ];

        $rowsCollection = $allRecords->map(fn (Attendance $item): array => [
            'id' => $item->id,
            'student_id' => $item->student_id,
            'school_date' => $item->school_date,
            'nis' => $item->student?->nis ?? '-',
            'student_name' => $item->student?->name ?? '-',
            'class_name' => $item->student?->schoolClass?->name ?? '-',
            'check_in_at' => $item->check_in_at ? $item->check_in_at->format('H:i:s') : '-',
            'check_out_at' => $item->check_out_at ? $item->check_out_at->format('H:i:s') : '-',
            'status' => $item->status,
            'check_out_status' => $item->check_out_status,
            'late_minutes' => (int) $item->late_minutes,
            'early_leave_minutes' => (int) $item->early_leave_minutes,
            'late_minutes_display' => $hasMinuteCalculation ? $item->late_minutes_display : null,
            'early_leave_minutes_display' => $hasMinuteCalculation ? $item->early_leave_minutes_display : null,
            'is_virtual_alpa' => false,
            'is_manual_override' => $item->is_manual_override,
            'override_reason' => $item->override_reason,
            'operator_name' => $item->operator?->name ?? '-',
            'note' => $this->attendanceNote($item),
        ])->concat($virtualRows);

        if ($status === 'pulang_cepat') {
            $rowsCollection = $rowsCollection->filter(
                fn (array $row): bool => $row['status'] === 'pulang_cepat'
                    || $row['check_out_status'] === 'pulang_cepat'
            );
        } elseif ($status) {
            $rowsCollection = $rowsCollection->where('status', $status);
        }

        $rowsCollection = $rowsCollection
            ->sortBy('student_id')
            ->values()
            ->map(fn (array $row, int $index): array => array_merge($row, ['no' => $index + 1]));

        // Pagination untuk Web Preview
        if ($perPage > 0) {
            $currentPage = LengthAwarePaginator::resolveCurrentPage();
            $paginatedItems = $rowsCollection->slice(($currentPage - 1) * $perPage, $perPage)->values();
            $paginatedRows = new LengthAwarePaginator(
                $paginatedItems,
                $rowsCollection->count(),
                $perPage,
                $currentPage,
                ['path' => LengthAwarePaginator::resolveCurrentPath(), 'query' => request()->query()]
            );
            $rows = $paginatedRows;
        } else {
            $rows = $rowsCollection;
        }

        $selectedClass = $classId ? SchoolClass::find($classId) : null;
        $selectedStudent = $studentId ? Student::with('schoolClass')->find($studentId) : null;

        return [
            'type' => 'daily',
            'title' => 'LAPORAN PRESENSI HARIAN SISWA',
            'date' => $date,
            'date_formatted' => $parsedDate->locale('id')->isoFormat('dddd, D MMMM Y'),
            'selectedClass' => $selectedClass,
            'selectedStudent' => $selectedStudent,
            'summary' => $summary,
            'rows' => $rows,
            'total_rows' => $rowsCollection->count(),
        ];
    }

    /**
     * Laporan Presensi Bulanan (Semua Kelas / Filter Kelas).
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function getMonthlyReport(array $filters, int $perPage = 0): array
    {
        $month = $filters['month'] ?? Carbon::now('Asia/Jakarta')->format('Y-m');
        $classId = $filters['class_id'] ?? null;

        $startDate = Carbon::parse($month.'-01', 'Asia/Jakarta')->startOfMonth();
        $endDate = $startDate->copy()->endOfMonth();

        $effectiveData = $this->getEffectiveDaysInMonth($startDate, $endDate);
        $effectiveDays = $effectiveData['effective_days'];

        $studentQuery = Student::with('schoolClass')
            ->where('active', true)
            ->orderBy('class_id')
            ->orderBy('name');

        if ($classId) {
            $studentQuery->where('class_id', $classId);
        }

        $students = $studentQuery->get();

        $attendances = Attendance::whereIn('student_id', $students->pluck('id'))
            ->whereBetween('school_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->get()
            ->groupBy('student_id');

        $alpaDateKeys = $this->alpaService->getAlpaDateKeys($startDate, $endDate);

        $rowsList = [];
        $totalHadirAll = 0;
        $totalTerlambatAll = 0;
        $totalPulangCepatAll = 0;
        $totalIzinAll = 0;
        $totalSakitAll = 0;
        $totalAlpaAll = 0;
        $totalPerluTinjauanAll = 0;
        $totalVirtualAlpaAll = 0;
        $totalHadirKeseluruhan = 0;

        foreach ($students as $index => $st) {
            $stAtts = $attendances->get($st->id, collect());
            $stored = $this->summarizeAttendances($stAtts);
            $virtualAlpa = $this->countVirtualAlpa($stAtts, $alpaDateKeys);
            $a = $stored['alpa'] + $virtualAlpa;
            $totHadir = $stored['total_hadir'];
            $pct = $effectiveDays > 0 ? round(($totHadir / $effectiveDays) * 100, 1) : 0;

            $firstIn = $stAtts->pluck('check_in_at')->filter()->min();
            $lastOut = $stAtts->pluck('check_out_at')->filter()->max();

            $totalHadirAll += $stored['hadir'];
            $totalTerlambatAll += $stored['terlambat'];
            $totalPulangCepatAll += $stored['pulang_cepat'];
            $totalIzinAll += $stored['izin'];
            $totalSakitAll += $stored['sakit'];
            $totalAlpaAll += $a;
            $totalPerluTinjauanAll += $stored['perlu_tinjauan'];
            $totalVirtualAlpaAll += $virtualAlpa;
            $totalHadirKeseluruhan += $totHadir;

            $rowsList[] = [
                'no' => $index + 1,
                'student' => $st,
                'nis' => $st->nis ?? '-',
                'student_name' => $st->name,
                'class_name' => $st->schoolClass?->name ?? '-',
                'first_check_in' => $firstIn ? $firstIn->format('H:i') : '-',
                'last_check_out' => $lastOut ? $lastOut->format('H:i') : '-',
                'hadir' => $stored['hadir'],
                'terlambat' => $stored['terlambat'],
                'pulang_cepat' => $stored['pulang_cepat'],
                'izin' => $stored['izin'],
                'sakit' => $stored['sakit'],
                'alpa' => $a,
                'perlu_tinjauan' => $stored['perlu_tinjauan'],
                'virtual_alpa' => $virtualAlpa,
                'total_hadir' => $totHadir,
                'percentage' => $pct,
            ];
        }

        $totalStudents = count($rowsList);
        $averagePct = $totalStudents > 0
            ? round(array_sum(array_column($rowsList, 'percentage')) / $totalStudents, 1)
            : 0;

        $summary = [
            'total_students' => $totalStudents,
            'effective_days' => $effectiveDays,
            'hadir' => $totalHadirAll,
            'terlambat' => $totalTerlambatAll,
            'pulang_cepat' => $totalPulangCepatAll,
            'izin' => $totalIzinAll,
            'sakit' => $totalSakitAll,
            'alpa' => $totalAlpaAll,
            'perlu_tinjauan' => $totalPerluTinjauanAll,
            'virtual_alpa' => $totalVirtualAlpaAll,
            'total_hadir' => $totalHadirKeseluruhan,
            'percentage' => $averagePct,
        ];

        $rowsCollection = collect($rowsList);

        if ($perPage > 0) {
            $currentPage = LengthAwarePaginator::resolveCurrentPage();
            $paginatedItems = $rowsCollection->slice(($currentPage - 1) * $perPage, $perPage)->values();
            $rows = new LengthAwarePaginator(
                $paginatedItems,
                $rowsCollection->count(),
                $perPage,
                $currentPage,
                ['path' => LengthAwarePaginator::resolveCurrentPath(), 'query' => request()->query()]
            );
        } else {
            $rows = $rowsCollection;
        }

        $selectedClass = $classId ? SchoolClass::find($classId) : null;

        return [
            'type' => 'monthly',
            'title' => 'REKAPITULASI PRESENSI BULANAN',
            'month' => $month,
            'month_formatted' => $startDate->locale('id')->isoFormat('MMMM Y'),
            'startDate' => $startDate,
            'effectiveDays' => $effectiveDays,
            'selectedClass' => $selectedClass,
            'summary' => $summary,
            'rows' => $rows,
            'total_rows' => $totalStudents,
        ];
    }

    /**
     * Laporan Presensi Per Kelas.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function getClassMonthlyReport(array $filters, int $perPage = 0): array
    {
        $month = $filters['month'] ?? Carbon::now('Asia/Jakarta')->format('Y-m');
        $classId = $filters['class_id'] ?? null;

        $startDate = Carbon::parse($month.'-01', 'Asia/Jakarta')->startOfMonth();
        $endDate = $startDate->copy()->endOfMonth();

        $effectiveData = $this->getEffectiveDaysInMonth($startDate, $endDate);
        $effectiveDays = $effectiveData['effective_days'];

        $selectedClass = $classId
            ? SchoolClass::with(['students' => fn ($q) => $q->where('active', true)->orderBy('name')])->find($classId)
            : null;

        $studentRows = [];
        $classTotalHadir = 0;
        $classTotalTerlambat = 0;
        $classTotalPulangCepat = 0;
        $classTotalIzin = 0;
        $classTotalSakit = 0;
        $classTotalAlpa = 0;
        $classTotalPerluTinjauan = 0;
        $classTotalVirtualAlpa = 0;
        $classTotalHadirKeseluruhan = 0;

        if ($selectedClass && $selectedClass->students->isNotEmpty()) {
            $studentIds = $selectedClass->students->pluck('id')->toArray();
            $classAttendances = Attendance::whereIn('student_id', $studentIds)
                ->whereBetween('school_date', [$startDate->toDateString(), $endDate->toDateString()])
                ->get()
                ->groupBy('student_id');

            $alpaDateKeys = $this->alpaService->getAlpaDateKeys($startDate, $endDate);

            foreach ($selectedClass->students as $idx => $st) {
                $stAtts = $classAttendances->get($st->id, collect());
                $stored = $this->summarizeAttendances($stAtts);
                $virtualAlpa = $this->countVirtualAlpa($stAtts, $alpaDateKeys);
                $a = $stored['alpa'] + $virtualAlpa;
                $totHadir = $stored['total_hadir'];
                $pct = $effectiveDays > 0 ? round(($totHadir / $effectiveDays) * 100, 1) : 0;

                $firstIn = $stAtts->pluck('check_in_at')->filter()->min();
                $lastOut = $stAtts->pluck('check_out_at')->filter()->max();

                $classTotalHadir += $stored['hadir'];
                $classTotalTerlambat += $stored['terlambat'];
                $classTotalPulangCepat += $stored['pulang_cepat'];
                $classTotalIzin += $stored['izin'];
                $classTotalSakit += $stored['sakit'];
                $classTotalAlpa += $a;
                $classTotalPerluTinjauan += $stored['perlu_tinjauan'];
                $classTotalVirtualAlpa += $virtualAlpa;
                $classTotalHadirKeseluruhan += $totHadir;

                $studentRows[] = [
                    'no' => $idx + 1,
                    'student' => $st,
                    'nis' => $st->nis ?? '-',
                    'student_name' => $st->name,
                    'class_name' => $selectedClass->name,
                    'first_check_in' => $firstIn ? $firstIn->format('H:i') : '-',
                    'last_check_out' => $lastOut ? $lastOut->format('H:i') : '-',
                    'hadir' => $stored['hadir'],
                    'terlambat' => $stored['terlambat'],
                    'pulang_cepat' => $stored['pulang_cepat'],
                    'izin' => $stored['izin'],
                    'sakit' => $stored['sakit'],
                    'alpa' => $a,
                    'perlu_tinjauan' => $stored['perlu_tinjauan'],
                    'virtual_alpa' => $virtualAlpa,
                    'total_hadir' => $totHadir,
                    'percentage' => $pct,
                ];
            }
        }

        $totalStudents = count($studentRows);
        $classAveragePct = $totalStudents > 0
            ? round(array_sum(array_column($studentRows, 'percentage')) / $totalStudents, 1)
            : 0;

        $summary = [
            'total_students' => $totalStudents,
            'effective_days' => $effectiveDays,
            'hadir' => $classTotalHadir,
            'terlambat' => $classTotalTerlambat,
            'pulang_cepat' => $classTotalPulangCepat,
            'izin' => $classTotalIzin,
            'sakit' => $classTotalSakit,
            'alpa' => $classTotalAlpa,
            'perlu_tinjauan' => $classTotalPerluTinjauan,
            'virtual_alpa' => $classTotalVirtualAlpa,
            'total_hadir' => $classTotalHadirKeseluruhan,
            'percentage' => $classAveragePct,
        ];

        $rowsCollection = collect($studentRows);

        if ($perPage > 0) {
            $currentPage = LengthAwarePaginator::resolveCurrentPage();
            $paginatedItems = $rowsCollection->slice(($currentPage - 1) * $perPage, $perPage)->values();
            $rows = new LengthAwarePaginator(
                $paginatedItems,
                $rowsCollection->count(),
                $perPage,
                $currentPage,
                ['path' => LengthAwarePaginator::resolveCurrentPath(), 'query' => request()->query()]
            );
        } else {
            $rows = $rowsCollection;
        }

        return [
            'type' => 'class',
            'title' => 'LAPORAN REKAPITULASI PRESENSI KELAS',
            'month' => $month,
            'month_formatted' => $startDate->locale('id')->isoFormat('MMMM Y'),
            'startDate' => $startDate,
            'effectiveDays' => $effectiveDays,
            'selectedClass' => $selectedClass,
            'summary' => $summary,
            'rows' => $rows,
            'total_rows' => $totalStudents,
        ];
    }

    /**
     * Laporan Presensi Per Siswa Bulanan (Rincian Kalender Harian Siswa).
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function getStudentMonthlyReport(array $filters): array
    {
        $month = $filters['month'] ?? Carbon::now('Asia/Jakarta')->format('Y-m');
        $studentId = $filters['student_id'] ?? null;

        $selectedStudent = $studentId ? Student::with('schoolClass')->find($studentId) : null;
        if (! $selectedStudent) {
            $studentQuery = Student::with('schoolClass')->where('active', true);
            if (! empty($filters['class_id'])) {
                $studentQuery->where('class_id', $filters['class_id']);
            }
            $selectedStudent = $studentQuery->orderBy('name')->first();
        }

        $startDate = Carbon::parse($month.'-01', 'Asia/Jakarta')->startOfMonth();
        $endDate = $startDate->copy()->endOfMonth();
        $totalDaysInMonth = $endDate->day;

        $scheduleMap = $this->getScheduleMap();

        $attendances = $selectedStudent
            ? Attendance::where('student_id', $selectedStudent->id)
                ->whereBetween('school_date', [$startDate->toDateString(), $endDate->toDateString()])
                ->get()
                ->keyBy(fn ($item) => $item->school_date->toDateString())
            : collect();

        $holidays = SchoolHoliday::active()
            ->whereDate('start_date', '<=', $endDate->toDateString())
            ->whereDate('end_date', '>=', $startDate->toDateString())
            ->get();

        $alpaDateKeys = $this->alpaService->getAlpaDateKeys($startDate, $endDate);

        $dailyRows = [];
        $summary = [
            'hadir' => 0,
            'terlambat' => 0,
            'pulang_cepat' => 0,
            'izin' => 0,
            'sakit' => 0,
            'alpa' => 0,
            'perlu_tinjauan' => 0,
            'virtual_alpa' => 0,
            'libur' => 0,
            'total_effective' => 0,
        ];
        $legacyPulangCepatCount = 0;

        $activeDays = SchoolSchedule::getActiveDayNumbers();
        $hasScheduleConfig = ! empty($activeDays);

        for ($day = 1; $day <= $totalDaysInMonth; $day++) {
            $currentDate = $startDate->copy()->day($day);
            $dateStr = $currentDate->toDateString();
            $dayOfWeek = (int) $currentDate->dayOfWeekIso; // 1 = Senin, 7 = Minggu
            $isWeekendOrOff = $hasScheduleConfig ? ! in_array($dayOfWeek, $activeDays, true) : ($dayOfWeek === 7);

            $holidayMatch = $holidays->first(function ($h) use ($dateStr) {
                return $dateStr >= $h->start_date->toDateString() && $dateStr <= $h->end_date->toDateString();
            });

            $att = $attendances->get($dateStr);
            $isSchoolHoliday = $holidayMatch !== null;
            $isHolidayDay = $isWeekendOrOff || $isSchoolHoliday;
            $schedule = $scheduleMap[$dayOfWeek] ?? null;

            $statusText = '-';
            $isVirtualAlpa = false;

            if ($att) {
                $statusText = $att->status;
                if ($att->status === 'hadir') {
                    $summary['hadir']++;
                } elseif ($att->status === 'terlambat') {
                    $summary['terlambat']++;
                } elseif ($att->status === 'pulang_cepat') {
                    $legacyPulangCepatCount++;
                } elseif ($att->status === 'izin') {
                    $summary['izin']++;
                } elseif ($att->status === 'sakit') {
                    $summary['sakit']++;
                } elseif ($att->status === 'alpa') {
                    $summary['alpa']++;
                } elseif ($att->status === 'perlu_tinjauan') {
                    $summary['perlu_tinjauan']++;
                }

                if ($att->status === 'pulang_cepat' || $att->check_out_status === 'pulang_cepat') {
                    $summary['pulang_cepat']++;
                }
            } elseif ($isHolidayDay) {
                $statusText = 'libur';
                $summary['libur']++;
            } elseif (isset($alpaDateKeys[$dateStr])) {
                $statusText = 'alpa';
                $isVirtualAlpa = true;
                $summary['alpa']++;
                $summary['virtual_alpa']++;
            }

            $isAlpa = ! $isSchoolHoliday
                && isset($alpaDateKeys[$dateStr])
                && $this->isAlpaWithoutAttendanceEvidence($att);

            $note = $this->attendanceDescription($isSchoolHoliday, $isAlpa);

            if (! $isHolidayDay) {
                $summary['total_effective']++;
            }

            $dailyRows[] = [
                'day_number' => $day,
                'date' => $currentDate,
                'date_formatted' => $currentDate->format('d/m/Y'),
                'day_name' => $currentDate->locale('id')->isoFormat('dddd'),
                'attendance' => $att,
                'schedule_in' => $schedule['in'] ?? '-',
                'schedule_out' => $schedule['out'] ?? '-',
                'check_in_at' => $att?->check_in_at ? $att->check_in_at->format('H:i') : '-',
                'check_out_at' => $att?->check_out_at ? $att->check_out_at->format('H:i') : '-',
                'status' => $statusText,
                'is_holiday' => $isHolidayDay,
                'is_virtual_alpa' => $isVirtualAlpa,
                'late_minutes' => (int) ($att?->late_minutes ?? 0),
                'early_leave_minutes' => (int) ($att?->early_leave_minutes ?? 0),
                'late_minutes_display' => $isHolidayDay || $schedule === null ? null : $att?->late_minutes_display,
                'early_leave_minutes_display' => $isHolidayDay || $schedule === null ? null : $att?->early_leave_minutes_display,
                'check_out_status' => $att?->check_out_status,
                'note' => $note,
                'override_reason' => $att?->is_manual_override ? $att->override_reason : null,
            ];
        }

        $totalAttendanceCount = $summary['hadir'] + $summary['terlambat'] + $legacyPulangCepatCount;
        $percentage = $summary['total_effective'] > 0
            ? round(($totalAttendanceCount / $summary['total_effective']) * 100, 1)
            : 0;

        $summary['total_hadir'] = $totalAttendanceCount;
        $summary['percentage'] = $percentage;

        return [
            'type' => 'student',
            'title' => 'LAPORAN PRESENSI BULANAN',
            'month' => $month,
            'month_formatted' => $startDate->locale('id')->isoFormat('MMMM Y'),
            'startDate' => $startDate,
            'selectedStudent' => $selectedStudent,
            'selectedClass' => $selectedStudent?->schoolClass,
            'dailyRows' => $dailyRows,
            'rows' => $dailyRows,
            'summary' => $summary,
            'percentage' => $percentage,
            'total_rows' => count($dailyRows),
        ];
    }
}
