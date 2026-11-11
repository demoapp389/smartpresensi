<?php

namespace App\Http\Controllers;

use App\Concerns\LogsAuditTrail;
use App\Http\Controllers\Concerns\HasHybridFilters;
use App\Models\AttendanceLog;
use App\Models\DeviceUser;
use App\Models\FingerprintDevice;
use App\Models\Student;
use App\Services\AttendanceLogProcessingService;
use App\Services\AttendancePeriodService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AttendanceLogController extends Controller
{
    use HasHybridFilters;
    use LogsAuditTrail;

    public function __construct(
        protected AttendancePeriodService $periodService
    ) {}

    protected function getFilterConfig(): array
    {
        return [
            'search' => [
                'type' => 'text',
                'column' => 'machine_user_id',
                'operator' => 'like',
            ],
            'device_id' => [
                'type' => 'select',
                'column' => 'device_id',
                'operator' => '=',
            ],
            'status' => [
                'type' => 'select',
                'column' => 'status',
                'operator' => '=',
            ],
        ];
    }

    public function rawIndex(Request $request): View
    {
        $query = AttendanceLog::with('device')->latest('occurred_at');
        $query = $this->periodService->applyTo($query, 'occurred_at');

        // Filter tanggal tunggal: default hari ini (zona Asia/Jakarta).
        // Antrean tinjauan waktu harus menampilkan semua tanggal agar log historis
        // tidak tersembunyi oleh filter tanggal default.
        $rawDate = $request->query('date');
        $requestedStatus = (string) $request->input('status', '');
        $date = $rawDate ?? (
            $requestedStatus === AttendanceLog::STATUS_NEEDS_REVIEW
                ? 'all'
                : now('Asia/Jakarta')->toDateString()
        );

        if ($date !== '' && $date !== 'all') {
            $query->whereDate('occurred_at', $date);
        }

        $query = $this->applyFilters($query, $request);

        // Paginasi server: filter tanggal/perangkat/status ditangani server dan
        // hasil dibatasi per halaman agar antrean log historis tidak dimuat penuh.
        $logs = $query->paginate($this->resolvePerPage(50))->withQueryString();
        $devices = FingerprintDevice::all();

        $dateInput = in_array($date, ['', 'all'], true) ? '' : $date;
        $deviceId = $request->input('device_id', '');
        $status = $requestedStatus;
        $search = (string) $request->input('search', '');
        $isFiltered = $rawDate !== null || $deviceId !== '' || $status !== '' || $search !== '';

        $timeReviewCount = $this->periodService
            ->applyTo(AttendanceLog::query(), 'occurred_at')
            ->where('status', AttendanceLog::STATUS_NEEDS_REVIEW)
            ->count();

        return view('logs.raw', compact('logs', 'devices', 'dateInput', 'deviceId', 'status', 'search', 'isFiltered', 'timeReviewCount'));
    }

    public function retry(AttendanceLog $log, AttendanceLogProcessingService $processor): RedirectResponse
    {
        if (! Auth::user()?->isOperator()) {
            abort(403);
        }

        $before = [
            'status' => $log->status,
            'processing_attempts' => $log->processing_attempts,
            'processing_note' => $log->processing_note,
        ];

        if (! in_array($log->status, ['failed', 'unprocessed'], true)) {
            return back()->withErrors(['log' => 'Hanya log berstatus gagal atau pending yang dapat diproses ulang.']);
        }

        $processor->processLog($log, ['failed', 'unprocessed']);
        $log->refresh();

        $this->logAudit(
            'retry_attendance_log',
            'AttendanceLog',
            $log->id,
            $before,
            [
                'status' => $log->status,
                'processing_attempts' => $log->processing_attempts,
                'processing_note' => $log->processing_note,
                'next_retry_at' => $log->next_retry_at?->format('Y-m-d H:i:s'),
            ],
            Auth::id()
        );

        if ($log->status === 'processed') {
            return back()->with('success', "Raw log #{$log->id} berhasil diproses ulang.");
        }

        return back()->withErrors([
            'log' => "Raw log #{$log->id} masih gagal diproses: ".($log->processing_note ?? 'lihat catatan proses.'),
        ]);
    }

    public function approveTimeReview(
        Request $request,
        AttendanceLog $log,
        AttendanceLogProcessingService $processor,
    ): RedirectResponse {
        $validated = $request->validate([
            'review_note' => ['required', 'string', 'max:500'],
        ]);

        $attendance = $processor->approveTimeReview(
            $log,
            (int) Auth::id(),
            $validated['review_note'],
        );

        if ($attendance === null) {
            return back()->withErrors([
                'log' => 'Scan tidak dapat disetujui karena statusnya sudah berubah atau di luar jendela absensi.',
            ]);
        }

        return back()->with('success', "Scan #{$log->id} disetujui dan diterapkan ke presensi.");
    }

    public function rejectTimeReview(
        Request $request,
        AttendanceLog $log,
        AttendanceLogProcessingService $processor,
    ): RedirectResponse {
        $validated = $request->validate([
            'review_note' => ['required', 'string', 'max:500'],
        ]);

        if (! $processor->rejectTimeReview($log, (int) Auth::id(), $validated['review_note'])) {
            return back()->withErrors([
                'log' => 'Scan tidak dapat ditolak karena statusnya sudah berubah.',
            ]);
        }

        return back()->with('success', "Scan #{$log->id} ditolak dan tidak akan mengubah presensi.");
    }

    public function bulkApproveTimeReview(
        Request $request,
        AttendanceLogProcessingService $processor,
    ): RedirectResponse {
        return $this->bulkReviewTime($request, $processor, true);
    }

    public function bulkRejectTimeReview(
        Request $request,
        AttendanceLogProcessingService $processor,
    ): RedirectResponse {
        return $this->bulkReviewTime($request, $processor, false);
    }

    private function bulkReviewTime(
        Request $request,
        AttendanceLogProcessingService $processor,
        bool $approve,
    ): RedirectResponse {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['integer', 'distinct'],
            'review_note' => ['required', 'string', 'max:500'],
        ], [
            'ids.required' => 'Pilih minimal satu scan untuk diproses.',
            'ids.max' => 'Maksimal 200 scan dapat diproses sekaligus.',
            'review_note.required' => 'Alasan persetujuan/penolakan wajib diisi.',
            'review_note.max' => 'Alasan maksimal 500 karakter.',
        ]);

        $result = $processor->bulkReviewTime(
            array_map('intval', $validated['ids']),
            (int) Auth::id(),
            $validated['review_note'],
            $approve,
        );

        $this->logAudit(
            $approve ? 'bulk_approve_attendance_log_time_review' : 'bulk_reject_attendance_log_time_review',
            'AttendanceLog',
            null,
            null,
            [
                'requested_ids' => array_map('intval', $validated['ids']),
                'result' => $result,
                'review_note' => $validated['review_note'],
            ],
        );

        $action = $approve ? 'disetujui' : 'ditolak';
        $countKey = $approve ? 'approved' : 'rejected';
        $summary = "{$result[$countKey]} scan {$action}.";
        $parts = [];

        if ($result['skipped'] > 0) {
            $parts[] = "{$result['skipped']} dilewati karena status sudah berubah";
        }

        if ($result['failed'] > 0) {
            $parts[] = "{$result['failed']} gagal diproses";
        }

        if ($parts !== []) {
            $summary .= ' '.implode(', ', $parts).'.';
        }

        return back()->with('success', $summary);
    }

    public function unmappedIndex(Request $request): View
    {
        $query = AttendanceLog::with('device')
            ->where('status', 'unmapped')
            ->latest('occurred_at');
        $query = $this->periodService->applyTo($query, 'occurred_at');

        $query = $this->applyFilters($query, $request);

        // Paginasi server agar daftar PIN yang belum dipetakan tidak tumbuh tanpa batas.
        $unmappedLogs = $query->paginate($this->resolvePerPage(50))->withQueryString();

        $students = Student::with('schoolClass')
            ->where('active', true)
            ->orderBy('name')
            ->get();

        $devices = FingerprintDevice::orderBy('name')->get();
        $deviceId = $request->input('device_id', '');
        $search = $request->input('search', '');

        return view('logs.unmapped', compact('unmappedLogs', 'students', 'devices', 'deviceId', 'search'));
    }

    public function mapLog(Request $request, AttendanceLogProcessingService $processor): RedirectResponse
    {
        $validated = $request->validate([
            'device_id' => ['required', 'exists:fingerprint_devices,id'],
            'machine_user_id' => ['required', 'string'],
            'student_id' => ['required', 'exists:students,id'],
        ]);

        // Cek duplicate PIN pada device yang sama (FR-06)
        $existingPin = DeviceUser::where('device_id', $validated['device_id'])
            ->where('machine_user_id', $validated['machine_user_id'])
            ->first();

        if ($existingPin && $existingPin->student_id != $validated['student_id']) {
            return back()->withErrors(['PIN Mesin ini sudah terpetakan ke siswa lain!']);
        }

        // Simpan / update mapping DeviceUser
        DeviceUser::updateOrCreate(
            ['device_id' => $validated['device_id'], 'student_id' => $validated['student_id']],
            ['machine_user_id' => $validated['machine_user_id']]
        );

        $replayed = $processor->processLogsForMapping(
            (int) $validated['device_id'],
            (string) $validated['machine_user_id']
        );

        $processedCount = $replayed['processed'];
        $reviewCount = $replayed['review'];
        $failedCount = $replayed['failed'];

        $student = Student::find($validated['student_id']);

        $this->logAudit('map_unmapped_log', 'DeviceUser', $validated['student_id'], ['status' => 'unmapped', 'machine_user_id' => $validated['machine_user_id']], ['student_id' => $student->id, 'student_name' => $student->name, 'processed_logs' => $processedCount, 'time_review_logs' => $reviewCount, 'failed_logs' => $failedCount]);

        $message = "PIN {$validated['machine_user_id']} berhasil dipetakan ke {$student->name}. {$processedCount} log diproses menjadi absensi.";

        if ($reviewCount > 0) {
            $message .= " {$reviewCount} log perlu tinjauan waktu.";
        }

        if ($failedCount > 0) {
            $message .= " {$failedCount} log belum berhasil diproses.";
        }

        return back()->with('success', $message);
    }
}
