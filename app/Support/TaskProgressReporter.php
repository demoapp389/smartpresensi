<?php

namespace App\Support;

use App\Models\TaskProgress;
use Illuminate\Support\Str;

/**
 * Pembantu pencatatan progres untuk proses yang berjalan panjang di worker queue.
 *
 * Penulisan ke database di-throttle agar loop yang memproses ribuan item tidak
 * menambah hundreds query sia-sia. Status akhir (selesai/gagal) selalu ditulis
 * secara langsung supaya polling browser tidak pernah terjebak di state berjalan.
 */
final class TaskProgressReporter
{
    /**
     * Interval minimum antar penulisan progres, dalam detik.
     */
    private const THROTTLE_SECONDS = 0.4;

    /**
     * Jumlah item tiap penulisan paksa. Tanpa ini, job yang selesai sangat cepat
     * bisa menuliskan progres yang tertinggal jauh di belakang posisi sebenarnya.
     */
    private const FLUSH_EVERY = 10;

    private TaskProgress $task;

    private float $lastWriteAt = 0.0;

    private int $processed = 0;

    private bool $dirty = false;

    private function __construct(TaskProgress $task)
    {
        $this->task = $task;
        $this->processed = $task->processed;
    }

    /**
     * Buat task baru berstatus pending dan kembalikan reporter-nya.
     */
    public static function start(string $type, string $label, int $total = 0, ?int $userId = null): self
    {
        $task = TaskProgress::create([
            'uuid' => (string) Str::uuid(),
            'type' => $type,
            'label' => $label,
            'status' => TaskProgress::STATUS_PENDING,
            'total' => $total,
            'processed' => 0,
            'user_id' => $userId,
        ]);

        return new self($task);
    }

    /**
     * Lanjutkan pelacakan task yang sudah dibuat (dipakai worker yang menerima UUID).
     */
    public static function forTask(TaskProgress $task): self
    {
        return new self($task);
    }

    public function uuid(): string
    {
        return $this->task->uuid;
    }

    /**
     * Token akses untuk polling progres (lihat TaskProgress::signature()).
     */
    public function signature(): string
    {
        return $this->task->signature();
    }

    public function total(): int
    {
        return $this->task->total;
    }

    public function processed(): int
    {
        return $this->processed;
    }

    /**
     * Tetapkan jumlah total item yang akan diproses.
     *
     * Total diperbarui ke database segera karena ini yang menentukan
     * perhitungan persentase di progress bar.
     */
    public function setTotal(int $total, ?string $message = null): self
    {
        $this->task->total = $total;
        $this->task->status = TaskProgress::STATUS_RUNNING;
        $this->task->started_at ??= now();
        $this->flush($message);

        return $this;
    }

    /**
     * Naikkan jumlah item yang selesai dan tulis progres bila throttle sudah lewat.
     *
     * @param  string|null  $message  Keterangan singkat untuk ditampilkan di bawah bar.
     */
    public function advance(int $by = 1, ?string $message = null): self
    {
        $this->processed += $by;
        $this->task->processed = $this->processed;
        $this->task->status = TaskProgress::STATUS_RUNNING;
        $this->task->started_at ??= now();
        $this->dirty = true;

        if ($message !== null) {
            $this->task->message = Str::limit($message, 191, '');
        }

        $elapsed = microtime(true) - $this->lastWriteAt;

        if ($elapsed >= self::THROTTLE_SECONDS || $this->processed % self::FLUSH_EVERY === 0) {
            $this->flush();
        }

        return $this;
    }

    /**
     * Tulis progres yang tertunda ke database.
     *
     * Dipanggil otomatis oleh advance(), tapi tersedia untuk pemanggil yang
     * butuh nilai terkini secara deterministik.
     */
    public function flushProgress(?string $message = null): self
    {
        $this->flush($message);

        return $this;
    }

    /**
     * Tandai proses selesai dengan ringkasan hasil.
     *
     * @param  array<string, mixed>  $results
     */
    public function finish(array $results = [], ?string $message = null): self
    {
        $this->task->status = TaskProgress::STATUS_COMPLETED;
        $this->task->results = $results;
        $this->task->processed = $this->processed;
        $this->task->started_at ??= now();
        $this->task->finished_at = now();

        if ($message !== null) {
            $this->task->message = Str::limit($message, 191, '');
        }

        $this->persist();

        return $this;
    }

    /**
     * Tandai proses gagal. Selalu ditulis langsung, bukan throttled.
     */
    public function fail(string $message): self
    {
        $this->task->status = TaskProgress::STATUS_FAILED;
        $this->task->processed = $this->processed;
        $this->task->started_at ??= now();
        $this->task->finished_at = now();
        $this->task->message = Str::limit($message, 191, '');
        $this->persist();

        return $this;
    }

    private function flush(?string $message = null): void
    {
        if ($message !== null) {
            $this->task->message = Str::limit($message, 191, '');
        }

        if (! $this->dirty && $message === null) {
            return;
        }

        $this->persist();
    }

    private function persist(): void
    {
        $this->task->save();
        $this->lastWriteAt = microtime(true);
        $this->dirty = false;
    }
}
