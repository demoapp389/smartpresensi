<?php

namespace App\Http\Controllers;

use App\Concerns\LogsAuditTrail;
use App\Jobs\SendWhatsAppBroadcastJob;
use App\Models\Guardian;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\WhatsAppTemplate;
use App\Services\AttendanceStatusResolver;
use App\Services\WhatsApp\WhatsAppSendPacer;
use App\Support\TaskProgressReporter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class WhatsAppBroadcastController extends Controller
{
    use LogsAuditTrail;

    public function __construct(protected WhatsAppSendPacer $pacer) {}

    /**
     * Tampilkan formulir pembuatan siaran pengumuman ke wali murid.
     */
    public function create(): View
    {
        $classes = SchoolClass::withCount(['students' => function ($q) {
            $q->where('active', true);
        }])->orderBy('name')->get();

        $totalActiveStudents = Student::where('active', true)->count();

        $validGuardiansCount = Guardian::where('active', true)
            ->where('wa_status', 'valid')
            ->whereNotNull('wa_number')
            ->whereHas('student', function ($q) {
                $q->where('active', true);
            })
            ->distinct('wa_number')
            ->count('wa_number');

        $broadcastTemplate = WhatsAppTemplate::getTemplate('broadcast');

        $estimatedMinutes = (int) ceil($validGuardiansCount * $this->pacer->averageGapSeconds() / 60);

        $throttleMinSeconds = (int) round($this->pacer->minDelayMs() / 1000);
        $throttleMaxSeconds = (int) round($this->pacer->maxDelayMs() / 1000);

        return view('notifications.broadcast', compact(
            'classes',
            'totalActiveStudents',
            'validGuardiansCount',
            'broadcastTemplate',
            'estimatedMinutes',
            'throttleMinSeconds',
            'throttleMaxSeconds'
        ));
    }

    /**
     * Hitung estimasi penerima unik secara dinamis (AJAX / JSON).
     */
    public function recipientCount(Request $request): JsonResponse
    {
        $targetType = $request->input('target_type', 'all');
        $classIds = (array) $request->input('class_ids', []);
        $recipientScope = $request->input('recipient_scope', 'all') === 'late' ? 'late' : 'all';

        $studentIds = $this->studentsQuery($targetType, $classIds, $recipientScope)->pluck('id');

        $totalGuardians = Guardian::whereIn('student_id', $studentIds)
            ->where('active', true)
            ->whereNotNull('wa_number')
            ->distinct('wa_number')
            ->count('wa_number');

        $validGuardians = Guardian::whereIn('student_id', $studentIds)
            ->where('active', true)
            ->where('wa_status', 'valid')
            ->whereNotNull('wa_number')
            ->distinct('wa_number')
            ->count('wa_number');

        return response()->json([
            'total_students' => $studentIds->count(),
            'total_guardians' => $totalGuardians,
            'valid_guardians' => $validGuardians,
            'recipient_scope' => $recipientScope,
            'estimated_minutes' => (int) ceil($validGuardians * $this->pacer->averageGapSeconds() / 60),
        ]);
    }

    /**
     * Proses pengiriman siaran massal ke antrean worker.
     */
    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'target_type' => ['required', 'string', 'in:all,class'],
            'recipient_scope' => ['nullable', 'string', 'in:all,late'],
            'class_ids' => ['nullable', 'array', 'required_if:target_type,class'],
            'class_ids.*' => ['exists:classes,id'],
            'message' => ['required', 'string', 'min:5', 'max:2000'],
        ], [
            'target_type.required' => 'Pilih target penerima siaran pengumuman.',
            'recipient_scope.in' => 'Jenis penerima siaran tidak valid.',
            'class_ids.required_if' => 'Pilih minimal satu kelas tujuan pengumuman.',
            'message.required' => 'Tuliskan isi pesan pengumuman yang ingin disampaikan.',
            'message.min' => 'Isi pesan pengumuman minimal 5 karakter.',
            'message.max' => 'Panjang pesan pengumuman maksimal 2000 karakter.',
        ]);

        $classIds = $validated['target_type'] === 'class' ? $validated['class_ids'] : null;
        $recipientScope = ($validated['recipient_scope'] ?? 'all') === 'late' ? 'late' : 'all';

        $targetLabel = $validated['target_type'] === 'all'
            ? 'Seluruh Wali Murid Sekolah'
            : 'Wali Murid Kelas Terpilih ('.count($classIds).' Kelas)';

        if ($recipientScope === 'late') {
            $targetLabel .= ' — Hanya Siswa Terlambat';
        }

        // Daftarkan TaskProgress untuk pelacakan langsung di UI
        $reporter = TaskProgressReporter::start(
            type: 'whatsapp_broadcast',
            label: "Siaran Pengumuman: {$targetLabel}",
            total: 0,
            userId: Auth::id()
        );

        // Dispatch background job
        SendWhatsAppBroadcastJob::dispatch(
            $validated['target_type'],
            $classIds,
            $validated['message'],
            $reporter->uuid(),
            Auth::id(),
            $recipientScope
        );

        $this->logAudit('create_whatsapp_broadcast', 'NotificationLog', null, null, [
            'target_type' => $validated['target_type'],
            'recipient_scope' => $recipientScope,
            'class_ids' => $classIds,
            'message_preview' => mb_substr($validated['message'], 0, 150),
            'task_uuid' => $reporter->uuid(),
        ]);

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'success' => true,
                'task_uuid' => $reporter->uuid(),
                'progress_token' => $reporter->signature(),
                'recipient_scope' => $recipientScope,
                'message' => 'Siaran pengumuman berhasil didaftarkan dan sedang dikirim.',
            ]);
        }

        return redirect()->route('whatsapp.broadcast.create')
            ->with('success', 'Siaran pengumuman berhasil didaftarkan dan sedang diproses di antrean.')
            ->with('broadcast_task_uuid', $reporter->uuid());
    }

    /**
     * Query siswa sesuai target kelas dan jenis penerima siaran.
     *
     * @param  list<int|string>  $classIds
     * @return Builder<Student>
     */
    private function studentsQuery(string $targetType, array $classIds, string $recipientScope): Builder
    {
        $query = Student::query()->where('active', true);

        if ($recipientScope === 'late') {
            $query->whereHas('attendances', function ($q): void {
                $q->whereDate('school_date', now('Asia/Jakarta')->toDateString())
                    ->where('check_in_status', AttendanceStatusResolver::CHECK_IN_LATE);
            });
        }

        if ($targetType === 'class' && $classIds !== []) {
            $query->whereIn('class_id', $classIds);
        }

        return $query;
    }
}
