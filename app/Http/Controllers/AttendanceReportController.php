<?php

namespace App\Http\Controllers;

use App\Concerns\LogsAuditTrail;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Services\AttendanceReportService;
use App\Services\SimpleXlsxExporter;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AttendanceReportController extends Controller
{
    use LogsAuditTrail;

    public function __construct(
        protected AttendanceReportService $reportService
    ) {}

    /**
     * Halaman Hub Utama Laporan Presensi (Preview & Filter Dinamis).
     */
    public function index(Request $request): View
    {
        $type = $request->input('type', 'daily');
        if (! in_array($type, ['daily', 'monthly', 'class', 'student'], true)) {
            $type = 'daily';
        }

        $user = Auth::user();
        $isHomeroom = $user?->isHomeroomTeacher() ?? false;
        $userClassId = $user?->class_id;

        // Khusus wali kelas, sederhanakan: laporan bulanan langsung menggunakan format rekap kelas 1 halaman A4
        if ($isHomeroom && $type === 'monthly') {
            $type = 'class';
        }

        $date = $request->input('date', Carbon::today('Asia/Jakarta')->toDateString());
        $month = $request->input('month', Carbon::parse($date)->format('Y-m'));
        $classId = ($type === 'monthly') ? null : ($isHomeroom ? $userClassId : $request->input('class_id'));
        $studentId = $type === 'daily' ? null : $request->input('student_id');
        $status = $request->input('status');

        $classes = $isHomeroom && $userClassId
            ? SchoolClass::where('id', $userClassId)->get()
            : SchoolClass::orderBy('name')->get();

        $allStudentsForFilter = Student::with('schoolClass')
            ->where('active', true)
            ->when($isHomeroom && $userClassId, fn ($q) => $q->where('class_id', $userClassId))
            ->orderBy('name')
            ->get();

        $studentsList = $allStudentsForFilter->map(fn ($s) => [
            'id' => (string) $s->id,
            'name' => $s->name,
            'class_id' => (string) ($s->class_id ?? ''),
            'class_name' => $s->schoolClass?->name ?? 'Tanpa Kelas',
        ])->values()->all();

        $students = $allStudentsForFilter
            ->when($classId && ! $isHomeroom, fn ($coll) => $coll->where('class_id', $classId))
            ->values();

        // Filter siswa hanya berlaku bila siswa termasuk dalam daftar (kelas) yang
        // sedang difilter, agar tidak menghasilkan laporan kosong saat kelas diubah.
        if ($studentId && ! $students->contains(fn ($s) => (int) $s->id === (int) $studentId)) {
            $studentId = null;
        }

        // Default studentId jika mode student dan belum terpilih
        if ($type === 'student' && ! $studentId && $students->isNotEmpty()) {
            $studentId = $students->first()->id;
        }

        // Default classId jika mode class dan belum terpilih
        if ($type === 'class' && ! $classId && $classes->isNotEmpty()) {
            $classId = $classes->first()->id;
        }

        $filters = [
            'date' => $date,
            'month' => $month,
            'class_id' => $classId,
            'student_id' => $studentId,
            'status' => $status,
        ];

        // Data dikirim penuh (tanpa paginate): tabel memakai DataTables di sisi
        // klien. Volume tetap terbatas (per tanggal / per bulan / satu siswa).
        $reportData = match ($type) {
            'monthly' => $this->reportService->getMonthlyReport($filters, 0),
            'class' => $this->reportService->getClassMonthlyReport($filters, 0),
            'student' => $this->reportService->getStudentMonthlyReport($filters),
            default => $this->reportService->getDailyReport($filters, 0),
        };

        // Parameter aktif untuk tombol Cetak/PDF & Ekspor Excel di header card.
        $activeReportParams = array_filter([
            'type' => $type,
            'date' => $type === 'daily' ? $date : null,
            'month' => $type !== 'daily' ? $month : null,
            'class_id' => $classId,
            'student_id' => $studentId,
            'status' => $status,
        ], fn ($val) => ! is_null($val) && $val !== '');

        // Tombol Reset hanya muncul bila pengguna mengubah filter dari nilai
        // default (mis. kembali dari halaman cetak yang membawa ?type & tanggal bawaan).
        $today = Carbon::today('Asia/Jakarta')->toDateString();
        $currentMonth = Carbon::now('Asia/Jakarta')->format('Y-m');
        $hasActiveFilter = match ($type) {
            'daily' => $request->filled('class_id')
                || $request->filled('status')
                || ($request->filled('date') && $request->input('date') !== $today),
            'monthly' => $request->filled('month') && $request->input('month') !== $currentMonth,
            'student' => $request->filled('class_id')
                || ($request->filled('month') && $request->input('month') !== $currentMonth),
            default => $request->filled('month') && $request->input('month') !== $currentMonth,
        };

        return view('reports.index', compact(
            'type',
            'date',
            'month',
            'classId',
            'studentId',
            'status',
            'classes',
            'students',
            'studentsList',
            'reportData',
            'isHomeroom',
            'activeReportParams',
            'hasActiveFilter'
        ));
    }

    /**
     * Tampilan Siap Cetak A4 / PDF dengan Kop Sekolah dan Pengesahan.
     */
    public function print(Request $request): View
    {
        $type = $request->input('type', 'daily');
        if (! in_array($type, ['daily', 'monthly', 'class', 'student'], true)) {
            $type = 'daily';
        }

        $user = Auth::user();
        $isHomeroom = $user?->isHomeroomTeacher() ?? false;
        $userClassId = $user?->class_id;

        if ($isHomeroom && $type === 'monthly') {
            $type = 'class';
        }

        $date = $request->input('date', Carbon::today('Asia/Jakarta')->toDateString());
        $month = $request->input('month', Carbon::parse($date)->format('Y-m'));
        $classId = ($type === 'monthly') ? null : ($isHomeroom ? $userClassId : $request->input('class_id'));
        $studentId = $type === 'daily' ? null : $request->input('student_id');
        $status = $request->input('status');

        if ($type === 'student' && ! $studentId) {
            $firstStudent = Student::where('active', true)
                ->when($isHomeroom && $userClassId, fn ($q) => $q->where('class_id', $userClassId))
                ->first();
            $studentId = $firstStudent?->id;
        }

        if ($type === 'class' && ! $classId) {
            $firstClass = $isHomeroom && $userClassId
                ? SchoolClass::find($userClassId)
                : SchoolClass::orderBy('name')->first();
            $classId = $firstClass?->id;
        }

        $this->ensureStudentReportAccess($studentId, $isHomeroom, $userClassId);

        $filters = [
            'date' => $date,
            'month' => $month,
            'class_id' => $classId,
            'student_id' => $studentId,
            'status' => $status,
        ];

        // Cetak memuat seluruh data (tanpa pagination)
        $reportData = match ($type) {
            'monthly' => $this->reportService->getMonthlyReport($filters, 0),
            'class' => $this->reportService->getClassMonthlyReport($filters, 0),
            'student' => $this->reportService->getStudentMonthlyReport($filters),
            default => $this->reportService->getDailyReport($filters, 0),
        };

        return view('reports.print', compact('reportData', 'type', 'filters'));
    }

    /**
     * Ekspor Data Laporan ke File Excel (.xlsx) dengan Sanitasi & Audit Log.
     */
    public function export(Request $request): BinaryFileResponse
    {
        $type = $request->input('type', 'daily');
        if (! in_array($type, ['daily', 'monthly', 'class', 'student'], true)) {
            $type = 'daily';
        }

        $user = Auth::user();
        $isHomeroom = $user?->isHomeroomTeacher() ?? false;
        $userClassId = $user?->class_id;

        if ($isHomeroom && $type === 'monthly') {
            $type = 'class';
        }

        $date = $request->input('date', Carbon::today('Asia/Jakarta')->toDateString());
        $month = $request->input('month', Carbon::parse($date)->format('Y-m'));
        $classId = ($type === 'monthly') ? null : ($isHomeroom ? $userClassId : $request->input('class_id'));
        $studentId = $type === 'daily' ? null : $request->input('student_id');
        $status = $request->input('status');

        if ($type === 'student' && ! $studentId) {
            $firstStudent = Student::where('active', true)
                ->when($isHomeroom && $userClassId, fn ($q) => $q->where('class_id', $userClassId))
                ->first();
            $studentId = $firstStudent?->id;
        }

        if ($type === 'class' && ! $classId) {
            $firstClass = $isHomeroom && $userClassId
                ? SchoolClass::find($userClassId)
                : SchoolClass::orderBy('name')->first();
            $classId = $firstClass?->id;
        }

        $this->ensureStudentReportAccess($studentId, $isHomeroom, $userClassId);

        $filters = [
            'date' => $date,
            'month' => $month,
            'class_id' => $classId,
            'student_id' => $studentId,
            'status' => $status,
        ];

        // Ambil data utuh tanpa pagination
        $reportData = match ($type) {
            'monthly' => $this->reportService->getMonthlyReport($filters, 0),
            'class' => $this->reportService->getClassMonthlyReport($filters, 0),
            'student' => $this->reportService->getStudentMonthlyReport($filters),
            default => $this->reportService->getDailyReport($filters, 0),
        };

        // Buat file Excel sesuai tipe laporan (murni data tabel tanpa kartu ringkasan)
        $headers = [];
        $rows = [];
        $columnWidths = [];
        $sheetTitle = 'Rekap Laporan';
        $filename = "laporan_{$type}_".now()->format('Ymd_His').'.xlsx';

        if ($type === 'daily') {
            $filename = "laporan_harian_{$date}.xlsx";
            $sheetTitle = 'Laporan Harian';
            $headers = ['No', 'Tanggal', 'NIS', 'Nama Siswa', 'Kelas', 'Jam Masuk', 'Telat (Menit)', 'Jam Pulang', 'PLG Cepat (Menit)', 'Status', 'Keterangan'];
            $columnWidths = [5, 12, 10, 25, 16, 10, 12, 10, 12, 14, 40];
            foreach ($reportData['rows'] as $r) {
                $rows[] = [
                    $r['no'],
                    $r['school_date'] instanceof Carbon ? $r['school_date']->format('d/m/Y') : (string) $r['school_date'],
                    $r['nis'],
                    $r['student_name'],
                    $r['class_name'],
                    $r['check_in_at'],
                    $r['late_minutes_display'] ?? '-',
                    $r['check_out_at'],
                    $r['early_leave_minutes_display'] ?? '-',
                    strtoupper($r['status']),
                    $r['note'],
                ];
            }
        } elseif ($type === 'monthly') {
            $filename = "laporan_bulanan_{$month}.xlsx";
            $sheetTitle = 'Laporan Bulanan';
            $headers = ['No', 'NIS', 'Nama Siswa', 'Kelas', 'Hadir', 'Telat', 'Pulang Cepat', 'Izin', 'Sakit', 'Alpa', 'Perlu Verifikasi', 'Total Hadir', '% Kehadiran'];
            foreach ($reportData['rows'] as $r) {
                $rows[] = [
                    $r['no'],
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
        } elseif ($type === 'class') {
            $className = $reportData['selectedClass']?->name ?? 'Kelas';
            $filename = "laporan_kelas_{$className}_{$month}.xlsx";
            $sheetTitle = 'Rekap Kelas';
            $headers = ['No', 'NIS', 'Nama Siswa', 'Kelas', 'Hadir', 'Telat', 'Pulang Cepat', 'Izin', 'Sakit', 'Alpa', 'Perlu Verifikasi', 'Total Hadir', '% Kehadiran'];
            foreach ($reportData['rows'] as $r) {
                $rows[] = [
                    $r['no'],
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
        } elseif ($type === 'student') {
            $nis = $reportData['selectedStudent']?->nis ?? '0';
            $filename = "laporan_siswa_{$nis}_{$month}.xlsx";
            $sheetTitle = 'Presensi Siswa';
            $headers = ['No', 'Tanggal', 'Hari', 'Jam Masuk', 'Telat (Menit)', 'Jam Pulang', 'PLG Cepat (Menit)', 'Keterangan'];
            $columnWidths = [5, 12, 14, 10, 12, 10, 14, 40];
            foreach ($reportData['dailyRows'] as $r) {
                $rows[] = [
                    $r['day_number'],
                    $r['date_formatted'],
                    $r['day_name'],
                    $r['check_in_at'],
                    $r['late_minutes_display'] ?? '-',
                    $r['check_out_at'],
                    $r['early_leave_minutes_display'] ?? '-',
                    $r['note'] ?: '-',
                ];
            }
        }

        // Audit Log Aktivitas Ekspor
        $this->logAudit('export_attendance_report', 'AttendanceReport', null, null, [
            'report_type' => $type,
            'format' => 'xlsx',
            'filename' => $filename,
            'filters' => $filters,
            'total_rows' => count($rows),
        ]);

        return SimpleXlsxExporter::download($filename, $headers, $rows, $sheetTitle, $columnWidths);
    }

    private function ensureStudentReportAccess(
        int|string|null $studentId,
        bool $isHomeroom,
        ?int $userClassId
    ): void {
        if (! $studentId || ! $isHomeroom || ! $userClassId) {
            return;
        }

        $studentClassId = Student::whereKey($studentId)->value('class_id');

        if ($studentClassId !== null && (int) $studentClassId !== (int) $userClassId) {
            abort(403, 'Akses ditolak: Anda hanya berwenang melihat laporan siswa pada kelas yang Anda ampu.');
        }
    }
}
