<?php

namespace App\Services;

use App\Jobs\SendWhatsAppNotificationJob;
use App\Jobs\ValidateGuardianPhoneJob;
use App\Models\Attendance;
use App\Models\NotificationLog;

/**
 * Service untuk memproses outbox notifikasi WhatsApp ke wali siswa.
 * Sesuai PRD FR-11, FR-12, BR-05, BR-06:
 * - Menggunakan Idempotency Key unik (BR-06: No double send).
 * - Menangani verifikasi consent wali (FR-07, BR-05).
 * - Menyiapkan payload template utility untuk Meta Cloud API / Queue Worker.
 */
class WhatsAppNotificationService
{
    /**
     * Daftarkan notifikasi ke antrean (outbox).
     *
     * @param  string  $eventType  'check_in' | 'check_out' | 'late'
     * @return array List created NotificationLog records
     */
    public function queueAttendanceNotifications(Attendance $attendance, string $eventType): array
    {
        if ($attendance->is_manual_override) {
            return [];
        }

        $student = $attendance->student;
        if (! $student || ! $student->active) {
            return [];
        }

        // Ambil wali yang aktif dan telah memberikan persetujuan (consent)
        $guardians = $student->guardians()
            ->where('active', true)
            ->whereNotNull('consent_at')
            ->get();

        $queuedLogs = [];
        $seenPhones = [];

        foreach ($guardians as $guardian) {
            // Wali tanpa nomor WhatsApp tidak bisa menerima notifikasi.
            if (blank($guardian->wa_number)) {
                continue;
            }

            $phone = $guardian->wa_number;

            // Hemat kuota: beberapa wali (mis. Ayah & Ibu) bisa memakai nomor WA yang sama.
            // Cukup satu pesan per nomor per jenis event per hari (BR-06).
            if (isset($seenPhones[$phone])) {
                continue;
            }
            $seenPhones[$phone] = true;

            // Lindungi juga terhadap pemanggilan lintas-request: bila nomor yang sama sudah
            // pernah masuk outbox untuk absensi & event ini, lewati (tidak menambah kuota).
            $alreadyQueued = NotificationLog::where('attendance_id', $attendance->id)
                ->where('event_type', $eventType)
                ->where('recipient_phone', $phone)
                ->exists();

            if ($alreadyQueued) {
                continue;
            }

            // Idempotency key unik per attendance, wali, jenis event, dan tanggal sekolah (BR-06)
            $idempotencyKey = strtoupper($eventType)."_{$attendance->id}_{$guardian->id}_{$attendance->school_date->format('Ymd')}";

            $templateName = match ($eventType) {
                'check_in' => 'siswa_hadir_v1',
                'late' => 'siswa_terlambat_v1',
                'check_out' => 'siswa_pulang_v1',
                default => 'siswa_notifikasi_v1',
            };

            // Tentukan status awal berdasarkan validitas nomor WA wali (menghemat kuota)
            $initialStatus = match ($guardian->wa_status) {
                'valid' => 'queued',
                'invalid' => 'cancelled',
                default => 'held', // 'pending' atau 'unknown' ditahan sementara
            };

            $errorMessage = match ($guardian->wa_status) {
                'invalid' => 'Nomor WhatsApp wali tidak valid / tidak terdaftar di aplikasi WhatsApp. Pengiriman dilewati untuk menghemat kuota.',
                'pending', 'unknown' => 'Menunggu hasil validasi nomor WhatsApp orang tua sebelum pengiriman.',
                default => null,
            };

            // Pakai waktu scan siswa (masuk/pulang) agar pesan menampilkan jam absen sebenarnya,
            // bukan waktu outbox dibuat saat antrean tertunda.
            $scanTime = $eventType === 'check_out'
                ? $attendance->check_out_at
                : $attendance->check_in_at;
            $scanTime ??= now('Asia/Jakarta');
            $waktu = $scanTime->timezone('Asia/Jakarta')->format('H:i').' WIB';

            $log = NotificationLog::firstOrCreate(
                ['idempotency_key' => $idempotencyKey],
                [
                    'attendance_id' => $attendance->id,
                    'guardian_id' => $guardian->id,
                    'event_type' => $eventType,
                    'template_name' => $templateName,
                    'recipient_phone' => $guardian->wa_number,
                    'payload' => [
                        'nama_siswa' => $student->name,
                        'kelas' => $student->schoolClass?->name ?? '-',
                        'tanggal' => $attendance->school_date->format('d/m/Y'),
                        'waktu' => $waktu,
                        'status' => match ($eventType) {
                            'late' => 'Terlambat',
                            'check_in' => 'Hadir',
                            default => ucfirst($attendance->status),
                        },
                    ],
                    'status' => $initialStatus,
                    'attempts' => 0,
                    'error_message' => $errorMessage,
                    'queued_at' => $initialStatus === 'queued' ? now() : null,
                ]
            );

            if ($log->wasRecentlyCreated) {
                $queuedLogs[] = $log;

                // Hanya dispatch kirim jika nomor sudah valid
                if ($initialStatus === 'queued') {
                    SendWhatsAppNotificationJob::dispatch($log->id);
                } elseif ($initialStatus === 'held') {
                    // Jadwalkan validasi async jika wali belum pernah divalidasi
                    ValidateGuardianPhoneJob::dispatch($guardian->id, 'held_notification');
                }
            }
        }

        return $queuedLogs;
    }

    /**
     * Batalkan notifikasi yang belum terkirim setelah presensi dikoreksi manual.
     */
    public function cancelPendingForAttendance(Attendance $attendance, string $reason): int
    {
        return NotificationLog::query()
            ->where('attendance_id', $attendance->id)
            ->whereIn('status', ['queued', 'held', 'failed'])
            ->update([
                'status' => 'cancelled',
                'error_message' => $reason,
            ]);
    }
}
