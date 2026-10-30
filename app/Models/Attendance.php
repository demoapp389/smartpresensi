<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Attendance extends Model
{
    use HasFactory;

    public const STATUS_LABELS = [
        'hadir' => 'Hadir',
        'terlambat' => 'Terlambat',
        'izin' => 'Izin',
        'sakit' => 'Sakit',
        'alpa' => 'Alpa',
        'perlu_tinjauan' => 'Perlu Tinjauan',
    ];

    public const CHECK_IN_STATUS_LABELS = [
        'tepat_waktu' => 'Tepat Waktu',
        'terlambat' => 'Terlambat',
        'tidak_absen' => 'Tidak Absen',
        'diverifikasi' => 'Diverifikasi',
    ];

    public const CHECK_OUT_STATUS_LABELS = [
        'tepat_waktu' => 'Tepat Waktu',
        'pulang_cepat' => 'Pulang Cepat',
        'belum_absen' => 'Belum Absen',
        'perlu_verifikasi' => 'Perlu Verifikasi',
    ];

    public const POLICY_VERSION_BASE = 'v1.0';

    public const POLICY_VERSION_PENDING = 'v1.1';

    public const POLICY_VERSION_AUTO_ALPA = 'v1.1-auto-alpa';

    public const POLICY_VERSION_SCAN_CORRECTION = 'v1.2-scan-correction';

    public const POLICY_VERSION_ADMIN_SCAN_EXCEPTION = 'v1.3-admin-scan-exception';

    protected $fillable = [
        'student_id',
        'school_date',
        'check_in_at',
        'check_out_at',
        'status',
        'check_in_status',
        'check_out_status',
        'late_minutes',
        'early_leave_minutes',
        'policy_version',
        'check_in_log_id',
        'check_out_log_id',
        'is_manual_override',
        'override_reason',
        'operator_id',
    ];

    protected $casts = [
        'school_date' => 'date',
        'check_in_at' => 'datetime',
        'check_out_at' => 'datetime',
        'late_minutes' => 'integer',
        'early_leave_minutes' => 'integer',
        'is_manual_override' => 'boolean',
    ];

    protected function statusLabel(): Attribute
    {
        return Attribute::get(fn (): string => $this->labelFor(self::STATUS_LABELS, $this->status));
    }

    protected function statusDisplayLabel(): Attribute
    {
        return Attribute::get(function (): string {
            if ($this->isAutomaticAlpa()) {
                return 'Alpa Otomatis';
            }

            if ($this->isManualAlpa()) {
                return 'Alpa (Koreksi Manual)';
            }

            return $this->status_label;
        });
    }

    protected function scanCompletenessLabel(): Attribute
    {
        return Attribute::get(function (): string {
            if ($this->check_in_at !== null && $this->check_out_at !== null) {
                return 'Scan Lengkap';
            }

            if ($this->check_in_at !== null) {
                return 'Scan Pulang Belum Ada';
            }

            if ($this->check_out_at !== null) {
                return 'Scan Masuk Belum Ada';
            }

            return 'Belum Ada Scan';
        });
    }

    protected function checkInStatusLabel(): Attribute
    {
        return Attribute::get(fn (): string => $this->labelFor(self::CHECK_IN_STATUS_LABELS, $this->check_in_status));
    }

    protected function checkOutStatusLabel(): Attribute
    {
        return Attribute::get(fn (): string => $this->labelFor(self::CHECK_OUT_STATUS_LABELS, $this->check_out_status));
    }

    protected function lateMinutesDisplay(): Attribute
    {
        return Attribute::get(fn (): ?int => $this->minutesForDisplay(
            $this->check_in_at !== null,
            $this->late_minutes
        ));
    }

    protected function earlyLeaveMinutesDisplay(): Attribute
    {
        return Attribute::get(fn (): ?int => $this->minutesForDisplay(
            $this->check_out_at !== null,
            $this->early_leave_minutes
        ));
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function checkInLog(): BelongsTo
    {
        return $this->belongsTo(AttendanceLog::class, 'check_in_log_id');
    }

    public function checkOutLog(): BelongsTo
    {
        return $this->belongsTo(AttendanceLog::class, 'check_out_log_id');
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_id');
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(NotificationLog::class);
    }

    public function isAutomaticAlpa(): bool
    {
        return $this->status === 'alpa'
            && ! $this->is_manual_override
            && $this->operator_id === null
            && $this->policy_version === self::POLICY_VERSION_AUTO_ALPA;
    }

    public function isManualAlpa(): bool
    {
        return $this->status === 'alpa'
            && ($this->is_manual_override || $this->operator_id !== null);
    }

    private function minutesForDisplay(bool $hasScan, ?int $minutes): ?int
    {
        if (! $hasScan || $minutes === null || in_array($this->status, ['alpa', 'izin', 'sakit'], true)) {
            return null;
        }

        return $minutes;
    }

    /**
     * @param  array<string, string>  $labels
     */
    private function labelFor(array $labels, ?string $value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        return $labels[$value] ?? ucfirst(str_replace('_', ' ', $value));
    }
}
