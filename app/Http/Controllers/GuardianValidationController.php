<?php

namespace App\Http\Controllers;

use App\Jobs\BulkValidateGuardianPhones;
use App\Models\Guardian;
use App\Models\GuardianPhoneValidationLog;
use App\Services\WhatsApp\FonnteNumberValidationService;
use App\Support\TaskProgressReporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class GuardianValidationController extends Controller
{
    public function __construct(
        protected FonnteNumberValidationService $validationService
    ) {}

    /**
     * Halaman validasi nomor WhatsApp wali (daftar + filter + validasi massal).
     */
    public function index(Request $request): View
    {
        $status = $request->input('status');
        $search = $request->input('search');

        $query = Guardian::with('student.schoolClass')->latest('id');

        if ($status) {
            $query->where('wa_status', $status);
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('wa_number', 'like', "%{$search}%")
                    ->orWhereHas('student', function ($s) use ($search) {
                        $s->where('name', 'like', "%{$search}%")
                            ->orWhere('nis', 'like', "%{$search}%");
                    });
            });
        }

        $guardians = $query->get();

        $activeGuardians = Guardian::where('active', true);

        $stats = [
            'total' => (clone $activeGuardians)->count(),
            'valid' => (clone $activeGuardians)->where('wa_status', 'valid')->count(),
            'pending' => (clone $activeGuardians)->where(function ($q) {
                $q->where('wa_status', 'pending')->orWhereNull('wa_status');
            })->count(),
            'invalid' => (clone $activeGuardians)->where('wa_status', 'invalid')->count(),
            'unknown' => (clone $activeGuardians)->where('wa_status', 'unknown')->count(),
        ];

        return view('guardians.validation', compact('guardians', 'stats', 'status', 'search'));
    }

    /**
     * Dapatkan ringkasan statistik validasi nomor WhatsApp wali.
     */
    public function stats(): JsonResponse
    {
        $base = Guardian::where('active', true);

        return response()->json([
            'total' => (clone $base)->count(),
            'valid' => (clone $base)->where('wa_status', 'valid')->count(),
            'pending' => (clone $base)->where(function ($q) {
                $q->where('wa_status', 'pending')->orWhereNull('wa_status');
            })->count(),
            'invalid' => (clone $base)->where('wa_status', 'invalid')->count(),
            'unknown' => (clone $base)->where('wa_status', 'unknown')->count(),
        ]);
    }

    /**
     * Validasi satu nomor wali murid on-demand.
     */
    public function validateSingle(Request $request, Guardian $guardian): JsonResponse|RedirectResponse
    {
        $actor = Auth::user()?->username ?? 'operator';
        $result = $this->validationService->validateGuardian($guardian, "manual_{$actor}");

        $statusLabel = match ($result['status']) {
            'valid' => 'Valid (Terdaftar di WhatsApp)',
            'invalid' => 'Invalid (Tidak Terdaftar / Format Salah)',
            default => 'Unknown (Gagal Terhubung ke Provider)',
        };

        $message = "Validasi nomor {$guardian->name} ({$guardian->wa_number}): {$statusLabel}.";
        if (! empty($result['error'])) {
            $message .= " Keterangan: {$result['error']}";
        }

        if ($request->wantsJson()) {
            return response()->json([
                'success' => $result['status'] === 'valid',
                'status' => $result['status'],
                'message' => $message,
                'guardian' => $guardian->fresh(),
            ]);
        }

        $flashKey = $result['status'] === 'valid' ? 'success' : ($result['status'] === 'invalid' ? 'error' : 'warning');

        return back()->with($flashKey, $message);
    }

    /**
     * Validasi nomor wali secara massal (batch berurutan tanpa concurrent requests).
     */
    public function bulkValidate(Request $request): JsonResponse|RedirectResponse
    {
        $scope = $request->input('scope', 'pending');
        $limit = min((int) $request->input('limit', 50), 100);
        $isAsync = $request->boolean('async', false);

        $query = Guardian::where('active', true);

        if ($scope === 'pending') {
            $query->where(function ($q) {
                $q->where('wa_status', 'pending')->orWhereNull('wa_status');
            });
        } elseif ($scope === 'invalid') {
            $query->where('wa_status', 'invalid');
        } elseif ($scope === 'unknown') {
            $query->where('wa_status', 'unknown');
        }

        $totalMatching = (clone $query)->count();

        // Mode Asinkron: satu task dengan progress bar, bukan N job terpisah.
        if ($isAsync) {
            $guardianIds = $query->limit($limit)->pluck('id')->all();

            if ($guardianIds === []) {
                $msg = 'Tidak ada nomor wali yang perlu divalidasi pada kriteria ini.';

                return $request->wantsJson()
                    ? response()->json(['success' => true, 'uuid' => null, 'message' => $msg])
                    : back()->with('info', $msg);
            }

            $reporter = TaskProgressReporter::start(
                'guardians.bulk-validate',
                'Validasi Nomor WhatsApp Wali',
                count($guardianIds),
                Auth::id()
            );

            BulkValidateGuardianPhones::dispatch(
                $guardianIds,
                Auth::user()?->username ?? 'operator',
                $reporter->uuid()
            );

            $msg = 'Sebanyak '.count($guardianIds).' nomor wali sedang divalidasi di latar belakang.';

            if ($request->wantsJson()) {
                return response()->json([
                    'success' => true,
                    'uuid' => $reporter->uuid(),
                    'progress_token' => $reporter->signature(),
                    'total' => count($guardianIds),
                    'remaining' => max(0, $totalMatching - count($guardianIds)),
                    'message' => $msg,
                ], 202);
            }

            return back()->with('success', $msg);
        }

        // Mode Batch Sekuensial Langsung
        $guardians = $query->limit($limit)->get();

        if ($guardians->isEmpty()) {
            $msg = 'Tidak ada nomor wali yang perlu divalidasi pada kriteria ini.';

            return $request->wantsJson()
                ? response()->json(['success' => true, 'processed' => 0, 'remaining' => 0, 'message' => $msg])
                : back()->with('info', $msg);
        }

        $phoneMap = [];
        foreach ($guardians as $g) {
            $phoneMap[$g->wa_number] = $g;
        }

        // Jalankan batch validation berurutan
        $results = $this->validationService->validateBatch(array_keys($phoneMap));

        $validCount = 0;
        $invalidCount = 0;
        $unknownCount = 0;
        $actor = Auth::user()?->username ?? 'operator';
        $now = now();

        // PERF-05: kumpulkan perubahan lalu simpan massal (upsert + insert),
        // bukan satu query UPDATE/INSERT per wali.
        $guardianRows = [];
        $logRows = [];
        $releasable = [];
        $cancellable = [];

        foreach ($guardians as $guardian) {
            $res = $results[$guardian->wa_number] ?? [
                'status' => 'unknown',
                'normalized' => $guardian->wa_number,
                'raw' => null,
                'error' => 'Tidak ada respon dari pemrosesan batch.',
            ];

            $guardianRows[] = [
                'id' => $guardian->id,
                // Kolom wajib diisi pada cabang INSERT; tidak ikut di-update.
                'student_id' => $guardian->student_id,
                'name' => $guardian->name,
                'wa_number' => $res['normalized'],
                'wa_status' => $res['status'],
                'wa_validated_at' => $now,
                'wa_validation_error' => $res['error'],
            ];

            $logRows[] = [
                'guardian_id' => $guardian->id,
                'phone' => $res['normalized'],
                'status' => $res['status'],
                'raw_response' => $res['raw'] !== null
                    ? json_encode($res['raw'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    : null,
                'error_message' => $res['error'],
                'validated_by' => "bulk_{$actor}",
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if ($res['status'] === 'valid') {
                $validCount++;
                $releasable[] = $guardian;
            } elseif ($res['status'] === 'invalid') {
                $invalidCount++;
                $cancellable[] = $guardian;
            } else {
                $unknownCount++;
            }
        }

        if ($guardianRows !== []) {
            Guardian::upsert(
                $guardianRows,
                ['id'],
                ['wa_number', 'wa_status', 'wa_validated_at', 'wa_validation_error']
            );
        }

        if ($logRows !== []) {
            GuardianPhoneValidationLog::insert($logRows);
        }

        // Efek samping per wali (rilis/batalkan notifikasi tertahan) tetap berurutan.
        foreach ($releasable as $guardian) {
            $this->validationService->releaseHeldNotifications($guardian);
        }

        foreach ($cancellable as $guardian) {
            $this->validationService->cancelHeldNotifications($guardian);
        }

        $processedCount = count($guardians);
        $remainingCount = max(0, $totalMatching - $processedCount);

        $summaryMessage = "Batch validasi selesai ({$processedCount} nomor): {$validCount} valid, {$invalidCount} invalid, {$unknownCount} unknown. Sisa antrean: {$remainingCount} nomor.";

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'processed' => $processedCount,
                'valid' => $validCount,
                'invalid' => $invalidCount,
                'unknown' => $unknownCount,
                'remaining' => $remainingCount,
                'message' => $summaryMessage,
            ]);
        }

        return back()->with('success', $summaryMessage);
    }
}
