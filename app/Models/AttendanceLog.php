<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceLog extends Model
{
    use HasFactory;

    public const STATUS_UNPROCESSED = 'unprocessed';

    public const STATUS_PROCESSED = 'processed';

    public const STATUS_UNMAPPED = 'unmapped';

    public const STATUS_FAILED = 'failed';

    public const STATUS_NEEDS_REVIEW = 'needs_review';

    public const STATUS_TIME_REJECTED = 'time_rejected';

    /**
     * Status log yang masih memiliki bukti scan belum tuntas, sehingga
     * Auto-Alpa untuk siswa terkait harus ditunda sampai log diselesaikan.
     */
    public const AUTO_ALPA_BLOCKING_STATUSES = [
        self::STATUS_UNPROCESSED,
        self::STATUS_UNMAPPED,
        self::STATUS_FAILED,
        self::STATUS_NEEDS_REVIEW,
    ];

    protected $fillable = [
        'device_id',
        'machine_user_id',
        'occurred_at',
        'received_at',
        'source_key',
        'status',
        'processing_note',
        'processing_attempts',
        'last_attempted_at',
        'next_retry_at',
        'raw_payload',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'received_at' => 'datetime',
        'processing_attempts' => 'integer',
        'last_attempted_at' => 'datetime',
        'next_retry_at' => 'datetime',
        'raw_payload' => 'array',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(FingerprintDevice::class, 'device_id');
    }
}
