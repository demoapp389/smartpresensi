<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class FingerprintDevice extends Model
{
    use HasFactory;

    /**
     * Kebijakan token: setiap perangkat WAJIB punya `secret_token` agar dapat
     * melakukan ingest. Token dibuat otomatis bila belum diisi saat pembuatan.
     */
    protected static function booted(): void
    {
        static::creating(function (self $device): void {
            if (blank($device->secret_token)) {
                $device->secret_token = 'x601_sec_'.Str::random(24);
            }
        });
    }

    protected $fillable = [
        'name',
        'ip',
        'port',
        'comm_key',
        'timezone',
        'status',
        'secret_token',
        'last_seen_at',
        'active',
        'ingest_driver',
        'capabilities',
        'last_probe_at',
    ];

    protected $casts = [
        'port' => 'integer',
        'last_seen_at' => 'datetime',
        'active' => 'boolean',
        'capabilities' => 'array',
        'last_probe_at' => 'datetime',
    ];

    /**
     * Titik ingest yang seharusnya memproses mesin ini.
     * Null/kosong = 'connector' (jalur utama default) agar mesin lama tetap berjalan.
     */
    public function ingestDriver(): string
    {
        return $this->ingest_driver ?: 'connector';
    }

    /**
     * Apakah mesin ini punya kapabilitas tertentu hasil probe terakhir.
     */
    public function supports(string $capability): bool
    {
        return (bool) ($this->capabilities[$capability] ?? false);
    }

    /**
     * Tandai perangkat terverifikasi online (Heartbeat / Log Ingest / Tes Koneksi).
     * Status 'maintenance' (override administratif) tidak boleh ditimpa.
     */
    public function markOnline(): void
    {
        $attributes = ['last_seen_at' => now()];

        if ($this->status !== 'maintenance') {
            $attributes['status'] = 'active';
        }

        $this->update($attributes);
    }

    /**
     * Tandai perangkat offline ketika verifikasi riil gagal.
     * Status 'maintenance' (override administratif) tidak boleh ditimpa.
     */
    public function markOffline(): void
    {
        if ($this->status !== 'maintenance') {
            $this->update(['status' => 'offline']);
        }
    }

    /**
     * Status yang aman ditampilkan di UI.
     *
     * Status `active` hanya dipercaya selama heartbeat masih segar. Jika
     * `last_seen_at` sudah terlalu lama (mis. scheduler tidak berjalan), mesin
     * ditampilkan offline sampai probe berikutnya berhasil.
     */
    public function displayStatus(int $staleAfterMinutes = 2): string
    {
        if ($this->status === 'maintenance') {
            return 'maintenance';
        }

        if ($this->status !== 'active') {
            return 'offline';
        }

        if (! $this->last_seen_at || $this->last_seen_at->lt(now()->subMinutes($staleAfterMinutes))) {
            return 'offline';
        }

        return 'active';
    }

    /**
     * Pesan penolakan aksi perangkat ketika badge mesin sedang offline.
     */
    public function offlineActionMessage(string $subject = 'Mesin'): ?string
    {
        if ($this->displayStatus() !== 'offline') {
            return null;
        }

        return "{$subject} {$this->name} ({$this->ip}:{$this->port}) sedang offline. Nyalakan mesin dan pastikan terhubung ke jaringan sebelum melanjutkan.";
    }

    public function deviceUsers(): HasMany
    {
        return $this->hasMany(DeviceUser::class, 'device_id');
    }

    public function attendanceLogs(): HasMany
    {
        return $this->hasMany(AttendanceLog::class, 'device_id');
    }

    public function syncLogs(): HasMany
    {
        return $this->hasMany(DeviceSyncLog::class, 'device_id')->latest('id');
    }

    /**
     * Relasi ke satu log sinkronisasi terakhir, untuk ditampilkan pada kartu mesin.
     */
    public function latestSyncLog(): HasOne
    {
        return $this->hasOne(DeviceSyncLog::class, 'device_id')->latest('id');
    }
}
