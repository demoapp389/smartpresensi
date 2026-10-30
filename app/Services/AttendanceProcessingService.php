<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\AttendanceLog;
use App\Models\AuditLog;
use App\Models\DeviceUser;
use App\Models\SchoolHoliday;
use App\Models\SchoolSchedule;
use App\Models\Student;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Service untuk memproses raw log menjadi status kehadiran terstruktur.
 * Sesuai PRD FR-09, BR-01, BR-02, BR-03:
 * - Menentukan masuk vs terlambat berdasarkan jam jadwal hari tersebut.
 * - Menolak scan sebelum jendela masuk dan setelah jam pulang.
 * - Menentukan kehadiran dari scan masuk; kelengkapan scan pulang dicatat terpisah.
 * - Menyimpan checkout tanpa check-in sebagai perlu tinjauan.
 * - Deduplikasi scan berulang dalam periode yang sama.
 */
class AttendanceProcessingService
{
    private const NOTE_BEFORE_CHECK_IN = 'Scan sebelum jendela masuk';

    private const NOTE_AFTER_END = 'Scan setelah jam pulang';

    public function __construct(
        private readonly AttendanceStatusResolver $statusResolver,
        private readonly ScanTimeReviewService $scanTimeReview,
    ) {}

    /**
     * Terjemahkan waktu scan tersimpan (wall-clock lokal perangkat) ke Carbon
     * dengan zona waktu perangkat. Jatuh ke zona aplikasi bila perangkat tidak ada.
     *
     * Memakai nilai mentah dari DB (bukan cast Eloquent) agar jam dinding tidak
     * bergeser; zona waktu hanya memberi konteks, bukan mengubah instan.
     */
    public function resolveScanTime(AttendanceLog $log): Carbon
    {
        $timezone = $log->device?->timezone ?: config('app.timezone', 'Asia/Jakarta');
        $raw = $log->getRawOriginal('occurred_at') ?? $log->occurred_at?->format('Y-m-d H:i:s');

        return Carbon::parse($raw, $timezone);
    }

    /**
     * Memproses satu raw log menjadi record kehadiran.
     */
    public function processLog(AttendanceLog $log, bool $allowHistorical = false): ?Attendance
    {
        return DB::transaction(
            fn (): ?Attendance => $this->processLogInTransaction($log, $allowHistorical)
        );
    }

    private function processLogInTransaction(AttendanceLog $log, bool $allowHistorical): ?Attendance
    {
        // Cari siswa yang terpetakan dengan machine_user_id ini
        $mapping = DeviceUser::where('device_id', $log->device_id)
            ->where('machine_user_id', $log->machine_user_id)
            ->first();

        if (! $mapping) {
            $log->update(['status' => 'unmapped']);

            return null;
        }

        // Pastikan siswa masih ada. Siswa yang sudah dipindahkan ke Sampah (soft delete)
        // tetap dipetakan ke mesin, jadi log-nya ditandai 'inactive_student'
        // agar tidak tercampur dengan PIN yang benar-benar tidak dikenal.
        $student = Student::withTrashed()->find($mapping->student_id);
        if (! $student) {
            $log->update(['status' => 'unmapped']);

            return null;
        }

        if ($student->trashed()) {
            $log->update(['status' => 'inactive_student']);

            return null;
        }

        $studentId = $student->id;
        $scanTime = $this->resolveScanTime($log);

        if (! $allowHistorical) {
            $reviewReason = $this->scanTimeReview->reviewReason($log, $scanTime);

            if ($reviewReason !== null) {
                $log->update([
                    'status' => AttendanceLog::STATUS_NEEDS_REVIEW,
                    'processing_note' => $reviewReason,
                    'next_retry_at' => null,
                ]);

                return null;
            }
        }

        $schoolDate = $scanTime->toDateString();
        $dayOfWeek = $scanTime->dayOfWeekIso; // 1 = Senin, 7 = Minggu

        // Ambil jadwal hari ini
        $schedule = SchoolSchedule::where('day_of_week', $dayOfWeek)
            ->where('is_active', true)
            ->first();

        $isHoliday = SchoolHoliday::isHoliday($schoolDate);
        $checkInStart = $this->scheduleMoment($schedule, 'check_in_start', $scanTime);
        $checkOutStart = $this->scheduleMoment($schedule, 'check_out_start', $scanTime);
        $endAt = $this->scheduleMoment($schedule, 'end_at', $scanTime);

        $rejectionNote = $this->rejectionNote($schedule, $checkInStart, $endAt, $scanTime);

        if ($rejectionNote !== null) {
            $log->update([
                'status' => 'rejected',
                'processing_note' => $rejectionNote,
            ]);

            return null;
        }

        $isCheckOutWindow = $schedule !== null
            && ! $isHoliday
            && $checkOutStart !== null
            && $scanTime->greaterThanOrEqualTo($checkOutStart);

        // Cari record absensi siswa untuk hari ini.
        // Race-safe: unique index (student_id, school_date) menjamin satu baris per hari.
        // Bila dua mesin memproses scan siswa yang sama hampir bersamaan, pihak yang kalah
        // menangkap pelanggaran unique dan memakai baris milik pihak yang menang.
        $attendance = Attendance::where('student_id', $studentId)
            ->whereDate('school_date', $schoolDate)
            ->lockForUpdate()
            ->first();

        if (! $attendance) {
            try {
                $attendance = Attendance::create([
                    'student_id' => $studentId,
                    'school_date' => $schoolDate,
                    'status' => 'hadir',
                    'policy_version' => 'v1.0',
                ]);
            } catch (QueryException) {
                $attendance = Attendance::where('student_id', $studentId)
                    ->whereDate('school_date', $schoolDate)
                    ->lockForUpdate()
                    ->firstOrFail();
            }
        }

        // Logika Periode Masuk vs Pulang (BR-02 & BR-03).
        // Update dilakukan secara atomik dengan syarat kolom masih NULL (conditional update).
        // Bila dua mesin mengirim scan siswa yang sama hampir bersamaan, hanya pihak
        // pertama yang berhasil mengisi kolom tujuan; pihak kedua menangkap 0 baris
        // terpengaruh dan menandai log-nya sebagai 'ignored'.
        if (! $attendance->check_in_at) {
            // Scan di jendela pulang tanpa scan pagi bukan check-in. Data disimpan untuk
            // ditinjau operator, bukan dihitung hadir atau Alpa.
            if ($isCheckOutWindow) {
                $checkOutUpdate = [
                    'check_out_at' => $scanTime,
                    'check_out_log_id' => $log->id,
                    'check_in_status' => AttendanceStatusResolver::CHECK_IN_MISSING,
                    'check_out_status' => AttendanceStatusResolver::CHECK_OUT_REVIEW,
                    'late_minutes' => 0,
                    'early_leave_minutes' => $this->statusResolver->earlyLeaveMinutes($scanTime, $schedule),
                ];

                if (! $attendance->is_manual_override) {
                    $checkOutUpdate['status'] = 'perlu_tinjauan';
                }

                $checkOutUpdate = array_merge(
                    $checkOutUpdate,
                    $this->automaticAlpaResetAttributes($attendance)
                );

                $updated = Attendance::where('id', $attendance->id)
                    ->whereNull('check_in_at')
                    ->whereNull('check_out_at')
                    ->update($checkOutUpdate);

                if ($updated === 0) {
                    $log->update(['status' => 'ignored']);

                    return $attendance->fresh();
                }

                $this->markLogProcessed($log, $attendance->id);

                return $attendance->fresh();
            }

            // Scan pertama di luar jendela pulang dianggap Check-In. Jika hari libur atau
            // tidak ada jadwal, tidak ada klasifikasi terlambat.
            $checkInStatus = $this->checkInStatusFor($scanTime, $schedule, $isHoliday);
            $checkInUpdate = [
                'check_in_at' => $scanTime,
                'check_in_log_id' => $log->id,
                'check_in_status' => $checkInStatus,
                'late_minutes' => $this->lateMinutesFor($scanTime, $schedule, $isHoliday),
            ];

            $existingCheckOut = $attendance->getRawOriginal('check_out_at');

            if ($existingCheckOut === null) {
                $checkInUpdate['check_out_status'] = AttendanceStatusResolver::CHECK_OUT_MISSING;
            } elseif ($schedule !== null && ! $isHoliday) {
                $checkOutAt = Carbon::parse($existingCheckOut, $scanTime->getTimezone());

                $checkInUpdate['check_out_status'] = $this->statusResolver->checkOutStatus($checkOutAt, $schedule);
                $checkInUpdate['early_leave_minutes'] = $this->statusResolver->earlyLeaveMinutes($checkOutAt, $schedule);
            } else {
                $checkInUpdate['check_out_status'] = AttendanceStatusResolver::CHECK_OUT_ON_TIME;
                $checkInUpdate['early_leave_minutes'] = 0;
            }

            if (! $attendance->is_manual_override) {
                $checkInUpdate['status'] = $checkInStatus === AttendanceStatusResolver::CHECK_IN_LATE
                    ? 'terlambat'
                    : 'hadir';
            }

            $checkInUpdate = array_merge(
                $checkInUpdate,
                $this->automaticAlpaResetAttributes($attendance),
                $existingCheckOut === null ? [] : $this->pendingReasonResetAttributes($attendance)
            );

            $updated = Attendance::where('id', $attendance->id)
                ->whereNull('check_in_at')
                ->update($checkInUpdate);

            if ($updated === 0) {
                $log->update(['status' => 'ignored']);

                return $attendance->fresh();
            }

            $this->markLogProcessed($log, $attendance->id);

            return $attendance->fresh();
        }

        // Jika sudah ada check_in_at, cek apakah waktu scan berada di jendela checkout.
        // Opsi A: jam pulang = scan PERTAMA di jendela checkout. Scan berikutnya di jendela
        // yang sama diabaikan agar jam pulang tidak bergeser dan WA pulang tidak terkirim ulang.
        if ($isCheckOutWindow && ! $attendance->check_out_at) {
            // Status utama tetap ditentukan oleh status masuk; pulang cepat hanya
            // dicatat pada check_out_status dan early_leave_minutes.
            $checkOutStatus = $this->statusResolver->checkOutStatus($scanTime, $schedule);
            $checkOutUpdate = [
                'check_out_at' => $scanTime,
                'check_out_log_id' => $log->id,
                'check_out_status' => $checkOutStatus,
                'early_leave_minutes' => $this->statusResolver->earlyLeaveMinutes($scanTime, $schedule),
            ];

            if (! $attendance->is_manual_override) {
                [$checkInStatus, $lateMinutes] = $this->resolveStoredCheckIn(
                    $attendance,
                    $schedule,
                    $scanTime,
                    $isHoliday
                );

                $checkOutUpdate['check_in_status'] = $checkInStatus;
                $checkOutUpdate['late_minutes'] = $lateMinutes;
                $checkOutUpdate['status'] = $checkInStatus === AttendanceStatusResolver::CHECK_IN_LATE
                    ? 'terlambat'
                    : 'hadir';
            }

            $checkOutUpdate = array_merge(
                $checkOutUpdate,
                $this->pendingReasonResetAttributes($attendance)
            );

            $updated = Attendance::where('id', $attendance->id)
                ->whereNull('check_out_at')
                ->update($checkOutUpdate);

            if ($updated === 0) {
                $log->update(['status' => 'ignored']);

                return $attendance->fresh();
            }

            $this->markLogProcessed($log, $attendance->id);

            return $attendance->fresh();
        }

        // Jika scan berulang sebelum jendela checkout (BR-02: scan berkali-kali hanya log tambahan)
        $log->update(['status' => 'ignored']);

        return $attendance;
    }

    private function markLogProcessed(AttendanceLog $log, int $attendanceId): void
    {
        $attendance = Attendance::find($attendanceId);

        if (! $attendance?->is_manual_override) {
            $log->update(['status' => 'processed']);
            $this->clearStaleAutomaticAlpaMetadata($attendanceId);

            return;
        }

        $log->update([
            'status' => 'processed',
            'processing_note' => 'Scan disimpan; koreksi manual dipertahankan.',
        ]);

        AuditLog::create([
            'actor_id' => null,
            'action' => 'scan_after_manual_override',
            'entity_type' => 'Attendance',
            'entity_id' => $attendance->id,
            'before' => [
                'status' => $attendance->status,
                'is_manual_override' => true,
                'operator_id' => $attendance->operator_id,
            ],
            'after' => [
                'status' => $attendance->status,
                'is_manual_override' => true,
                'check_in_at' => $attendance->check_in_at?->format('Y-m-d H:i:s'),
                'check_out_at' => $attendance->check_out_at?->format('Y-m-d H:i:s'),
                'attendance_log_id' => $log->id,
            ],
            'ip_address' => null,
            'created_at' => now(),
        ]);

        $this->clearStaleAutomaticAlpaMetadata($attendanceId);
    }

    private function checkInStatusFor(Carbon $scanTime, ?SchoolSchedule $schedule, bool $isHoliday): string
    {
        if ($schedule === null || $isHoliday) {
            return AttendanceStatusResolver::CHECK_IN_ON_TIME;
        }

        return $this->statusResolver->checkInStatus($scanTime, $schedule);
    }

    private function lateMinutesFor(Carbon $scanTime, ?SchoolSchedule $schedule, bool $isHoliday): int
    {
        if ($schedule === null || $isHoliday) {
            return 0;
        }

        return $this->statusResolver->lateMinutes($scanTime, $schedule);
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function resolveStoredCheckIn(
        Attendance $attendance,
        SchoolSchedule $schedule,
        Carbon $scanTime,
        bool $isHoliday
    ): array {
        if (in_array($attendance->check_in_status, [
            AttendanceStatusResolver::CHECK_IN_ON_TIME,
            AttendanceStatusResolver::CHECK_IN_LATE,
            AttendanceStatusResolver::CHECK_IN_VERIFIED,
        ], true)) {
            return [$attendance->check_in_status, (int) $attendance->late_minutes];
        }

        $rawCheckIn = $attendance->getRawOriginal('check_in_at');

        if ($rawCheckIn === null) {
            return [AttendanceStatusResolver::CHECK_IN_MISSING, 0];
        }

        $checkInAt = Carbon::parse($rawCheckIn, $scanTime->getTimezone());

        return [
            $this->checkInStatusFor($checkInAt, $schedule, $isHoliday),
            $this->lateMinutesFor($checkInAt, $schedule, $isHoliday),
        ];
    }

    private function rejectionNote(
        ?SchoolSchedule $schedule,
        ?Carbon $checkInStart,
        ?Carbon $endAt,
        Carbon $scanTime
    ): ?string {
        if ($schedule === null) {
            return null;
        }

        if ($checkInStart !== null && $scanTime->lessThan($checkInStart)) {
            return self::NOTE_BEFORE_CHECK_IN.' ('.$schedule->check_in_start.')';
        }

        if ($endAt !== null && $scanTime->greaterThan($endAt)) {
            return self::NOTE_AFTER_END.' ('.$schedule->end_at.')';
        }

        return null;
    }

    private function scheduleMoment(?SchoolSchedule $schedule, string $column, Carbon $date): ?Carbon
    {
        if ($schedule === null || ! $schedule->{$column}) {
            return null;
        }

        return Carbon::parse(
            $date->toDateString().' '.$schedule->{$column},
            $date->getTimezone()
        );
    }

    /**
     * Scan yang datang terlambat setelah Auto-Alpa harus mengembalikan status
     * ke jalur scan dan menghapus alasan Auto-Alpa yang sudah tidak berlaku.
     *
     * @return array<string, mixed>
     */
    private function automaticAlpaResetAttributes(Attendance $attendance): array
    {
        if ($attendance->status !== 'alpa' || ! $attendance->isAutomaticAlpa()) {
            return [];
        }

        return [
            'override_reason' => null,
            'operator_id' => null,
            'policy_version' => Attendance::POLICY_VERSION_BASE,
        ];
    }

    private function clearStaleAutomaticAlpaMetadata(int $attendanceId): void
    {
        Attendance::query()
            ->whereKey($attendanceId)
            ->where('policy_version', Attendance::POLICY_VERSION_AUTO_ALPA)
            ->where('is_manual_override', false)
            ->update([
                'override_reason' => null,
                'operator_id' => null,
                'policy_version' => Attendance::POLICY_VERSION_BASE,
            ]);
    }

    /**
     * Alasan scan tidak lengkap tidak lagi relevan setelah kedua scan tersedia.
     *
     * @return array<string, mixed>
     */
    private function pendingReasonResetAttributes(Attendance $attendance): array
    {
        if ($attendance->is_manual_override || $attendance->override_reason === null) {
            return [];
        }

        return [
            'override_reason' => null,
            'operator_id' => null,
        ];
    }

    /**
     * Tentukan jenis notifikasi WhatsApp dari kondisi attendance.
     *
     * Check-out tanpa check-in (perlu tinjauan) tidak menghasilkan notifikasi.
     */
    public function notificationEventType(Attendance $attendance): ?string
    {
        if ($attendance->is_manual_override || ! $attendance->check_in_at) {
            return null;
        }

        if ($attendance->check_out_at) {
            return 'check_out';
        }

        return $attendance->check_in_status === AttendanceStatusResolver::CHECK_IN_LATE ? 'late' : 'check_in';
    }
}
