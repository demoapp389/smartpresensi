<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Student;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class AttendanceCorrectionService
{
    public const MANUAL_STATUSES = ['izin', 'sakit', 'alpa'];

    public const HOMEROOM_STATUSES = ['izin', 'sakit'];

    public const BULK_STATUSES = ['izin', 'sakit', 'alpa'];

    public const CORRECTION_STATUSES = ['hadir', 'izin', 'sakit', 'alpa'];

    public const ADMIN_SCAN_EXCEPTION_STATUSES = ['izin', 'sakit', 'alpa'];

    private const ABSENCE_STATUSES = ['izin', 'sakit', 'alpa'];

    private const CHECKOUT_ONLY_STATUSES = ['hadir', 'izin', 'sakit', 'alpa'];

    /**
     * Status koreksi yang tersedia untuk satu baris presensi.
     *
     * @return array<int, string>
     */
    public function allowedStatusesFor(User $user, Attendance $attendance): array
    {
        if ($this->hasCompleteScan($attendance)) {
            return $user->isAdmin() ? self::ABSENCE_STATUSES : [];
        }

        if ($this->hasCheckoutOnly($attendance)) {
            if ($user->isAdmin()) {
                return [];
            }

            return $user->isOperator() ? self::CHECKOUT_ONLY_STATUSES : [];
        }

        if ($this->hasScanEvidence($attendance)) {
            return [];
        }

        if ($user->isHomeroomTeacher()) {
            return $this->isInHomeroomScope($user, $attendance) ? self::HOMEROOM_STATUSES : [];
        }

        if ($user->isOperator()) {
            return self::BULK_STATUSES;
        }

        return [];
    }

    public function assertCanCorrect(User $user, Attendance $attendance, string $status): void
    {
        $this->assertUserCanCorrect($user);

        if ($user->isHomeroomTeacher() && ! $this->isInHomeroomScope($user, $attendance)) {
            throw new AuthorizationException('Akses ditolak: presensi ini bukan milik kelas yang Anda ampu.');
        }

        if (! in_array($status, $this->allowedStatusesFor($user, $attendance), true)) {
            throw ValidationException::withMessages([
                'status' => $this->rejectionMessage($user, $attendance, $status),
            ]);
        }
    }

    public function assertCanBulkCorrect(User $user, string $status): void
    {
        if (! $user->isOperator()) {
            throw new AuthorizationException('Akses ditolak: hanya admin atau operator yang dapat melakukan koreksi massal.');
        }

        if (! in_array($status, self::BULK_STATUSES, true)) {
            throw ValidationException::withMessages([
                'status' => 'Koreksi massal hanya mendukung status Izin, Sakit, atau Alpa.',
            ]);
        }
    }

    /**
     * @param  Collection<int, Attendance>  $attendances
     */
    public function assertBulkRowsAllowed(User $user, Collection $attendances, string $status): void
    {
        $this->assertCanBulkCorrect($user, $status);

        $invalidScanned = $attendances
            ->filter(fn (Attendance $attendance): bool => $this->hasScanEvidence($attendance))
            ->values();

        if ($invalidScanned->isNotEmpty()) {
            throw ValidationException::withMessages([
                'ids' => "Koreksi massal dibatalkan karena status {$this->statusLabel($status)} hanya untuk presensi tanpa bukti scan. Siswa: {$this->studentNames($invalidScanned)}.",
            ]);
        }

        $invalidManualPresence = $attendances
            ->filter(fn (Attendance $attendance): bool => $attendance->is_manual_override && $attendance->status === 'hadir')
            ->values();

        if ($invalidManualPresence->isNotEmpty()) {
            throw ValidationException::withMessages([
                'ids' => "Koreksi massal tidak dapat mengubah catatan Hadir manual tanpa scan. Siswa: {$this->studentNames($invalidManualPresence)}.",
            ]);
        }
    }

    public function canRecordScanReason(User $user, Attendance $attendance): bool
    {
        if (! $user->isOperator()) {
            return false;
        }

        return ($attendance->check_in_at !== null) xor ($attendance->check_out_at !== null);
    }

    public function assertCanRecordScanReason(User $user, Attendance $attendance): void
    {
        if (! $user->isOperator()) {
            throw new AuthorizationException('Akses ditolak: hanya admin atau operator yang dapat mengisi alasan scan tidak lengkap.');
        }

        if (! $this->canRecordScanReason($user, $attendance)) {
            throw ValidationException::withMessages([
                'override_reason' => 'Alasan hanya dapat diisi pada presensi yang memiliki tepat satu data scan.',
            ]);
        }
    }

    public function canUseAdminScanException(User $user, Attendance $attendance): bool
    {
        return $user->isAdmin()
            && $attendance->check_in_at === null
            && $attendance->check_out_at !== null;
    }

    public function assertCanUseAdminScanException(User $user, Attendance $attendance, string $status): void
    {
        if (! $this->canUseAdminScanException($user, $attendance)) {
            throw new AuthorizationException('Akses ditolak: pengecualian scan hanya untuk admin pada presensi dengan scan pulang saja.');
        }

        if (! in_array($status, self::ADMIN_SCAN_EXCEPTION_STATUSES, true)) {
            throw ValidationException::withMessages([
                'status' => 'Pengecualian scan hanya dapat menetapkan status Izin, Sakit, atau Alpa.',
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function adminScanExceptionAttributes(
        User $user,
        Attendance $attendance,
        string $status,
        string $overrideReason,
    ): array {
        $this->assertCanUseAdminScanException($user, $attendance, $status);

        return $this->baseOverrideAttributes(
            $status,
            $overrideReason,
            $user->id,
            Attendance::POLICY_VERSION_ADMIN_SCAN_EXCEPTION
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function scanReasonAttributes(User $user, Attendance $attendance, string $overrideReason): array
    {
        $this->assertCanRecordScanReason($user, $attendance);

        return [
            'override_reason' => $overrideReason,
            'operator_id' => $user->id,
            'policy_version' => 'v1.0',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function manualAttributes(
        User $user,
        Student $student,
        ?Attendance $attendance,
        string $status,
        string $overrideReason,
    ): array {
        $this->assertManualInputAllowed($user, $student, $attendance, $status);

        return array_merge(
            $this->baseOverrideAttributes($status, $overrideReason, $user->id),
            [
                'check_in_status' => null,
                'check_out_status' => null,
                'late_minutes' => 0,
                'early_leave_minutes' => 0,
            ]
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function overrideAttributes(Attendance $attendance, string $status, string $overrideReason, int $operatorId): array
    {
        if ($this->hasCompleteScan($attendance)) {
            if (! in_array($status, self::ABSENCE_STATUSES, true)) {
                throw ValidationException::withMessages([
                    'status' => 'Presensi dengan scan lengkap hanya dapat dikoreksi ke Izin, Sakit, atau Alpa.',
                ]);
            }

            return $this->baseOverrideAttributes(
                $status,
                $overrideReason,
                $operatorId,
                Attendance::POLICY_VERSION_SCAN_CORRECTION
            );
        }

        if ($this->hasCheckoutOnly($attendance)) {
            if (! in_array($status, self::CHECKOUT_ONLY_STATUSES, true)) {
                throw ValidationException::withMessages([
                    'status' => 'Presensi dengan scan pulang saja dapat diselesaikan ke Hadir, Izin, Sakit, atau Alpa.',
                ]);
            }

            $attributes = $this->baseOverrideAttributes(
                $status,
                $overrideReason,
                $operatorId,
                Attendance::POLICY_VERSION_SCAN_CORRECTION
            );

            if ($status === 'hadir') {
                $attributes['check_in_status'] = AttendanceStatusResolver::CHECK_IN_VERIFIED;
                $attributes['late_minutes'] = 0;
            }

            return $attributes;
        }

        if ($this->hasScanEvidence($attendance)) {
            throw ValidationException::withMessages([
                'status' => 'Presensi dengan scan masuk tidak dapat diubah statusnya. Gunakan Isi Alasan untuk scan pulang yang belum tersedia.',
            ]);
        }

        if (! in_array($status, self::ABSENCE_STATUSES, true)) {
            throw ValidationException::withMessages([
                'status' => 'Koreksi status tanpa scan hanya mendukung Izin, Sakit, atau Alpa.',
            ]);
        }

        return array_merge(
            $this->baseOverrideAttributes($status, $overrideReason, $operatorId),
            [
                'check_in_status' => null,
                'check_out_status' => null,
                'late_minutes' => 0,
                'early_leave_minutes' => 0,
            ]
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function auditState(Attendance $attendance): array
    {
        return [
            'status' => $attendance->status,
            'check_in_at' => $attendance->check_in_at?->format('Y-m-d H:i:s'),
            'check_out_at' => $attendance->check_out_at?->format('Y-m-d H:i:s'),
            'check_in_status' => $attendance->check_in_status,
            'check_out_status' => $attendance->check_out_status,
            'late_minutes' => $attendance->late_minutes,
            'early_leave_minutes' => $attendance->early_leave_minutes,
            'is_manual_override' => $attendance->is_manual_override,
            'override_reason' => $attendance->override_reason,
            'operator_id' => $attendance->operator_id,
            'policy_version' => $attendance->policy_version,
        ];
    }

    private function assertUserCanCorrect(User $user): void
    {
        if (! $user->isHomeroomTeacher() && ! $user->isOperator()) {
            throw new AuthorizationException('Akses ditolak: Anda tidak memiliki wewenang untuk mengoreksi presensi.');
        }
    }

    private function assertManualInputAllowed(
        User $user,
        Student $student,
        ?Attendance $attendance,
        string $status,
    ): void {
        if (! in_array($status, self::MANUAL_STATUSES, true)) {
            throw ValidationException::withMessages([
                'status' => 'Status presensi manual tidak valid.',
            ]);
        }

        if (! $user->isHomeroomTeacher() && ! $user->isOperator()) {
            throw new AuthorizationException('Akses ditolak: Anda tidak memiliki wewenang untuk mencatat presensi.');
        }

        if ($user->isHomeroomTeacher()) {
            if ($student->class_id !== $user->class_id) {
                throw new AuthorizationException('Akses ditolak: siswa ini bukan anggota kelas yang Anda ampu.');
            }

            if (! in_array($status, self::HOMEROOM_STATUSES, true)) {
                throw ValidationException::withMessages([
                    'status' => 'Wali kelas hanya dapat mencatat status Izin atau Sakit.',
                ]);
            }
        }

        if ($attendance !== null && $this->hasScanEvidence($attendance)) {
            throw ValidationException::withMessages([
                'status' => "Status {$this->statusLabel($status)} tidak dapat dicatat karena sudah ada bukti scan. Kehadiran mengikuti data scan.",
            ]);
        }
    }

    private function isInHomeroomScope(User $user, Attendance $attendance): bool
    {
        return $attendance->student !== null && $attendance->student->class_id === $user->class_id;
    }

    private function hasScanEvidence(Attendance $attendance): bool
    {
        return $attendance->check_in_at !== null
            || $attendance->check_out_at !== null;
    }

    private function hasCompleteScan(Attendance $attendance): bool
    {
        return $attendance->check_in_at !== null
            && $attendance->check_out_at !== null;
    }

    private function hasCheckoutOnly(Attendance $attendance): bool
    {
        return $attendance->check_in_at === null
            && $attendance->check_out_at !== null;
    }

    /**
     * @param  Collection<int, Attendance>  $attendances
     */
    private function studentNames(Collection $attendances): string
    {
        $names = $attendances
            ->take(5)
            ->map(fn (Attendance $attendance): string => $attendance->student?->name ?? 'Siswa')
            ->implode(', ');

        return $names.($attendances->count() > 5 ? ' dan lainnya' : '');
    }

    /**
     * @return array<string, mixed>
     */
    private function baseOverrideAttributes(
        string $status,
        string $overrideReason,
        int $operatorId,
        string $policyVersion = Attendance::POLICY_VERSION_BASE
    ): array {
        return [
            'status' => $status,
            'is_manual_override' => true,
            'override_reason' => $overrideReason,
            'operator_id' => $operatorId,
            'policy_version' => $policyVersion,
        ];
    }

    private function rejectionMessage(User $user, Attendance $attendance, string $status): string
    {
        if ($this->hasCompleteScan($attendance)) {
            if (! $user->isAdmin()) {
                return 'Presensi dengan scan lengkap hanya dapat dikoreksi oleh admin.';
            }

            return 'Presensi dengan scan lengkap hanya dapat dikoreksi ke Izin, Sakit, atau Alpa.';
        }

        if ($this->hasCheckoutOnly($attendance)) {
            if ($user->isAdmin()) {
                return 'Presensi dengan scan pulang saja harus diselesaikan melalui Pengecualian Admin.';
            }

            return 'Presensi dengan scan pulang saja hanya dapat diselesaikan oleh admin atau operator.';
        }

        if ($this->hasScanEvidence($attendance)) {
            return 'Presensi dengan scan masuk tidak dapat diubah statusnya. Gunakan Isi Alasan untuk scan pulang yang belum tersedia.';
        }

        if ($user->isHomeroomTeacher()) {
            return 'Wali kelas hanya dapat mengoreksi presensi tanpa scan menjadi status Izin atau Sakit.';
        }

        return "Status {$this->statusLabel($status)} tidak dapat diterapkan pada presensi ini.";
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'hadir' => 'Hadir',
            'terlambat' => 'Terlambat',
            'izin' => 'Izin',
            'sakit' => 'Sakit',
            'alpa' => 'Alpa',
            default => $status,
        };
    }
}
