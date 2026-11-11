<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

class NotificationLog extends Model
{
    use HasFactory;
    use MassPrunable;

    /**
     * Status akhir yang tidak boleh ditimpa oleh retry job atau event webhook
     * yang datang terlambat (mis. `delivered` tidak boleh kembali `sent`/`failed`).
     *
     * @var array<int, string>
     */
    public const FINAL_STATUSES = ['sent', 'delivered', 'read', 'cancelled'];

    protected $fillable = [
        'attendance_id',
        'guardian_id',
        'event_type',
        'idempotency_key',
        'template_name',
        'recipient_phone',
        'payload',
        'status',
        'attempts',
        'error_message',
        'provider_message_id',
        'queued_at',
        'sent_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'attempts' => 'integer',
        'queued_at' => 'datetime',
        'sent_at' => 'datetime',
    ];

    public function attendance(): BelongsTo
    {
        return $this->belongsTo(Attendance::class);
    }

    public function guardian(): BelongsTo
    {
        return $this->belongsTo(Guardian::class);
    }

    /**
     * Baris outbox yang boleh dipangkas otomatis oleh `model:prune`.
     *
     * Status gagal disimpan lebih lama agar masih bisa ditelusuri operator,
     * sedangkan status lain cukup satu bulan.
     */
    public function prunable(): Builder
    {
        $days = max(1, (int) config('whatsapp.retention.days', 30));
        $failedDays = max(1, (int) config('whatsapp.retention.failed_days', 90));

        return static::query()->where(function (Builder $query) use ($days, $failedDays): void {
            $query->where(function (Builder $query) use ($days): void {
                $query->where('status', '!=', 'failed')
                    ->where('created_at', '<', now()->subDays($days));
            })->orWhere(function (Builder $query) use ($failedDays): void {
                $query->where('status', 'failed')
                    ->where('created_at', '<', now()->subDays($failedDays));
            });
        });
    }

    /**
     * Hitung jumlah log per status dalam satu query agregat.
     *
     * Dipakai bersama oleh dasbor dan halaman riwayat notifikasi agar tidak
     * ada kueri `count()` berulang per status (PERF-06/DUP-03).
     *
     * @return Collection<string, int> Jumlah per status, di-key oleh nilai status.
     */
    public static function statusCounts(?string $date = null): Collection
    {
        return static::query()
            ->when($date !== null, fn ($query) => $query->whereDate('created_at', $date))
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');
    }
}
