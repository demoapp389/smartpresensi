<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TaskProgress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaskProgressController extends Controller
{
    /**
     * Endpoint polling progres proses latar belakang.
     *
     * Sengaja berada di routes/api.php (middleware group `api`, tanpa StartSession).
     * Ini penting: session driver aplikasi memakai file lock, sehingga endpoint yang
     * butuh sesi bisa ikut tertahan selama proses panjang masih berjalan.
     *
     * Akses dibatasi lewat token UUID yang acak dan tidak ditebak.
     */
    public function show(Request $request, string $uuid): JsonResponse
    {
        $task = TaskProgress::where('uuid', $uuid)->first();

        if (! $task) {
            return response()->json([
                'message' => 'Proses tidak ditemukan atau sudah kedaluwarsa.',
            ], 404);
        }

        // UUID saja tidak cukup: wajib menyertakan token turunan APP_KEY yang
        // hanya diberikan ke pemilik task saat proses dimulai.
        $token = (string) $request->query('token', '');

        if ($token === '' || ! hash_equals($task->signature(), $token)) {
            return response()->json([
                'message' => 'Akses progres ditolak.',
            ], 403);
        }

        return response()->json([
            'uuid' => $task->uuid,
            'type' => $task->type,
            'label' => $task->label,
            'status' => $task->status,
            'percent' => $task->percent(),
            'processed' => $task->processed,
            'total' => $task->total,
            'message' => $task->message,
            'results' => $task->results,
            'finished' => $task->isFinished(),
            'started_at' => optional($task->started_at)->toIso8601String(),
            'finished_at' => optional($task->finished_at)->toIso8601String(),
        ]);
    }
}
