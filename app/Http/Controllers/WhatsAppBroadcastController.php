<?php

namespace App\Http\Controllers;

use App\Concerns\LogsAuditTrail;
use App\Jobs\SendWhatsAppBroadcastJob;
use App\Models\Guardian;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\WhatsAppTemplate;
use App\Support\TaskProgressReporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class WhatsAppBroadcastController extends Controller
{
    use LogsAuditTrail;

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

        return view('notifications.broadcast', compact(
            'classes',
            'totalActiveStudents',
            'validGuardiansCount',
            'broadcastTemplate'
        ));
    }

    /**
     * Hitung estimasi penerima unik secara dinamis (AJAX / JSON).
     */
    public function recipientCount(Request $request): JsonResponse
    {
        $targetType = $request->input('target_type', 'all');
        $classIds = $request->input('class_ids', []);

        $studentsQuery = Student::query()
            ->where('active', true);

        if ($targetType === 'class' && ! empty($classIds)) {
            $studentsQuery->whereIn('class_id', (array) $classIds);
        }

        $studentIds = $studentsQuery->pluck('id');

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
        ]);
    }

    /**
     * Proses pengiriman siaran massal ke antrean worker.
     */
    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'target_type' => ['required', 'string', 'in:all,class'],
            'class_ids' => ['nullable', 'array', 'required_if:target_type,class'],
            'class_ids.*' => ['exists:classes,id'],
            'message' => ['required', 'string', 'min:5', 'max:2000'],
        ], [
            'target_type.required' => 'Pilih target penerima siaran pengumuman.',
            'class_ids.required_if' => 'Pilih minimal satu kelas tujuan pengumuman.',
            'message.required' => 'Tuliskan isi pesan pengumuman yang ingin disampaikan.',
            'message.min' => 'Isi pesan pengumuman minimal 5 karakter.',
            'message.max' => 'Panjang pesan pengumuman maksimal 2000 karakter.',
        ]);

        $classIds = $validated['target_type'] === 'class' ? $validated['class_ids'] : null;

        $targetLabel = $validated['target_type'] === 'all'
            ? 'Seluruh Wali Murid Sekolah'
            : 'Wali Murid Kelas Terpilih ('.count($classIds).' Kelas)';

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
            Auth::id()
        );

        $this->logAudit('create_whatsapp_broadcast', 'NotificationLog', null, null, [
            'target_type' => $validated['target_type'],
            'class_ids' => $classIds,
            'message_preview' => mb_substr($validated['message'], 0, 150),
            'task_uuid' => $reporter->uuid(),
        ]);

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'success' => true,
                'task_uuid' => $reporter->uuid(),
                'progress_token' => $reporter->signature(),
                'message' => 'Siaran pengumuman berhasil didaftarkan dan sedang dikirim.',
            ]);
        }

        return redirect()->route('whatsapp.broadcast.create')
            ->with('success', 'Siaran pengumuman berhasil didaftarkan dan sedang diproses di antrean.')
            ->with('broadcast_task_uuid', $reporter->uuid());
    }
}
