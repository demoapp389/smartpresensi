<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\AttendanceLog;
use App\Models\AuditLog;
use App\Models\FingerprintDevice;
use App\Support\TaskProgressReporter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Memproses raw log dengan retry terbatas agar satu log bermasalah tidak
 * menghentikan batch, dan agar Auto-Alpa tidak tertahan selamanya.
 */
class AttendanceLogProcessingService
{
    public function __construct(
        private readonly AttendanceProcessingService $processingService,
        private readonly WhatsAppNotificationService $whatsAppNotificationService,
    ) {}

    public function pendingCountForDevice(FingerprintDevice $device): int
    {
        return $this->dueQuery()
            ->where('device_id', $device->id)
            ->count();
    }

    /**
     * @return array{attempted: int, processed: int, failed: int, review: int}
     */
    public function processDueForDevice(
        FingerprintDevice $device,
        ?TaskProgressReporter $reporter = null,
        int $limit = 200,
    ): array {
        $logs = $this->dueQuery()
            ->where('device_id', $device->id)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        return $this->processLogs($logs, $reporter);
    }

    /**
     * @return array{attempted: int, processed: int, failed: int, review: int}
     */
    public function processDue(?TaskProgressReporter $reporter = null, int $limit = 500): array
    {
        $logs = $this->dueQuery()
            ->orderBy('id')
            ->limit($limit)
            ->get();

        return $this->processLogs($logs, $reporter);
    }

    /**
     * @param  array<int, string>  $allowedStatuses
     */
    public function processLog(
        AttendanceLog $log,
        array $allowedStatuses = ['unprocessed'],
    ): ?Attendance {
        $attendance = DB::transaction(function () use ($log, $allowedStatuses): ?Attendance {
            $lockedLog = AttendanceLog::query()
                ->whereKey($log->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedLog || ! in_array($lockedLog->status, $allowedStatuses, true)) {
                return null;
            }

            $attempt = (int) $lockedLog->processing_attempts + 1;

            $lockedLog->forceFill([
                'processing_attempts' => $attempt,
                'last_attempted_at' => now(),
                'next_retry_at' => null,
                'processing_note' => null,
            ])->save();

            try {
                $attendance = DB::transaction(
                    fn (): ?Attendance => $this->processingService->processLog($lockedLog)
                );
            } catch (Throwable $exception) {
                $lockedLog->refresh();
                $this->recordFailedAttempt($lockedLog, $attempt, $exception);

                return null;
            }

            $lockedLog->refresh();

            if ($lockedLog->status === 'unprocessed') {
                $this->recordFailedAttempt(
                    $lockedLog,
                    $attempt,
                    new \RuntimeException('Log tidak menghasilkan status proses yang valid.')
                );

                return null;
            }

            return $attendance;
        });

        if ($attendance !== null) {
            $log->refresh();

            try {
                $this->queueNotificationIfNeeded($log, $attendance);
            } catch (Throwable $exception) {
                Log::warning('Gagal membuat notifikasi WhatsApp untuk log absensi.', [
                    'attendance_log_id' => $log->id,
                    'attendance_id' => $attendance->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return $attendance;
    }

    /**
     * Proses ulang log lama yang sebelumnya tertahan karena PIN belum dipetakan.
     *
     * @param  array<int, string>  $allowedStatuses
     * @return array{processed: int, review: int, failed: int}
     */
    public function processLogsForMapping(
        int $deviceId,
        string $pin,
        array $allowedStatuses = [AttendanceLog::STATUS_UNMAPPED],
    ): array {
        $result = [
            'processed' => 0,
            'review' => 0,
            'failed' => 0,
        ];

        AttendanceLog::query()
            ->where('device_id', $deviceId)
            ->where('machine_user_id', $pin)
            ->whereIn('status', $allowedStatuses)
            ->chunkById(200, function (Collection $logs) use (&$result, $allowedStatuses): void {
                $batch = $this->processLogs($logs, null, $allowedStatuses);

                $result['processed'] += $batch['processed'];
                $result['review'] += $batch['review'];
                $result['failed'] += $batch['failed'];
            });

        return $result;
    }

    /**
     * @param  Collection<int, AttendanceLog>  $logs
     * @param  array<int, string>  $allowedStatuses
     * @return array{attempted: int, processed: int, failed: int, review: int}
     */
    private function processLogs(
        Collection $logs,
        ?TaskProgressReporter $reporter = null,
        array $allowedStatuses = [AttendanceLog::STATUS_UNPROCESSED],
    ): array {
        $result = [
            'attempted' => 0,
            'processed' => 0,
            'failed' => 0,
            'review' => 0,
        ];

        foreach ($logs as $log) {
            $reporter?->advance(1, "Memproses absensi {$log->machine_user_id}...");

            $this->processLog($log, $allowedStatuses);
            $result['attempted']++;

            $status = $log->fresh()?->status;

            if ($status === AttendanceLog::STATUS_PROCESSED) {
                $result['processed']++;
            } elseif ($status === AttendanceLog::STATUS_NEEDS_REVIEW) {
                $result['review']++;
            } elseif (in_array($status, [AttendanceLog::STATUS_UNPROCESSED, AttendanceLog::STATUS_FAILED], true)) {
                $result['failed']++;
            }
        }

        return $result;
    }

    /**
     * Setujui scan yang sebelumnya ditahan karena waktu mesin tidak wajar.
     */
    public function approveTimeReview(
        AttendanceLog $log,
        int $adminId,
        string $note,
    ): ?Attendance {
        $before = null;

        $attendance = DB::transaction(function () use ($log, $adminId, $note, &$before): ?Attendance {
            $lockedLog = AttendanceLog::query()
                ->whereKey($log->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedLog || $lockedLog->status !== AttendanceLog::STATUS_NEEDS_REVIEW) {
                return null;
            }

            $before = $this->auditState($lockedLog);
            $lockedLog->forceFill([
                'processing_attempts' => (int) $lockedLog->processing_attempts + 1,
                'last_attempted_at' => now(),
                'next_retry_at' => null,
            ])->save();

            $attendance = $this->processingService->processLog($lockedLog, true);
            $lockedLog->refresh();

            if ($lockedLog->status === AttendanceLog::STATUS_PROCESSED) {
                $lockedLog->forceFill(['processing_note' => 'Disetujui admin: '.$note])->save();
            }

            AuditLog::create([
                'actor_id' => $adminId,
                'action' => 'approve_attendance_log_time_review',
                'entity_type' => 'AttendanceLog',
                'entity_id' => $lockedLog->id,
                'before' => $before,
                'after' => array_merge($this->auditState($lockedLog), [
                    'attendance_id' => $attendance?->id,
                    'review_note' => $note,
                ]),
                'ip_address' => null,
                'created_at' => now(),
            ]);

            return $attendance;
        });

        // Approval time-review selalu bersifat historis/terlambat, termasuk
        // scan hari yang sama yang baru ditinjau. Jangan kirim WhatsApp susulan.
        return $attendance;
    }

    public function rejectTimeReview(
        AttendanceLog $log,
        int $adminId,
        string $note,
    ): bool {
        return DB::transaction(function () use ($log, $adminId, $note): bool {
            $lockedLog = AttendanceLog::query()
                ->whereKey($log->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedLog || $lockedLog->status !== AttendanceLog::STATUS_NEEDS_REVIEW) {
                return false;
            }

            $before = $this->auditState($lockedLog);
            $lockedLog->forceFill([
                'status' => AttendanceLog::STATUS_TIME_REJECTED,
                'processing_note' => 'Ditolak admin: '.$note,
                'next_retry_at' => null,
            ])->save();

            AuditLog::create([
                'actor_id' => $adminId,
                'action' => 'reject_attendance_log_time_review',
                'entity_type' => 'AttendanceLog',
                'entity_id' => $lockedLog->id,
                'before' => $before,
                'after' => array_merge($this->auditState($lockedLog), [
                    'review_note' => $note,
                ]),
                'ip_address' => null,
                'created_at' => now(),
            ]);

            return true;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function auditState(AttendanceLog $log): array
    {
        return [
            'status' => $log->status,
            'processing_note' => $log->processing_note,
            'processing_attempts' => $log->processing_attempts,
            'occurred_at' => $log->occurred_at?->format('Y-m-d H:i:s'),
            'received_at' => $log->received_at?->format('Y-m-d H:i:s'),
        ];
    }

    private function queueNotificationIfNeeded(AttendanceLog $log, ?Attendance $attendance): void
    {
        if (! $attendance || $log->status !== 'processed' || ! $attendance->school_date->isToday()) {
            return;
        }

        $eventType = $this->processingService->notificationEventType($attendance);

        if ($eventType !== null) {
            $this->whatsAppNotificationService->queueAttendanceNotifications($attendance, $eventType);
        }
    }

    private function recordFailedAttempt(
        AttendanceLog $log,
        int $attempt,
        Throwable $exception,
    ): void {
        $maxAttempts = max(1, (int) config('attendance.log_processing_max_attempts', 4));
        $isTerminal = $attempt >= $maxAttempts;

        $log->forceFill([
            'status' => $isTerminal ? 'failed' : 'unprocessed',
            'processing_attempts' => $attempt,
            'last_attempted_at' => now(),
            'next_retry_at' => $isTerminal ? null : $this->nextRetryAt($attempt),
            'processing_note' => Str::limit(
                'Gagal memproses log: '.$exception->getMessage(),
                250
            ),
        ])->save();

        if (! $isTerminal) {
            return;
        }

        AuditLog::create([
            'actor_id' => null,
            'action' => 'attendance_log_processing_failed',
            'entity_type' => 'AttendanceLog',
            'entity_id' => $log->id,
            'before' => null,
            'after' => [
                'status' => $log->status,
                'processing_attempts' => $log->processing_attempts,
                'processing_note' => $log->processing_note,
            ],
            'ip_address' => null,
            'created_at' => now(),
        ]);
    }

    private function nextRetryAt(int $attempt): Carbon
    {
        $backoff = config('attendance.log_processing_backoff_minutes', [1, 5, 15, 60]);
        $backoff = is_array($backoff) && $backoff !== [] ? array_values($backoff) : [1];
        $minutes = max(1, (int) $backoff[min($attempt - 1, count($backoff) - 1)]);

        return now()->addMinutes($minutes);
    }

    private function dueQuery(): Builder
    {
        return AttendanceLog::query()
            ->where('status', 'unprocessed')
            ->where(function (Builder $query): void {
                $query->whereNull('next_retry_at')
                    ->orWhere('next_retry_at', '<=', now());
            });
    }
}
