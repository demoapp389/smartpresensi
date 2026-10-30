<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Pengguna yang terdaftar pada mesin fingerprint (hasil sinkronisasi).
 *
 * Berbeda dengan {@see DeviceUser} yang menyatakan pemetaan ke siswa, model ini
 * hanya menyatakan "PIN ini ada di mesin itu" beserta nama yang tersimpan di sana.
 */
class DeviceMachineUser extends Model
{
    use HasFactory;

    protected $table = 'device_machine_users';

    protected $fillable = [
        'device_id',
        'fingerprint_user_id',
        'machine_name',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'synced_at' => 'datetime',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(FingerprintDevice::class, 'device_id');
    }

    /**
     * Pemetaan siswa untuk pengguna mesin ini pada mesin yang sama.
     */
    public function mapping(): HasOne
    {
        return $this->hasOne(DeviceUser::class, 'device_id', 'device_id')
            ->whereColumn('device_users.machine_user_id', 'device_machine_users.fingerprint_user_id');
    }

    /**
     * Pengguna mesin yang belum dipetakan ke siswa mana pun.
     */
    public function scopeUnmapped(Builder $query): Builder
    {
        return $query->whereDoesntHave('mapping');
    }

    /**
     * Pengguna mesin yang sudah dipetakan ke siswa.
     */
    public function scopeMapped(Builder $query): Builder
    {
        return $query->whereHas('mapping');
    }
}
