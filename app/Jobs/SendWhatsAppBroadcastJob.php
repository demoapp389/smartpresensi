<?php

namespace App\Jobs;

use App\Models\Guardian;
use App\Models\NotificationLog;
use App\Models\Student;
use App\Models\TaskProgress;
use App\Models\WhatsAppTemplate;
use App\Services\WhatsApp\WhatsAppManager;
use App\Support\TaskProgressReporter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SendWhatsAppBroadcastJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    public int $tries = 1;

    /**
     * Satu siaran (progress_uuid) hanya boleh punya satu job aktif pada satu waktu.
     */
    public int $uniqueFor = 1800;

    public function uniqueId(): string
    {
        return $this->progressUuid;
    }

    /**
     * @param  list<int>|null  $classIds
     */
    public function __construct(
        public string $targetType,
        public ?array $classIds,
        public string $messageText,
        public string $progressUuid,
        public ?int $actorId = null
    ) {
        $this->onQueue('default');
    }

    public function handle(WhatsAppManager $waManager): void
    {
        $task = TaskProgress::where('uuid', $this->progressUuid)->first();
        $reporter = $task ? TaskProgressReporter::forTask($task) : null;

        // Ambil query siswa
        $studentsQuery = Student::query()
            ->with(['schoolClass', 'guardians' => function ($q) {
                $q->where('active', true)
                    ->where('wa_status', 'valid')
                    ->whereNotNull('wa_number');
            }])
            ->where('active', true);

        if ($this->targetType === 'class' && ! empty($this->classIds)) {
            $studentsQuery->whereIn('class_id', $this->classIds);
        }

        $students = $studentsQuery->get();

        // Kumpulkan daftar penerima unik (1 pesan per nomor telepon)
        $recipients = [];
        foreach ($students as $student) {
            foreach ($student->guardians as $guardian) {
                $phone = $guardian->wa_number;
                if (blank($phone)) {
                    continue;
                }

                if (! isset($recipients[$phone])) {
                    $recipients[$phone] = [
                        'guardian' => $guardian,
                        'student' => $student,
                    ];
                }
            }
        }

        $totalRecipients = count($recipients);

        if ($reporter) {
            if ($totalRecipients === 0) {
                $reporter->finish([
                    'total' => 0,
                    'sent' => 0,
                    'failed' => 0,
                ], 'Tidak ada nomor WhatsApp wali yang valid pada target terpilih.');

                return;
            }

            $reporter->setTotal($totalRecipients, "Mengirim siaran pengumuman ke {$totalRecipients} wali murid...");
        }

        $driver = $waManager->driver();
        $sentCount = 0;
        $failedCount = 0;

        foreach ($recipients as $phone => $item) {
            /** @var Guardian $guardian */
            $guardian = $item['guardian'];
            /** @var Student $student */
            $student = $item['student'];

            $idempotencyKey = 'BROADCAST_'.$this->progressUuid.'_'.$guardian->id;

            // Render pesan menggunakan template broadcast
            $template = WhatsAppTemplate::getTemplate('broadcast');
            $schoolName = config('whatsapp.school_name', 'Sekolah');

            $fullMessage = str_replace(
                [':nama_siswa', ':kelas', ':nama_sekolah', ':nama_wali', ':pesan', ':tanggal', ':waktu'],
                [
                    $student->name,
                    $student->schoolClass?->name ?? '-',
                    $schoolName,
                    $guardian->name ?? 'Bapak/Ibu',
                    $this->messageText,
                    now()->format('d/m/Y'),
                    now()->format('H:i').' WIB',
                ],
                $template
            );

            // Simpan log ke NotificationLog secara idempoten. Kolom idempotency_key memiliki
            // unique index, sehingga bila job dijalankan ulang (mis. setelah restart karena
            // timeout/retry) kita tidak membuat baris ganda.
            $notificationLog = NotificationLog::firstOrCreate(
                ['idempotency_key' => $idempotencyKey],
                [
                    'attendance_id' => null,
                    'guardian_id' => $guardian->id,
                    'event_type' => 'broadcast',
                    'template_name' => 'siaran_sekolah',
                    'recipient_phone' => $phone,
                    'status' => 'queued',
                    'attempts' => 1,
                    'queued_at' => now(),
                    'payload' => [
                        'nama_siswa' => $student->name,
                        'kelas' => $student->schoolClass?->name ?? '-',
                        'nama_wali' => $guardian->name ?? 'Bapak/Ibu',
                        'pesan' => $this->messageText,
                        'full_message' => $fullMessage,
                        'task_uuid' => $this->progressUuid,
                    ],
                ]
            );

            // Klaim per baris outbox: dua instance job (mis. restart setelah timeout)
            // tidak boleh mengirim nomor yang sama secara bersamaan.
            $lock = Cache::lock('whatsapp-broadcast:'.$notificationLog->id, 120);

            if (! $lock->get()) {
                if ($reporter) {
                    $reporter->advance(1, "Lewati {$guardian->name} ({$phone}) — sedang diproses instance lain.");
                }

                continue;
            }

            try {
                $notificationLog->refresh();

                // Bila eksekusi lain sudah menyelesaikan pesan ini, lewati agar tidak
                // terkirim ulang ke wali yang sama saat job di-restart.
                if (in_array($notificationLog->status, ['sent', 'delivered', 'read'], true)) {
                    $sentCount++;

                    if ($reporter) {
                        $reporter->advance(1, "Lewati {$guardian->name} ({$phone}) — sudah terkirim.");
                    }

                    continue;
                }

                if ($notificationLog->status === 'cancelled') {
                    if ($reporter) {
                        $reporter->advance(1, "Lewati {$guardian->name} ({$phone}) — dibatalkan.");
                    }

                    continue;
                }

                // Kirim pesan via driver gateway
                try {
                    $res = $driver->sendMessage($notificationLog);

                    if (! empty($res['success'])) {
                        $notificationLog->update([
                            'status' => 'sent',
                            'sent_at' => now(),
                            'provider_message_id' => $res['message_id'] ?? null,
                        ]);
                        $sentCount++;
                    } else {
                        $notificationLog->update([
                            'status' => 'failed',
                            'error_message' => $res['error'] ?? 'Pengiriman gagal dari gateway.',
                        ]);
                        $failedCount++;
                    }
                } catch (\Throwable $e) {
                    Log::error('[Broadcast WA Exception] '.$e->getMessage(), [
                        'recipient' => $phone,
                        'guardian_id' => $guardian->id,
                    ]);

                    $notificationLog->update([
                        'status' => 'failed',
                        'error_message' => $e->getMessage(),
                    ]);
                    $failedCount++;
                }
            } finally {
                $lock->release();
            }

            if ($reporter) {
                $reporter->advance(1, "Mengirim ke {$guardian->name} ({$phone})...");
            }

            // Jeda mikro 50ms untuk menghindari rate limit provider
            usleep(50000);
        }

        if ($reporter) {
            $reporter->finish([
                'total' => $totalRecipients,
                'sent' => $sentCount,
                'failed' => $failedCount,
            ], "Selesai. {$sentCount} pesan terkirim".($failedCount > 0 ? ", {$failedCount} gagal." : '.'));
        }
    }
}
