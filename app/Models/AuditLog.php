<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    use HasFactory;
    use MassPrunable;

    /**
     * Aksi yang dijalankan tanpa sesi pengguna (antrean/command) tetapi tetap
     * merupakan inisiatif manusia, sehingga disimpan selama retensi normal.
     *
     * @var array<int, string>
     */
    public const HUMAN_INITIATED_SYSTEM_ACTIONS = [
        'import_students_xlsx',
        'create_device_mapping',
        'backup_database',
    ];

    public $timestamps = false;

    protected $fillable = [
        'actor_id',
        'action',
        'entity_type',
        'entity_id',
        'before',
        'after',
        'ip_address',
        'created_at',
    ];

    protected $casts = [
        'before' => 'array',
        'after' => 'array',
        'created_at' => 'datetime',
    ];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * Aksi yang murni dijalankan sistem tanpa campur tangan pengguna.
     */
    public function scopeSystemActions(Builder $query): Builder
    {
        return $query
            ->whereNull('actor_id')
            ->whereNotIn('action', self::HUMAN_INITIATED_SYSTEM_ACTIONS);
    }

    /**
     * Aksi yang dipicu pengguna, termasuk yang dieksekusi lewat antrean.
     */
    public function scopeHumanActions(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query->whereNotNull('actor_id')
                ->orWhereIn('action', self::HUMAN_INITIATED_SYSTEM_ACTIONS);
        });
    }

    /**
     * Baris yang boleh dipangkas otomatis oleh `model:prune`.
     */
    public function prunable(): Builder
    {
        $systemDays = max(1, (int) config('audit.system_retention_days', 30));
        $manualDays = max(1, (int) config('audit.retention_days', 365));

        return static::query()->where(function (Builder $query) use ($systemDays, $manualDays): void {
            $query->where(function (Builder $query) use ($systemDays): void {
                $query->systemActions()
                    ->where('created_at', '<', now()->subDays($systemDays));
            })->orWhere(function (Builder $query) use ($manualDays): void {
                $query->humanActions()
                    ->where('created_at', '<', now()->subDays($manualDays));
            });
        });
    }
}
