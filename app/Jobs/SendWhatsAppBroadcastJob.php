<?php

namespace App\Jobs;

use App\Models\Guardian;
use App\Models\NotificationLog;
use App\Models\Student;
use App\Models\TaskProgress;
use App\Models\WhatsAppTemplate;
use App\Services\AttendanceStatusResolver;
use App\Services\WhatsApp\WhatsAppSendPacer;
use App\Support\TaskProgressReporter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Perencana siaran pengumuman WhatsApp.
 *
 * Job ini tidak mengirim pesan satu per satu di dalam satu proses panjang.
 * Seluruh penerima didaftarkan sebagai baris outbox, lalu tiap pesan dikirim
 * oleh SendWhatsAppNotificationJob dengan jeda aman bertingkat. Dengan begitu
 * queue tidak tertahan berjam-jam dan notifikasi absensi tetap terkirim.
 */
class SendWhatsAppBroadcastJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;

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
     * @param  string  $recipientScope  'all' (semua siswa) atau 'late' (hanya siswa terlambat hari ini)
     */
    public function __construct(
        public string $targetType,
        public ?array $classIds,
        public string $messageText,
        public string $progressUuid,
        public ?int $actorId = null,
        public string $recipientScope = 'all',
    ) {
        $this->onQueue('default');
    }

    public function handle(WhatsAppSendPacer $pacer): void
    {
        $task = TaskProgress::where('uuid', $this->progressUuid)->first();
        $reporter = $task ? TaskProgressReporter::forTask($task) : null;

        $students = $this->studentsQuery()->get();

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

        if ($totalRecipients === 0) {
            $reporter?->finish([
                'total' => 0,
                'sent' => 0,
                'failed' => 0,
            ], $this->recipientScope === 'late'
                ? 'Tidak ada siswa terlambat hari ini pada target terpilih yang memiliki nomor WhatsApp wali valid.'
                : 'Tidak ada nomor WhatsApp wali yang valid pada target terpilih.');

            return;
        }

        $estimatedMinutes = (int) ceil($totalRecipients * $pacer->averageGapSeconds() / 60);

        $reporter?->setTotal(
            $totalRecipients,
            "Siaran dijadwalkan ke {$totalRecipients} wali murid (jeda aman, estimasi ±{$estimatedMinutes} menit)..."
        );

        $template = WhatsAppTemplate::getTemplate('broadcast');
        $schoolName = config('whatsapp.school_name', 'Sekolah');
        $now = now();
        $logs = [];

        foreach ($recipients as $phone => $item) {
            /** @var Guardian $guardian */
            $guardian = $item['guardian'];
            /** @var Student $student */
            $student = $item['student'];

            $idempotencyKey = 'BROADCAST_'.$this->progressUuid.'_'.$guardian->id;

            $fullMessage = str_replace(
                [':nama_siswa', ':kelas', ':nama_sekolah', ':nama_wali', ':pesan', ':tanggal', ':waktu'],
                [
                    $student->name,
                    $student->schoolClass?->name ?? '-',
                    $schoolName,
                    $guardian->name ?? 'Bapak/Ibu',
                    $this->messageText,
                    $now->format('d/m/Y'),
                    $now->format('H:i').' WIB',
                ],
                $template
            );

            // Idempoten: job yang dijalankan ulang tidak membuat baris ganda.
            $notificationLog = NotificationLog::firstOrCreate(
                ['idempotency_key' => $idempotencyKey],
                [
                    'attendance_id' => null,
                    'guardian_id' => $guardian->id,
                    'event_type' => 'broadcast',
                    'template_name' => 'siaran_sekolah',
                    'recipient_phone' => $phone,
                    'status' => 'queued',
                    'attempts' => 0,
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

            $logs[] = $notificationLog;
        }

        // Jadwalkan satu pesan per penerima dengan jeda bertingkat. Beban queue
        // tetap ringan: tiap pesan adalah job pendek, bukan satu job panjang.
        $offsetSeconds = 0;

        foreach ($logs as $log) {
            $offsetSeconds += $pacer->nextGapSeconds();
            $scheduledAt = now()->addSeconds($offsetSeconds);

            // `queued_at` diset ke waktu kirim terjadwal agar sapuan
            // `whatsapp:sweep-queued` tidak menganggapnya menggantung.
            $log->forceFill(['queued_at' => $scheduledAt])->save();

            SendWhatsAppNotificationJob::dispatch($log->id)->delay($scheduledAt);
        }
    }

    /**
     * @return Builder<Student>
     */
    private function studentsQuery(): Builder
    {
        $query = Student::query()
            ->with(['schoolClass', 'guardians' => function ($q) {
                $q->where('active', true)
                    ->where('wa_status', 'valid')
                    ->whereNotNull('wa_number');
            }])
            ->where('active', true);

        if ($this->recipientScope === 'late') {
            $query->whereHas('attendances', function ($q): void {
                $q->whereDate('school_date', now('Asia/Jakarta')->toDateString())
                    ->where('check_in_status', AttendanceStatusResolver::CHECK_IN_LATE);
            });
        }

        if ($this->targetType === 'class' && ! empty($this->classIds)) {
            $query->whereIn('class_id', $this->classIds);
        }

        return $query;
    }
}
