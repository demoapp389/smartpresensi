<?php

namespace App\Services;

use App\Concerns\LogsAuditTrail;
use App\Models\DeviceUser;
use App\Models\FingerprintDevice;
use App\Models\Student;

/**
 * Logika pemetaan PIN mesin ke siswa.
 *
 * Memusatkan aturan unik/konflik PIN dan pencatatan audit pemetaan sehingga
 * controller cukup menangani validasi HTTP dan pengalihan respons.
 */
class DeviceMappingService
{
    use LogsAuditTrail;

    public function __construct(
        private readonly AttendanceLogProcessingService $logProcessor,
    ) {}

    /**
     * Simpan pemetaan PIN mesin ke siswa.
     *
     * PIN bersifat unik per mesin (device_id + machine_user_id), dan satu siswa
     * hanya boleh punya satu pemetaan per mesin. Pemetaan kedua untuk siswa yang
     * sama pada mesin yang sama ditolak agar tidak salah pasang. NIS tidak
     * digunakan sebagai identifier pemetaan.
     *
     * @return array{status: string, message: string}
     */
    public function store(FingerprintDevice $device, Student $student, string $pin): array
    {
        $deviceId = $device->id;

        // PIN yang sama pada mesin yang sama tidak boleh punya dua pemetaan.
        $existingForPin = DeviceUser::where('device_id', $deviceId)
            ->where('machine_user_id', $pin)
            ->first();

        if ($existingForPin) {
            // Idempoten: memetakan ulang PIN yang sama ke siswa yang sama bukan konflik.
            if ((int) $existingForPin->student_id === (int) $student->id) {
                $replayed = $this->logProcessor->processLogsForMapping($deviceId, $pin);
                $message = $this->appendReplaySummary(
                    "PIN {$pin} sudah terpetakan ke {$student->name}.",
                    $replayed
                );

                return ['status' => 'info', 'message' => $message];
            }

            return ['status' => 'error', 'message' => "PIN {$pin} sudah terpetakan pada mesin {$device->name}."];
        }

        // Satu siswa hanya boleh memiliki satu PIN pada mesin yang sama.
        $conflictingPin = DeviceUser::where('device_id', $deviceId)
            ->where('student_id', $student->id)
            ->value('machine_user_id');

        if ($conflictingPin !== null && $conflictingPin !== $pin) {
            return [
                'status' => 'error',
                'message' => "Konflik: {$student->name} sudah terpetakan pada PIN {$conflictingPin} di mesin {$device->name}. Lepas pemetaan lama terlebih dahulu.",
            ];
        }

        $before = DeviceUser::where('device_id', $deviceId)->where('student_id', $student->id)->first();

        if ($before) {
            // Siswa sudah terpetakan pada PIN lain di mesin ini: alihkan PIN-nya.
            $before->update(['machine_user_id' => $pin]);
            $mapping = $before;
        } else {
            $mapping = DeviceUser::create([
                'device_id' => $deviceId,
                'machine_user_id' => $pin,
                'student_id' => $student->id,
            ]);
        }

        $replayed = $this->logProcessor->processLogsForMapping($deviceId, $pin);

        $this->logMappingChange('create_device_mapping', $mapping, $device, [
            'student_name' => $student->name,
            'nis' => $student->nis,
            'previous_pin' => $before && $before->wasChanged('machine_user_id') ? $conflictingPin : null,
            'replayed_logs' => $replayed,
        ]);

        return [
            'status' => 'success',
            'message' => $this->appendReplaySummary(
                "PIN {$pin} berhasil dipetakan ke {$student->name}.",
                $replayed
            ),
        ];
    }

    /**
     * Ubah siswa yang terhubung pada sebuah pemetaan PIN.
     *
     * Nomor PIN pada mesin tidak diubah, dan tidak ada data biometrik yang
     * disentuh di server maupun di mesin.
     *
     * @return array{status: string, message: string}
     */
    public function reassign(DeviceUser $mapping, Student $student): array
    {
        $device = $mapping->device;

        if ($mapping->student_id === $student->id) {
            return ['status' => 'info', 'message' => 'Pemetaan tersebut sudah menunjuk ke siswa yang sama.'];
        }

        // Satu siswa hanya boleh terpetakan satu kali per mesin.
        if (DeviceUser::where('device_id', $mapping->device_id)
            ->where('student_id', $student->id)
            ->where('id', '!=', $mapping->id)
            ->exists()) {
            return [
                'status' => 'error',
                'message' => "{$student->name} sudah terpetakan pada mesin {$device->name}. Lepas pemetaan lama terlebih dahulu.",
            ];
        }

        $before = [
            'student_id' => $mapping->student_id,
            'student_name' => $mapping->student?->name,
        ];

        $mapping->update(['student_id' => $student->id]);

        $replayed = $this->logProcessor->processLogsForMapping(
            (int) $mapping->device_id,
            (string) $mapping->machine_user_id
        );

        $this->logMappingChange('update_device_mapping', $mapping, $device, [
            'student_name' => $student->name,
            'nis' => $student->nis,
            'before' => $before,
            'replayed_logs' => $replayed,
        ]);

        return [
            'status' => 'success',
            'message' => $this->appendReplaySummary(
                "Pemetaan PIN {$mapping->machine_user_id} kini terhubung ke {$student->name}.",
                $replayed
            ),
        ];
    }

    /**
     * Lepas pemetaan PIN dari siswa.
     *
     * Hanya relasi pemetaan di server yang dihapus. Data pengguna pada mesin
     * (termasuk sidik jari dan wajah) TIDAK dihapus atau diubah, sehingga
     * pemetaan dapat dipasang kembali tanpa perekaman ulang.
     *
     * @return array{status: string, message: string}
     */
    public function release(DeviceUser $mapping): array
    {
        $device = $mapping->device;
        $pin = $mapping->machine_user_id;
        $studentName = $mapping->student?->name ?? '-';

        $this->logMappingChange('delete_device_mapping', $mapping, $device, [
            'student_name' => $studentName,
            'machine_user_id' => $pin,
        ]);

        $mapping->delete();

        return ['status' => 'success', 'message' => "Pemetaan PIN {$pin} dari {$studentName} berhasil dilepas. Data pada mesin tidak diubah."];
    }

    /**
     * @param  array{processed: int, review: int, failed: int}  $replayed
     */
    protected function appendReplaySummary(string $message, array $replayed): string
    {
        if ($replayed['processed'] > 0) {
            $message .= " {$replayed['processed']} log diproses menjadi absensi.";
        }

        if ($replayed['review'] > 0) {
            $message .= " {$replayed['review']} log perlu tinjauan waktu.";
        }

        if ($replayed['failed'] > 0) {
            $message .= " {$replayed['failed']} log belum berhasil diproses.";
        }

        return $message;
    }

    /**
     * Catat perubahan pemetaan PIN ke audit log.
     *
     * @param  array<string, mixed>  $context
     */
    protected function logMappingChange(
        string $action,
        DeviceUser $mapping,
        ?FingerprintDevice $device,
        array $context = []
    ): void {
        $this->logAudit($action, 'DeviceUser', $mapping->id, $context['before'] ?? null, [
            'machine_user_id' => $mapping->machine_user_id,
            'device' => $device?->name,
        ] + $context);
    }
}
