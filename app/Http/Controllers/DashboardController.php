<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\AttendanceLog;
use App\Models\FingerprintDevice;
use App\Models\Guardian;
use App\Models\NotificationLog;
use App\Models\SchoolClass;
use App\Models\SchoolHoliday;
use App\Models\SchoolSchedule;
use App\Models\Student;
use App\Services\AttendanceAlpaService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(
        protected AttendanceAlpaService $alpaService
    ) {}

    /**
     * Tampilkan dashboard pemantauan absensi utama.
     */
    public function index(Request $request): View
    {
        $selectedDate = $request->input('date', Carbon::today('Asia/Jakarta')->toDateString());
        $selectedClassId = $request->input('class_id');

        if ($request->user()?->isHomeroomTeacher()) {
            $selectedClassId = $request->user()->class_id;
            $classes = SchoolClass::where('id', $selectedClassId)->get();
            $totalStudents = Student::where('active', true)->where('class_id', $selectedClassId)->count();
        } else {
            $classes = SchoolClass::orderBy('name')->get();
            $totalStudents = Student::where('active', true)
                ->when($selectedClassId, fn ($query) => $query->where('class_id', $selectedClassId))
                ->count();
        }

        // Query dasar attendances untuk tanggal terpilih (dipakai statistik agregat & pratinjau).
        $attendanceBaseQuery = Attendance::query()->whereDate('school_date', $selectedDate);

        if ($selectedClassId) {
            $attendanceBaseQuery->whereHas('student', function ($q) use ($selectedClassId) {
                $q->where('class_id', $selectedClassId);
            });
        }

        // Cek apakah tanggal terpilih merupakan hari libur atau di luar hari aktif sekolah
        $isSchoolDay = SchoolSchedule::isSchoolDay($selectedDate);
        $isHoliday = SchoolHoliday::isHoliday($selectedDate);
        $isNonEffectiveDay = ! $isSchoolDay || $isHoliday;

        // Statistik Kehadiran Tanggal Terpilih (PERF-02: agregat, tanpa memuat seluruh baris).
        $statusCounts = (clone $attendanceBaseQuery)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $hadirCount = (int) $statusCounts->get('hadir', 0);
        $terlambatCount = (int) $statusCounts->get('terlambat', 0);
        $legacyPulangCepatCount = (int) $statusCounts->get('pulang_cepat', 0);
        $pulangCepatCount = (clone $attendanceBaseQuery)
            ->where(function ($query) {
                $query->where('status', 'pulang_cepat')
                    ->orWhere('check_out_status', 'pulang_cepat');
            })
            ->count();
        $hadirEarlyCount = (clone $attendanceBaseQuery)
            ->where('status', 'hadir')
            ->where('check_out_status', 'pulang_cepat')
            ->count();
        $storedAlpaCount = (int) $statusCounts->get('alpa', 0);
        $pendingCount = (int) $statusCounts->get('perlu_tinjauan', 0);
        $izinSakitCount = (int) $statusCounts->get('izin', 0) + (int) $statusCounts->get('sakit', 0);
        $pulangCount = (clone $attendanceBaseQuery)->whereNotNull('check_out_at')->count();
        $totalRecorded = (int) $statusCounts->sum();
        $unreportedCount = max(0, $totalStudents - $totalRecorded);
        $virtualAlpaCount = $this->alpaService->isAlpaDue(Carbon::parse($selectedDate, 'Asia/Jakarta'))
            ? $unreportedCount
            : 0;
        $alpaCount = $storedAlpaCount + $virtualAlpaCount;
        $belumHadirCount = $isNonEffectiveDay
            ? 0
            : max(0, $unreportedCount - $virtualAlpaCount) + $pendingCount;

        // Pratinjau tabel live dibatasi; "Buka Tabel Lengkap" menangani kebutuhan penuh.
        $attendances = (clone $attendanceBaseQuery)
            ->with([
                'student' => fn ($query) => $query->withTrashed()->with('schoolClass', 'guardians'),
                'checkInLog',
                'checkOutLog',
                'notifications',
            ])
            ->latest('updated_at')
            ->limit(50)
            ->get();

        // Status Perangkat Solution X601 (LAN)
        $devices = FingerprintDevice::withCount(['deviceUsers', 'attendanceLogs'])->get();
        $mainDevice = $devices->first();

        // Raw Logs Terbaru (FR-18)
        $recentLogs = AttendanceLog::with('device')
            ->whereDate('occurred_at', $selectedDate)
            ->latest('occurred_at')
            ->limit(20)
            ->get();

        // Raw Logs Belum Terpetakan / Unmapped (FR-08: Log tidak dikenal masuk daftar perlu pencocokan)
        $unmappedLogs = AttendanceLog::with('device')
            ->where('status', 'unmapped')
            ->latest('occurred_at')
            ->limit(10)
            ->get();

        // Status Antrean Notifikasi WhatsApp (FR-16) — agregasi 1 query per grup.
        $waStatsRaw = NotificationLog::statusCounts($selectedDate);

        $waStats = [
            'total' => (int) $waStatsRaw->sum(),
            'queued' => (int) $waStatsRaw->get('queued', 0),
            'held' => (int) $waStatsRaw->get('held', 0),
            'sent' => (int) $waStatsRaw->get('sent', 0),
            'delivered' => (int) $waStatsRaw->get('delivered', 0),
            'failed' => (int) $waStatsRaw->get('failed', 0),
            'cancelled' => (int) $waStatsRaw->get('cancelled', 0),
        ];

        // Status Validasi Nomor WhatsApp Orang Tua (Fonnte API Optimization) — agregasi 1 query.
        // wa_status NULL diperlakukan sebagai 'pending' (selaras dengan tampilan lama).
        $guardianStatsRaw = Guardian::where('active', true)
            ->selectRaw("COALESCE(wa_status, 'pending') as effective_status, COUNT(*) as total")
            ->groupBy('effective_status')
            ->pluck('total', 'effective_status');

        $guardianStats = [
            'total' => (int) $guardianStatsRaw->sum(),
            'valid' => (int) $guardianStatsRaw->get('valid', 0),
            'pending' => (int) $guardianStatsRaw->get('pending', 0),
            'invalid' => (int) $guardianStatsRaw->get('invalid', 0),
            'unknown' => (int) $guardianStatsRaw->get('unknown', 0),
        ];

        $recentNotifications = NotificationLog::with(['attendance.student', 'guardian'])
            ->whereDate('created_at', $selectedDate)
            ->latest()
            ->limit(10)
            ->get();

        // Data Visualisasi Grafik: Tren Presensi 7 Hari Terakhir & Rasio Hari Ini
        $endDate = Carbon::parse($selectedDate, 'Asia/Jakarta');
        $startDate = $endDate->copy()->subDays(6);

        $trendQuery = Attendance::query()
            ->whereDate('school_date', '>=', $startDate->toDateString())
            ->whereDate('school_date', '<=', $endDate->toDateString());

        if ($selectedClassId) {
            $trendQuery->whereHas('student', function ($q) use ($selectedClassId) {
                $q->where('class_id', $selectedClassId);
            });
        }

        $trendGrouped = $trendQuery
            ->get(['school_date', 'status', 'check_out_status'])
            ->groupBy(fn (Attendance $attendance): string => $attendance->school_date->toDateString());
        $alpaDateKeys = $this->alpaService->getAlpaDateKeys($startDate, $endDate);

        $trendLabels = [];
        $trendHadir = [];
        $trendTerlambat = [];
        $trendPulangCepat = [];
        $trendIzinSakit = [];
        $trendAlpa = [];

        $cursor = $startDate->copy();
        while ($cursor->lte($endDate)) {
            $dStr = $cursor->toDateString();
            $trendLabels[] = $cursor->translatedFormat('D, d M');

            $dayRecords = $trendGrouped->get($dStr, collect());
            $hCount = $dayRecords->where('status', 'hadir')->count();
            $tCount = $dayRecords->where('status', 'terlambat')->count();
            $pcCount = $dayRecords->filter(
                fn (Attendance $attendance): bool => $attendance->status === 'pulang_cepat'
                    || $attendance->check_out_status === 'pulang_cepat'
            )->count();
            $isCount = $dayRecords->whereIn('status', ['izin', 'sakit'])->count();
            $recordedCount = $dayRecords->count();
            $trendVirtualAlpa = isset($alpaDateKeys[$dStr])
                ? max(0, $totalStudents - $recordedCount)
                : 0;
            $trendAlpaCount = $dayRecords->where('status', 'alpa')->count() + $trendVirtualAlpa;

            $trendHadir[] = $hCount;
            $trendTerlambat[] = $tCount;
            $trendPulangCepat[] = $pcCount;
            $trendIzinSakit[] = $isCount;
            $trendAlpa[] = $trendAlpaCount;

            $cursor->addDay();
        }

        $trendChartData = [
            'labels' => $trendLabels,
            'hadir' => $trendHadir,
            'terlambat' => $trendTerlambat,
            'pulang_cepat' => $trendPulangCepat,
            'izin_sakit' => $trendIzinSakit,
            'alpa' => $trendAlpa,
        ];

        $presentCount = $hadirCount + $terlambatCount + $legacyPulangCepatCount;
        $attendanceRate = $totalStudents > 0
            ? round(($presentCount / $totalStudents) * 100, 1)
            : 0;

        $donutChartData = [
            'labels' => ['Tepat Waktu', 'Terlambat', 'Pulang Cepat', 'Izin & Sakit', 'Alpa / Belum Hadir'],
            'data' => [
                max(0, $hadirCount - $hadirEarlyCount),
                $terlambatCount,
                $hadirEarlyCount + $legacyPulangCepatCount,
                $izinSakitCount,
                $alpaCount + $belumHadirCount,
            ],
            'rate' => $attendanceRate,
        ];

        return view('dashboard.index', compact(
            'selectedDate',
            'selectedClassId',
            'classes',
            'totalStudents',
            'attendances',
            'hadirCount',
            'terlambatCount',
            'pulangCepatCount',
            'pulangCount',
            'izinSakitCount',
            'pendingCount',
            'alpaCount',
            'virtualAlpaCount',
            'belumHadirCount',
            'devices',
            'mainDevice',
            'recentLogs',
            'unmappedLogs',
            'waStats',
            'guardianStats',
            'recentNotifications',
            'trendChartData',
            'donutChartData',
            'attendanceRate',
            'isSchoolDay',
            'isHoliday',
            'isNonEffectiveDay'
        ));
    }
}
