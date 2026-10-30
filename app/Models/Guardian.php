<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Guardian extends Model
{
    use HasFactory;

    protected $attributes = [
        'wa_status' => 'pending',
    ];

    protected $fillable = [
        'student_id',
        'name',
        'relationship',
        'wa_number',
        'wa_status',
        'wa_validated_at',
        'wa_validation_error',
        'consent_at',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'wa_validated_at' => 'datetime',
            'consent_at' => 'datetime',
            'active' => 'boolean',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function notificationLogs(): HasMany
    {
        return $this->hasMany(NotificationLog::class);
    }

    public function validationLogs(): HasMany
    {
        return $this->hasMany(GuardianPhoneValidationLog::class);
    }
}
