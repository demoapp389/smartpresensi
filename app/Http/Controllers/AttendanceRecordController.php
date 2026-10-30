<?php

namespace App\Http\Controllers;

use App\Concerns\LogsAuditTrail;
use App\Models\Attendance;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Services\AttendanceCorrectionService;
use App\Services\AttendanceReportService;
use App\Services\SimpleXlsxExporter;
use App\Services\WhatsAppNotificationService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttendanceRecordController extends Controller
{
    use LogsAuditTrail;

    public function __construct(
        protected AttendanceReportService $reportService,
        protected AttendanceCorrectionService $correctionService,
        protected WhatsAppNotificationService $whatsAppNotificationService,
    ) {}

    public function index(Request $request): View
    {
        $selectedDate = $request->input('date', $request->input('date_from', Carbon::today('Asia/Jakarta')->toDateString()));
        $dateFrom = $request->input('date_from', $selectedDate);
        $dateTo = $request->input('date_to', $dateFrom);
        $classId = $request->input('class_id');
        $studentId = $request->input('student_id');
        $status = $request->input('status');
        $outStatus = $request->input('out_status');
        $month = $request->input('month', Carbon::parse($dateFrom)->format('Y-m'));

        // Jika Wali Kelas, batasi hanya untuk kelas yang diampunya
        if (Auth::user()?->isHomeroomTeacher()) {
            $classId = Auth::user()->class_id;
        }

        $query = Attendance::with([
            'student' => fn ($query) => $query->withTrashed()->with('schoolClass', 'guardians'),
            'checkInLog',
            'checkOutLog',
            'operator',
            'notifications',
        ])->latest('updated_at');

        if ($dateFrom === $dateTo) {
            $query->whereDate('school_date', $dateFrom);
        } else {
            $query->whereBetween('school_date', [$dateFrom, $dateTo]);
        }

        if ($classId) {
            $query->whereHas('student', function ($q) use ($classId) {
                $q->where('class_id', $classId);
            });
        }

        // Bila siswa terpilih tidak berada di kelas yang difilter, abaikan filter siswa
        // agar tidak menghasilkan irisan kosong (mis. saat filter kelas diubah).
        if ($classId && $studentId) {
            $studentInClass = Student::where('class_id', $classId)->where('id', $studentId)->exists();
            if (! $studentInClass) {
                $studentId = null;
            }
        }

        if ($studentId) {
            $query->where('student_id', $studentId);
        }

        if ($status) {
            $query->where('status', $status);
        }

        if ($outStatus) {
            $query->where('check_out_status', $outStatus);
        }

        // Data dikirim penuh (tanpa paginate): tabel memakai DataTables di sisi
        // klien. Presensi sudah dibatasi per tanggal (default hari ini).
        $attendances = $query->get();
        $classes = Auth::user()?->isHomeroomTeacher() && $classId
            ? SchoolClass::where('id', $classId)->get()
            : SchoolClass::orderBy('name')->get();

        // Daftar siswa pada filter mengikuti kelas yang dipilih, bukan hanya wali kelas.
        $students = Student::with('schoolClass')
            ->where('active', true)
            ->when($classId, fn ($q) => $q->where('class_id', $classId))
            ->orderBy('name')
            ->get();

        return view('attendances.index', compact('attendances', 'classes', 'students', 'dateFrom', 'dateTo', 'selectedDate', 'classId', 'studentId', 'status', 'outStatus', 'month'));
    }

    public function correctionIndex(Request $request): View
    {
        $user = Auth::user();
        $selectedDate = $request->input('date', Carbon::today('Asia/Jakarta')->toDateString());
        $classId = $request->input('class_id');

        // Jika Wali Kelas, batasi hanya untuk kelas yang diampunya
        if ($user?->isHomeroomTeacher()) {
            $classId = $user->class_id;
        }

        $query = Attendance::with([
            'student' => fn ($query) => $query->withTrashed()->with('schoolClass'),
            'operator',
        ])
            ->whereDate('school_date', $selectedDate)
            ->orderBy('student_id');

        if ($classId) {
            $query->whereHas('student', function ($q) use ($classId) {
                $q->where('class_id', $classId);
            });
        }

        // Data dikirim penuh (tanpa paginate): tabel memakai DataTables di sisi
        // klien. Presensi sudah dibatasi per tanggal (default hari ini).
        $attendances = $query->get();
        $classes = $user?->isHomeroomTeacher() && $classId
            ? SchoolClass::where('id', $classId)->get()
            : SchoolClass::orderBy('name')->get();

        $students = Student::with('schoolClass')
            ->where('active', true)
            ->when($classId, fn ($q) => $q->where('class_id', $classId))
            ->orderBy('name')
            ->get();

        $allowedStatusesByAttendance = [];
        $actionableAttendanceIds = [];
        $scanReasonAttendanceIds = [];
        $adminScanExceptionAttendanceIds = [];

        if ($user !== null) {
            foreach ($attendances as $attendance) {
                $statuses = $this->correctionService->allowedStatusesFor($user, $attendance);
                $allowedStatusesByAttendance[$attendance->id] = $statuses;
                $actionableAttendanceIds[$attendance->id] = collect($statuses)
                    ->contains(fn (string $status): bool => $status !== $attendance->status);
                $scanReasonAttendanceIds[$attendance->id] = $this->correctionService
                    ->canRecordScanReason($user, $attendance);
                $adminScanExceptionAttendanceIds[$attendance->id] = $this->correctionService
                    ->canUseAdminScanException($user, $attendance);
            }
        }

        $canBulkCorrect = $user?->isOperator() ?? false;
        $manualStatuses = $user?->isHomeroomTeacher()
            ? AttendanceCorrectionService::HOMEROOM_STATUSES
            : AttendanceCorrectionService::MANUAL_STATUSES;
        $statusOptions = AttendanceCorrectionService::BULK_STATUSES;

        $isFiltered = $request->filled('date');

        return view('attendances.correction', compact(
            'attendances',
            'classes',
            'students',
            'selectedDate',
            'classId',
            'isFiltered',
            'allowedStatusesByAttendance',
            'actionableAttendanceIds',
            'scanReasonAttendanceIds',
            'adminScanExceptionAttendanceIds',
            'canBulkCorrect',
            'manualStatuses',
            'statusOptions',
        ));
    }

    /**
     * Mengoreksi status sejumlah presensi siswa secara massal.
     */
    public function bulkCorrect(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:attendances,id'],
            'status' => ['required', 'string', 'in:'.implode(',', AttendanceCorrectionService::BULK_STATUSES)],
            'override_reason' => ['required', 'string', 'max:500'],
        ]);

        $user = Auth::user();
        $this->correctionService->assertCanBulkCorrect($user, $validated['status']);

        $attendances = Attendance::with('student')->whereIn('id', $validated['ids'])->get();
        $this->correctionService->assertBulkRowsAllowed($user, $attendances, $validated['status']);

        DB::transaction(function () use ($attendances, $validated, $user) {
            $lockedAttendances = Attendance::with('student')
                ->whereIn('id', $attendances->pluck('id'))
                ->lockForUpdate()
                ->get();

            $this->correctionService->assertBulkRowsAllowed($user, $lockedAttendances, $validated['status']);

            foreach ($lockedAttendances as $attendance) {
                $before = $this->correctionService->auditState($attendance);
                $attributes = $this->correctionService->overrideAttributes(
                    $attendance,
                    $validated['status'],
                    $validated['override_reason'],
                    $user->id
                );

                $attendance->update($attributes);
                $this->cancelPendingNotifications($attendance);

                $this->logAudit(
                    'bulk_manual_override_attendance',
                    'Attendance',
                    $attendance->id,
                    $before,
                    $this->correctionService->auditState($attendance),
                    $user->id
                );
            }
        });

        $count = $attendances->count();

        return redirect()->back()->with('success', "Status absensi {$count} siswa berhasil diperbarui secara massal.");
    }

    public function correct(Request $request, Attendance $attendance): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string', 'in:'.implode(',', AttendanceCorrectionService::CORRECTION_STATUSES)],
            'override_reason' => ['required', 'string', 'max:500'],
        ]);

        $user = Auth::user();

        DB::transaction(function () use ($attendance, $validated, $user): void {
            $lockedAttendance = Attendance::whereKey($attendance->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->correctionService->assertCanCorrect($user, $lockedAttendance, $validated['status']);

            $before = $this->correctionService->auditState($lockedAttendance);

            $lockedAttendance->update($this->correctionService->overrideAttributes(
                $lockedAttendance,
                $validated['status'],
                $validated['override_reason'],
                $user->id
            ));
            $this->cancelPendingNotifications($lockedAttendance);

            $this->logAudit(
                'manual_override_attendance',
                'Attendance',
                $lockedAttendance->id,
                $before,
                $this->correctionService->auditState($lockedAttendance)
            );
        });

        $studentName = Student::withTrashed()->whereKey($attendance->student_id)->value('name') ?? 'Siswa';

        return redirect()->back()->with('success', "Status absensi {$studentName} berhasil diperbarui.");
    }

    public function adminScanException(Request $request, Attendance $attendance): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string', 'in:'.implode(',', AttendanceCorrectionService::ADMIN_SCAN_EXCEPTION_STATUSES)],
            'override_reason' => ['required', 'string', 'max:500'],
        ]);

        $user = Auth::user();

        DB::transaction(function () use ($attendance, $validated, $user): void {
            $lockedAttendance = Attendance::whereKey($attendance->id)
                ->lockForUpdate()
                ->firstOrFail();

            $attributes = $this->correctionService->adminScanExceptionAttributes(
                $user,
                $lockedAttendance,
                $validated['status'],
                $validated['override_reason'],
            );

            $before = $this->correctionService->auditState($lockedAttendance);

            $lockedAttendance->update($attributes);
            $this->cancelPendingNotifications($lockedAttendance);

            $this->logAudit(
                'admin_scan_exception_attendance',
                'Attendance',
                $lockedAttendance->id,
                $before,
                $this->correctionService->auditState($lockedAttendance),
                $user->id
            );
        });

        $studentName = Student::withTrashed()->whereKey($attendance->student_id)->value('name') ?? 'Siswa';

        return redirect()->back()->with('success', "Pengecualian scan untuk {$studentName} berhasil disimpan.");
    }

    public function recordScanReason(Request $request, Attendance $attendance): RedirectResponse
    {
        $validated = $request->validate([
            'override_reason' => ['required', 'string', 'max:500'],
        ]);

        $user = Auth::user();
        $before = $this->correctionService->auditState($attendance);

        $attendance->update($this->correctionService->scanReasonAttributes(
            $user,
            $attendance,
            $validated['override_reason'],
        ));

        $this->logAudit(
            'record_scan_reason',
            'Attendance',
            $attendance->id,
            $before,
            $this->correctionService->auditState($attendance)
        );

        $studentName = Student::withTrashed()->whereKey($attendance->student_id)->value('name') ?? 'Siswa';

        return redirect()->back()->with('success', "Alasan scan tidak lengkap untuk {$studentName} berhasil disimpan.");
    }

    public function storeManual(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'student_id' => ['required', 'exists:students,id'],
            'school_date' => ['required', 'date'],
            'status' => ['required', 'string', 'in:'.implode(',', AttendanceCorrectionService::MANUAL_STATUSES)],
            'override_reason' => ['required', 'string', 'max:500'],
        ]);

        $user = Auth::user();
        $targetStudent = Student::findOrFail($validated['student_id']);

        DB::transaction(function () use ($validated, $user, $targetStudent): void {
            $attendance = Attendance::where('student_id', $validated['student_id'])
                ->whereDate('school_date', $validated['school_date'])
                ->lockForUpdate()
                ->first();

            $before = $attendance ? $this->correctionService->auditState($attendance) : null;

            $attributes = $this->correctionService->manualAttributes(
                $user,
                $targetStudent,
                $attendance,
                $validated['status'],
                $validated['override_reason'],
            );

            if ($attendance) {
                $attendance->update($attributes);
                $record = $attendance;
            } else {
                $record = Attendance::create(array_merge([
                    'student_id' => $validated['student_id'],
                    'school_date' => $validated['school_date'],
                ], $attributes));
            }

            $this->cancelPendingNotifications($record);

            $this->logAudit(
                $attendance ? 'update_manual_attendance' : 'create_manual_attendance',
                'Attendance',
                $record->id,
                $before,
                $this->correctionService->auditState($record)
            );
        });

        return redirect()->back()->with('success', 'Catatan absensi manual berhasil disimpan.');
    }

    /**
     * Ekspor Data Absensi Siswa ke format XLSX atau CSV tanpa menyertakan rahasia (PRD FR-19).
     */
    public function export(Request $request): BinaryFileResponse|StreamedResponse
    {
        $dateFrom = $request->input('date_from', Carbon::today('Asia/Jakarta')->toDateString());
        $dateTo = $request->input('date_to', $dateFrom);
        if ($request->filled('date')) {
            $dateFrom = $request->input('date');
            $dateTo = $dateFrom;
        }

        $classId = $request->input('class_id');
        $studentId = $request->input('student_id');
        $status = $request->input('status');
        $outStatus = $request->input('out_status');
        $format = $request->input('format', 'xlsx');

        if (Auth::user()?->isHomeroomTeacher()) {
            $classId = Auth::user()->class_id;
        }

        if ($classId && $studentId) {
            $studentInClass = Student::where('class_id', $classId)->where('id', $studentId)->exists();
            if (! $studentInClass) {
                $studentId = null;
            }
        }

        $query = Attendance::with(['student.schoolClass', 'operator'])
            ->orderBy('student_id');

        if ($request->filled('month')) {
            $month = $request->input('month');
            $startDate = Carbon::parse($month.'-01', 'Asia/Jakarta')->startOfMonth();
            $endDate = $startDate->copy()->endOfMonth();
            $query->whereBetween('school_date', [$startDate->toDateString(), $endDate->toDateString()]);
            $periodLabel = $month;
        } else {
            if ($dateFrom === $dateTo) {
                $query->whereDate('school_date', $dateFrom);
            } else {
                $query->whereBetween('school_date', [$dateFrom, $dateTo]);
            }
            $periodLabel = $dateFrom === $dateTo ? $dateFrom : "{$dateFrom}_sd_{$dateTo}";
        }

        if ($classId) {
            $query->whereHas('student', function ($q) use ($classId) {
                $q->where('class_id', $classId);
            });
        }

        if ($studentId) {
            $query->where('student_id', $studentId);
        }

        if ($status) {
            $query->where('status', $status);
        }

        if ($outStatus) {
            $query->where('check_out_status', $outStatus);
        }

        $records = $query->get();

        $headers = [
            'No',
            'Tanggal',
            'NIS',
            'Nama Siswa',
            'Kelas',
            'Jam Masuk',
            'Status Masuk',
            'Terlambat (Menit)',
            'Jam Pulang',
            'Status Pulang',
            'Pulang Cepat (Menit)',
            'Status Kehadiran',
            'Koreksi Operator',
            'Alasan Koreksi',
            'Nama Operator',
        ];

        $rows = [];
        $no = 1;
        foreach ($records as $item) {
            $rows[] = [
                $no++,
                $item->school_date->format('d/m/Y'),
                $item->student?->nis ?? '-',
                $item->student?->name ?? '-',
                $item->student?->schoolClass?->name ?? '-',
                $item->check_in_at ? $item->check_in_at->format('H:i:s') : '-',
                $item->check_in_status_label,
                $item->late_minutes_display ?? '-',
                $item->check_out_at ? $item->check_out_at->format('H:i:s') : '-',
                $item->check_out_status_label,
                $item->early_leave_minutes_display ?? '-',
                strtoupper($item->status_display_label),
                $item->is_manual_override ? 'Ya' : 'Tidak',
                $item->override_reason ?? '-',
                $item->operator?->name ?? '-',
            ];
        }

        $this->logAudit('export_attendance_record', 'Attendance', null, null, [
            'format' => $format,
            'period' => $periodLabel,
            'total_rows' => count($rows),
        ]);

        if ($format === 'xlsx') {
            $filename = "rekap_absensi_{$periodLabel}.xlsx";

            return SimpleXlsxExporter::download(
                $filename,
                $headers,
                $rows,
                'Rekap Presensi',
                [5, 12, 10, 25, 16, 10, 16, 12, 10, 16, 12, 16, 14, 24, 20]
            );
        }

        // Format CSV dengan sanitasi formula injection
        $sanitizedHeaders = array_map([SimpleXlsxExporter::class, 'sanitizeCellValue'], $headers);
        $sanitizedRows = array_map(function ($row) {
            return array_map([SimpleXlsxExporter::class, 'sanitizeCellValue'], $row);
        }, $rows);

        $filename = "rekap_absensi_{$periodLabel}.csv";
        $responseHeaders = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        return response()->stream(function () use ($sanitizedHeaders, $sanitizedRows) {
            $file = fopen('php://output', 'w');
            fwrite($file, "\xEF\xBB\xBF");
            fputcsv($file, $sanitizedHeaders);
            foreach ($sanitizedRows as $row) {
                fputcsv($file, $row);
            }
            fclose($file);
        }, 200, $responseHeaders);
    }

    /**
     * Tampilan Laporan Harian Per Siswa Tiap Bulan (Format Cetak / PDF & XLSX).
     */
    public function studentMonthlyReport(Request $request): View|BinaryFileResponse
    {
        $students = Student::with('schoolClass')
            ->where('active', true)
            ->when(Auth::user()?->isHomeroomTeacher() && Auth::user()->class_id, fn ($q) => $q->where('class_id', Auth::user()->class_id))
            ->orderBy('name')
            ->get();
        $studentId = $request->input('student_id', $students->first()?->id);
        $month = $request->input('month', Carbon::now('Asia/Jakarta')->format('Y-m'));

        $selectedStudent = $studentId ? Student::with('schoolClass')->findOrFail($studentId) : null;

        if (Auth::user()?->isHomeroomTeacher() && $selectedStudent && $selectedStudent->class_id !== Auth::user()->class_id) {
            abort(403, 'Akses ditolak: Anda hanya berwenang melihat laporan siswa pada kelas yang Anda ampu.');
        }

        $reportData = $this->reportService->getStudentMonthlyReport([
            'student_id' => $studentId,
            'month' => $month,
        ]);

        $selectedStudent = $reportData['selectedStudent'];
        $startDate = $reportData['startDate'];
        $dailyRows = $reportData['dailyRows'];
        $summary = $reportData['summary'];
        $percentage = $reportData['percentage'];

        if ($request->input('format') === 'xlsx') {
            $studentNis = $selectedStudent?->nis ?? '0';
            $headers = ['No', 'Tanggal', 'Hari', 'Jam Masuk', 'Telat (Menit)', 'Jam Pulang', 'PLG Cepat (Menit)', 'Keterangan'];
            $rows = [];
            foreach ($dailyRows as $r) {
                $rows[] = [
                    $r['day_number'],
                    $r['date']->format('d/m/Y'),
                    $r['day_name'],
                    $r['check_in_at'],
                    $r['late_minutes_display'] ?? '-',
                    $r['check_out_at'],
                    $r['early_leave_minutes_display'] ?? '-',
                    $r['note'] ?: '-',
                ];
            }

            return SimpleXlsxExporter::download(
                "laporan_presensi_{$studentNis}_{$month}.xlsx",
                $headers,
                $rows,
                'Presensi Siswa Bulanan',
                [5, 12, 14, 10, 12, 10, 14, 40]
            );
        }

        return view('reports.student_monthly', compact(
            'students',
            'selectedStudent',
            'month',
            'startDate',
            'dailyRows',
            'summary',
            'percentage'
        ));
    }

    /**
     * Tampilan Laporan Per Kelas Tiap Bulan (Format Cetak / PDF & XLSX).
     */
    public function classMonthlyReport(Request $request): View|BinaryFileResponse
    {
        if (Auth::user()?->isHomeroomTeacher()) {
            $classId = Auth::user()->class_id;
            if ($request->filled('class_id') && (int) $request->input('class_id') !== (int) $classId) {
                abort(403, 'Akses ditolak: Anda hanya berwenang melihat laporan untuk kelas yang Anda ampu.');
            }
        } else {
            $classId = $request->input('class_id', SchoolClass::orderBy('name')->first()?->id);
        }

        $classes = Auth::user()?->isHomeroomTeacher() && $classId
            ? SchoolClass::where('id', $classId)->get()
            : SchoolClass::orderBy('name')->get();

        $month = $request->input('month', Carbon::now('Asia/Jakarta')->format('Y-m'));

        $reportData = $this->reportService->getClassMonthlyReport([
            'class_id' => $classId,
            'month' => $month,
        ]);

        $selectedClass = $reportData['selectedClass'];
        $startDate = $reportData['startDate'];
        $effectiveDays = $reportData['effectiveDays'];
        $studentRows = $reportData['rows'];
        $summary = $reportData['summary'];
        $classAveragePct = $summary['percentage'];
        $classTotalHadir = $summary['hadir'];
        $classTotalTerlambat = $summary['terlambat'];
        $classTotalPulangCepat = $summary['pulang_cepat'];
        $classTotalIzin = $summary['izin'];
        $classTotalSakit = $summary['sakit'];
        $classTotalAlpa = $summary['alpa'];

        if ($request->input('format') === 'xlsx') {
            $className = $selectedClass?->name ?? 'Semua_Kelas';
            $headers = ['No', 'NIS', 'Nama Siswa', 'Kelas', 'Hadir', 'Telat', 'Pulang Cepat', 'Izin', 'Sakit', 'Alpa', 'Perlu Verifikasi', 'Total Hadir', '% Kehadiran'];
            $rows = [];
            foreach ($studentRows as $idx => $r) {
                $rows[] = [
                    $idx + 1,
                    $r['nis'],
                    $r['student_name'],
                    $selectedClass?->name ?? '-',
                    $r['hadir'],
                    $r['terlambat'],
                    $r['pulang_cepat'],
                    $r['izin'],
                    $r['sakit'],
                    $r['alpa'],
                    $r['perlu_tinjauan'] ?? 0,
                    $r['total_hadir'],
                    $r['percentage'].'%',
                ];
            }

            return SimpleXlsxExporter::download("rekap_kelas_{$className}_{$month}.xlsx", $headers, $rows, 'Rekap Presensi Kelas');
        }

        return view('reports.class_monthly', compact(
            'classes',
            'selectedClass',
            'month',
            'startDate',
            'effectiveDays',
            'studentRows',
            'classAveragePct',
            'classTotalHadir',
            'classTotalTerlambat',
            'classTotalPulangCepat',
            'classTotalIzin',
            'classTotalSakit',
            'classTotalAlpa'
        ));
    }

    /**
     * Tampilan Laporan Bulanan Umum (Format Cetak / PDF & XLSX).
     */
    public function monthlyReport(Request $request): View|BinaryFileResponse
    {
        $month = $request->input('month', Carbon::now('Asia/Jakarta')->format('Y-m'));

        if (Auth::user()?->isHomeroomTeacher()) {
            $classId = Auth::user()->class_id;
            $classes = SchoolClass::where('id', $classId)->get();
        } else {
            $classId = $request->input('class_id');
            $classes = SchoolClass::orderBy('name')->get();
        }

        $reportData = $this->reportService->getMonthlyReport([
            'class_id' => $classId,
            'month' => $month,
        ]);

        $selectedClass = $reportData['selectedClass'];
        $startDate = $reportData['startDate'];
        $effectiveDays = $reportData['effectiveDays'];
        $rows = $reportData['rows'];
        $summary = $reportData['summary'];
        $totalStudents = $summary['total_students'];
        $averagePct = $summary['percentage'];
        $totalHadirAll = $summary['hadir'];
        $totalTerlambatAll = $summary['terlambat'];
        $totalPulangCepatAll = $summary['pulang_cepat'];
        $totalIzinAll = $summary['izin'];
        $totalSakitAll = $summary['sakit'];
        $totalAlpaAll = $summary['alpa'];

        if ($request->input('format') === 'xlsx') {
            $headers = ['No', 'NIS', 'Nama Siswa', 'Kelas', 'Hadir', 'Telat', 'Pulang Cepat', 'Izin', 'Sakit', 'Alpa', 'Perlu Verifikasi', 'Total Hadir', '% Kehadiran'];
            $exportRows = [];
            foreach ($rows as $idx => $r) {
                $exportRows[] = [
                    $idx + 1,
                    $r['nis'],
                    $r['student_name'],
                    $r['class_name'],
                    $r['hadir'],
                    $r['terlambat'],
                    $r['pulang_cepat'],
                    $r['izin'],
                    $r['sakit'],
                    $r['alpa'],
                    $r['perlu_tinjauan'] ?? 0,
                    $r['total_hadir'],
                    $r['percentage'].'%',
                ];
            }

            return SimpleXlsxExporter::download("rekap_bulanan_{$month}.xlsx", $headers, $exportRows, 'Rekap Bulanan');
        }

        return view('reports.monthly', compact(
            'month', 'classes', 'selectedClass', 'startDate', 'effectiveDays',
            'rows', 'totalStudents', 'averagePct',
            'totalHadirAll', 'totalTerlambatAll', 'totalPulangCepatAll', 'totalIzinAll', 'totalSakitAll', 'totalAlpaAll'
        ));
    }

    private function cancelPendingNotifications(Attendance $attendance): void
    {
        $this->whatsAppNotificationService->cancelPendingForAttendance(
            $attendance,
            'Dibatalkan: presensi telah dikoreksi manual oleh operator.'
        );
    }
}
