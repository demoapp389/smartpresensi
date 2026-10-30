<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceSyncLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'device_id',
        'sync_type',
        'status',
        'records_count',
        'message',
        'details',
        'operator_id',
    ];

    protected $casts = [
        'details' => 'array',
        'records_count' => 'integer',
        'created_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(FingerprintDevice::class, 'device_id');
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_id');
    }
}
