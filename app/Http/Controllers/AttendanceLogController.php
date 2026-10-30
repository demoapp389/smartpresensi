<?php

namespace App\Http\Controllers;

use App\Concerns\LogsAuditTrail;
use App\Http\Controllers\Concerns\HasHybridFilters;
use App\Models\AttendanceLog;
use App\Models\DeviceUser;
use App\Models\FingerprintDevice;
use App\Models\Student;
use App\Services\AttendanceLogProcessingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AttendanceLogController extends Controller
{
    use HasHybridFilters;
    use LogsAuditTrail;

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

        // Data dikirim penuh (tanpa paginate): tabel memakai DataTables di sisi
        // klien, sehingga filter tanggal/perangkat/status tetap ditangani server.
        $logs = $query->get();
        $devices = FingerprintDevice::all();

        $dateInput = in_array($date, ['', 'all'], true) ? '' : $date;
        $deviceId = $request->input('device_id', '');
        $status = $requestedStatus;
        $isFiltered = $rawDate !== null || $deviceId !== '' || $status !== '';

        $timeReviewCount = AttendanceLog::query()
            ->where('status', AttendanceLog::STATUS_NEEDS_REVIEW)
            ->count();

        return view('logs.raw', compact('logs', 'devices', 'dateInput', 'deviceId', 'status', 'isFiltered', 'timeReviewCount'));
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

    public function unmappedIndex(Request $request): View
    {
        $query = AttendanceLog::with('device')
            ->where('status', 'unmapped')
            ->latest('occurred_at');

        $query = $this->applyFilters($query, $request);

        // Data dikirim penuh (tanpa paginate): tabel memakai DataTables di sisi klien.
        $unmappedLogs = $query->get();

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
