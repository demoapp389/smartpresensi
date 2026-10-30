<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pencatatan progres untuk proses lama yang berjalan di luar siklus request
 * (mis. import Excel, sinkronisasi massal mesin fingerprint).
 *
 * Ditulis oleh worker queue, dibaca oleh browser lewat endpoint polling stateless.
 *
 * @property int $id
 * @property string $uuid
 * @property string $type
 * @property string $label
 * @property string $status
 * @property int $total
 * @property int $processed
 * @property string|null $message
 * @property array|null $results
 * @property int|null $user_id
 */
class TaskProgress extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'uuid',
        'type',
        'label',
        'status',
        'total',
        'processed',
        'message',
        'results',
        'user_id',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'results' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Token akses untuk endpoint polling progres.
     *
     * Diturunkan dari UUID + APP_KEY sehingga tidak dapat dihitung tanpa rahasia
     * server, dan tidak menambah kolom/relasi baru di database.
     */
    public function signature(): string
    {
        return hash_hmac('sha256', $this->uuid, (string) config('app.key'));
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_FAILED], true);
    }

    /**
     * Persentase penyelesaian 0-100. Proses tanpa total yang diketahui tetap
     * menampilkan 0% agar progress bar tidak pernah terlihat "selesai" palsu.
     */
    public function percent(): int
    {
        if ($this->isFinished()) {
            return 100;
        }

        if ($this->total <= 0) {
            return 0;
        }

        return (int) min(100, round($this->processed / $this->total * 100));
    }

    /**
     * @param  Builder<TaskProgress>  $query
     * @return Builder<TaskProgress>
     */
    public function scopeRecent(Builder $query, int $minutes = 60): Builder
    {
        return $query->where('created_at', '>=', now()->subMinutes($minutes));
    }
}
