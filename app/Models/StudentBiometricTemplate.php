<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentBiometricTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_id',
        'finger_index',
        'template_data',
        'template_size',
        'source_device_id',
    ];

    protected $casts = [
        'finger_index' => 'integer',
        'template_size' => 'integer',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function sourceDevice(): BelongsTo
    {
        return $this->belongsTo(FingerprintDevice::class, 'source_device_id');
    }
}
