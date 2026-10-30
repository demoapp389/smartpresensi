<?php

namespace App\Services;

use App\Models\AttendanceLog;
use App\Models\DeviceUser;
use App\Models\FingerprintDevice;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Service untuk menangani penerimaan raw log dari C# Connector Solution X601 (LAN).
 * Sesuai PRD FR-03, FR-08:
 * - Menerima data log mentah via API terautentikasi (localhost/hosting).
 * - Melakukan deduplikasi idempotent berdasarkan (device_id, source_key).
 * - Menandai status awal: 'unmapped' jika user ID mesin belum dipetakan, atau 'unprocessed'.
 */
class FingerprintIngestService
{
    /**
     * Kunci kanonik log absensi, dipakai bersama oleh semua jalur ingest.
     *
     * Format sengaja disamakan dengan connector Python (`x601_{pin}_{unix_sec}`) agar log
     * yang sama dari jalur berbeda (daemon TCP 4370 vs Web SOAP port 80) menghasilkan
     * source_key identik dan tidak tercipta baris ganda.
     */
    public static function canonicalSourceKey(string $machineUserId, CarbonInterface $occurredAt): string
    {
        return 'x601_'.$machineUserId.'_'.$occurredAt->timestamp;
    }

    /**
     * Ingest batch raw logs yang dikirim oleh C# Connector.
     *
     * @return array{inserted: int, accepted: int, duplicates_skipped: int, unmapped: int, missing: array<int, string>}
     */
    public function ingestBatch(FingerprintDevice $device, array $logs): array
    {
        $duplicateCount = 0;
        $unmappedCount = 0;

        // Preload seluruh ID mesin yang terdaftar untuk device ini dalam satu query (O(1) in-memory lookup)
        $mappedUsers = DeviceUser::where('device_id', $device->id)
            ->pluck('machine_user_id')
            ->mapWithKeys(fn ($id) => [(string) $id => true])
            ->toArray();

        $now = now();
        $rows = [];

        foreach ($logs as $item) {
            $machineUserId = (string) ($item['machine_user_id'] ?? $item['pin'] ?? '');
            $occurredAt = Carbon::parse($item['occurred_at'] ?? $item['time']);
            $sourceKey = $item['source_key'] ?? self::canonicalSourceKey($machineUserId, $occurredAt);

            // Buang duplikat DI DALAM satu batch (kunci sama dua kali) agar hitungan akurat.
            if (isset($rows[$sourceKey])) {
                $duplicateCount++;

                continue;
            }

            $isMapped = isset($mappedUsers[$machineUserId]);

            $rows[$sourceKey] = [
                'device_id' => $device->id,
                'machine_user_id' => $machineUserId,
                'occurred_at' => $occurredAt->toDateTimeString(),
                'received_at' => $now,
                'source_key' => $sourceKey,
                'status' => $isMapped ? 'unprocessed' : 'unmapped',
                'raw_payload' => json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (! $isMapped) {
                $unmappedCount++;
            }
        }

        if ($rows !== []) {
            // Cari source_key yang sudah ada agar hitungan inserted/duplicate tetap akurat,
            // lalu hanya upsert baris baru dalam batch (idempotent + race-safe via unique).
            $existing = AttendanceLog::where('device_id', $device->id)
                ->whereIn('source_key', array_keys($rows))
                ->pluck('source_key')
                ->flip();

            $newRows = [];

            foreach ($rows as $sourceKey => $row) {
                if ($existing->has($sourceKey)) {
                    $duplicateCount++;

                    if ($row['status'] === 'unmapped') {
                        $unmappedCount--;
                    }

                    continue;
                }

                $newRows[] = $row;
            }

            foreach (array_chunk($newRows, 500) as $chunk) {
                // Update list no-op: baris yang sudah ada tidak diubah (dedup saja).
                AttendanceLog::upsert($chunk, ['device_id', 'source_key'], ['source_key']);
            }

            $insertedCount = count($newRows);
            $storedKeys = AttendanceLog::where('device_id', $device->id)
                ->whereIn('source_key', array_keys($rows))
                ->pluck('source_key')
                ->all();

            $missingKeys = array_values(array_diff(array_keys($rows), $storedKeys));
            $acceptedCount = count($storedKeys);
        } else {
            $insertedCount = 0;
            $acceptedCount = 0;
            $missingKeys = [];
        }

        $device->markOnline();

        return [
            'inserted' => $insertedCount,
            'accepted' => $acceptedCount,
            'duplicates_skipped' => $duplicateCount,
            'unmapped' => $unmappedCount,
            'missing' => $missingKeys,
        ];
    }
}
