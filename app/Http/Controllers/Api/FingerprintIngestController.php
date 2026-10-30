<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FingerprintDevice;
use App\Services\AttendanceLogProcessingService;
use App\Services\FingerprintIngestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FingerprintIngestController extends Controller
{
    /**
     * Endpoint API Ingest untuk C# Windows Connector Solution X601.
     * Menerima payload batch log yang dibaca dari mesin fingerprint via LAN.
     */
    public function ingest(
        Request $request,
        FingerprintIngestService $ingestService,
        AttendanceLogProcessingService $logProcessor
    ): JsonResponse {
        // Ambil token dari Header (X-Device-Token atau Bearer)
        $token = $request->header('X-Device-Token') ?? $request->bearerToken();

        if (! $token) {
            return response()->json([
                'status' => 'error',
                'message' => 'Header autentikasi X-Device-Token tidak ditemukan.',
            ], 401);
        }

        $device = FingerprintDevice::where('secret_token', $token)
            ->where('active', true)
            ->first();

        if (! $device) {
            return response()->json([
                'status' => 'error',
                'message' => 'Token perangkat tidak valid atau perangkat nonaktif.',
            ], 403);
        }

        $validated = $request->validate([
            'logs' => ['required', 'array'],
            'logs.*.machine_user_id' => ['required_without:logs.*.pin'],
            'logs.*.pin' => ['required_without:logs.*.machine_user_id'],
            'logs.*.occurred_at' => ['required_without:logs.*.time'],
            'logs.*.time' => ['required_without:logs.*.occurred_at'],
            'logs.*.source_key' => ['nullable', 'string'],
        ]);

        // 1. Simpan raw log secara idempotent (PRD FR-03, FR-08)
        $ingestResult = $ingestService->ingestBatch($device, $validated['logs']);

        // 2. Proses log yang baru tersimpan ke dalam attendances (PRD FR-09).
        //    Service ini juga mengirim notifikasi WA hanya untuk scan hari ini.
        $processingResult = $logProcessor->processDueForDevice($device, null, 200);
        $processedCount = $processingResult['processed'];

        return response()->json([
            'status' => 'success',
            'message' => 'Data log berhasil diterima dan diproses.',
            'device' => [
                'id' => $device->id,
                'name' => $device->name,
                'ip' => $device->ip,
            ],
            'summary' => [
                'received' => count($validated['logs']),
                'accepted' => $ingestResult['accepted'],
                'missing' => $ingestResult['missing'],
                'new_inserted' => $ingestResult['inserted'],
                'duplicates_skipped' => $ingestResult['duplicates_skipped'],
                'unmapped_logs' => $ingestResult['unmapped'],
                'attendances_processed' => $processedCount,
            ],
        ], 200);
    }

    /**
     * Endpoint Ping / Health check untuk konektor C#.
     */
    public function ping(Request $request): JsonResponse
    {
        $token = $request->header('X-Device-Token') ?? $request->bearerToken();
        $device = FingerprintDevice::where('secret_token', $token)
            ->where('active', true)
            ->first();

        if (! $device) {
            return response()->json(['status' => 'unauthorized'], 401);
        }

        $device->markOnline();

        return response()->json([
            'status' => 'ok',
            'device' => $device->name,
            'server_time' => now('Asia/Jakarta')->toIso8601String(),
        ]);
    }
}
