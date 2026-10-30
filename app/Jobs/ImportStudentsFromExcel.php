<?php

namespace App\Jobs;

use App\Concerns\LogsAuditTrail;
use App\Models\DeviceUser;
use App\Models\Guardian;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\TaskProgress;
use App\Services\AttendanceLogProcessingService;
use App\Services\SimpleXlsxReader;
use App\Support\TaskProgressReporter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class ImportStudentsFromExcel implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, LogsAuditTrail, Queueable, SerializesModels;

    /**
     * Import thousands of rows dapat memakan waktu lama, jadi diberi timeout longgar.
     */
    public int $timeout = 1800;

    public int $tries = 1;

    /**
     * Pesan kesalahan per baris, dibatasi agar hasil progres tidak membengkak.
     *
     * @var array<int, string>
     */
    protected array $rowErrors = [];

    public function __construct(
        public string $storedPath,
        public string $originalName,
        public ?int $deviceId,
        public ?int $userId,
        public ?string $ipAddress,
        public string $progressUuid,
    ) {
        // Antrean terpisah supaya proses panjang tidak memblokir notifikasi WhatsApp.
        $this->onQueue('long-running');
    }

    public function handle(): void
    {
        $reporter = TaskProgressReporter::forTask($this->loadTask());
        $this->rowErrors = [];

        try {
            $rows = SimpleXlsxReader::parse($this->storedPath);

            if (count($rows) <= 1) {
                $reporter->fail('File Excel kosong atau hanya berisi baris header.');

                return;
            }

            // Baris pertama adalah header judul kolom.
            $totalRows = count($rows) - 1;
            $reporter->setTotal($totalRows, "Memproses {$totalRows} baris data...");

            $results = $this->processUpsertRows($rows, $reporter);
            $message = $this->upsertMessage($results);

            $this->logAudit('import_students_xlsx', 'Student', 0, null, [
                ...$results,
                'errors' => array_slice($this->rowErrors, 0, 20),
            ], $this->userId, $this->ipAddress);

            $finalResults = $results;

            if ($this->rowErrors !== []) {
                $finalResults['errors'] = array_slice($this->rowErrors, 0, 20);
            }

            $reporter->finish($finalResults, $message.'.');
        } catch (\Throwable $e) {
            Log::error('[ImportStudentsFromExcel] Import gagal', [
                'file' => $this->originalName,
                'error' => $e->getMessage(),
            ]);

            $reporter->fail('Import gagal: '.$e->getMessage());
        } finally {
            $this->cleanupUploadedFile();
        }
    }

    /**
     * Import normal (tambah/perbarui siswa). Setiap baris memakai transaksi
     * sendiri agar satu baris bermasalah tidak membatalkan baris lainnya.
     *
     * @param  array<int, array<int, mixed>>  $rows
     * @return array{created: int, updated: int, mapped: int, skipped: int}
     */
    protected function processUpsertRows(array $rows, TaskProgressReporter $reporter): array
    {
        $created = 0;
        $updated = 0;
        $mapped = 0;
        $skipped = 0;
        $totalRows = count($rows);

        for ($i = 1; $i < $totalRows; $i++) {
            try {
                $outcome = DB::transaction(fn () => $this->processRow($rows[$i], $i + 1));
            } catch (\Throwable $e) {
                $this->recordRowError($i + 1, 'Gagal diproses: '.$e->getMessage());
                $outcome = 'skipped';
            }

            if (in_array($outcome, ['created', 'updated', 'mapped'], true)) {
                $this->replayMappingLogs($rows[$i]);
            }

            match ($outcome) {
                'created' => $created++,
                'updated' => $updated++,
                'mapped' => $mapped++,
                default => $skipped++,
            };

            $reporter->advance();
        }

        return [
            'created' => $created,
            'updated' => $updated,
            'mapped' => $mapped,
            'skipped' => $skipped,
        ];
    }

    /**
     * @param  array{created: int, updated: int, mapped: int, skipped: int}  $results
     */
    protected function upsertMessage(array $results): string
    {
        $message = "Import selesai! {$results['created']} siswa baru, {$results['updated']} diperbarui";

        if ($results['mapped'] > 0) {
            $message .= ", {$results['mapped']} pemetaan ID Mesin";
        }

        if ($results['skipped'] > 0) {
            $message .= " ({$results['skipped']} baris dilewati)";
        }

        return $message;
    }

    /**
     * Proses satu baris Excel.
     *
     * @param  array<int, mixed>  $row
     * @return string created|updated|mapped|skipped
     */
    protected function processRow(array $row, int $rowNumber = 0): string
    {
        $nis = isset($row[0]) ? trim((string) $row[0]) : '';
        $nisn = isset($row[1]) ? trim((string) $row[1]) : '';
        $name = isset($row[2]) ? trim((string) $row[2]) : '';
        $rawGender = isset($row[3]) ? strtoupper(trim((string) $row[3])) : '';
        $className = isset($row[4]) ? trim((string) $row[4]) : '';
        $guardianName = isset($row[5]) ? trim((string) $row[5]) : '';
        $relationship = isset($row[6]) ? trim((string) $row[6]) : '';
        $rawWa = isset($row[7]) ? trim((string) $row[7]) : '';
        $machineUserId = isset($row[8]) ? trim((string) $row[8]) : '';
        $rawConsent = isset($row[9]) ? trim((string) $row[9]) : '';
        $consent = $this->parseConsent($rawConsent);

        if ($rawConsent !== '' && $consent === null) {
            $this->recordRowError($rowNumber, 'Nilai Persetujuan WA harus Ya atau Tidak.');
        }

        if ($name === '' && $machineUserId === '') {
            return 'skipped';
        }

        if ($nis !== '' && mb_strlen($nis) > 30) {
            $this->recordRowError($rowNumber, 'NIS maksimal 30 karakter.');

            return 'skipped';
        }

        if ($machineUserId !== '' && mb_strlen($machineUserId) > 50) {
            $this->recordRowError($rowNumber, 'PIN Mesin maksimal 50 karakter.');

            return 'skipped';
        }

        $student = $this->resolveStudent($nis, $machineUserId);

        // Baris tanpa Nama diperlakukan sebagai pemetaan ID Mesin saja: profil
        // siswa maupun data wali tidak disentuh.
        if ($name === '') {
            if (! $student) {
                $this->recordRowError($rowNumber, $nis === ''
                    ? 'Baris tanpa Nama Siswa dan NIS tidak dapat diproses.'
                    : "Siswa dengan NIS {$nis} tidak ditemukan untuk pemetaan ID Mesin.");

                return 'skipped';
            }

            return $this->syncDeviceMapping($student, $machineUserId, $rowNumber) ? 'mapped' : 'skipped';
        }

        // Siswa baru wajib punya NIS agar tidak menumpuk data duplikat.
        if (! $student && $nis === '') {
            $this->recordRowError($rowNumber, 'NIS wajib diisi untuk membuat siswa baru.');

            return 'skipped';
        }

        $gender = null;
        if (str_starts_with($rawGender, 'P') || $rawGender === 'PEREMPUAN') {
            $gender = 'P';
        } elseif (str_starts_with($rawGender, 'L') || $rawGender === 'LAKI-LAKI' || $rawGender === 'PRIA') {
            $gender = 'L';
        }

        $classId = null;
        if ($className !== '') {
            $class = SchoolClass::firstOrCreate(
                ['name' => $className],
                ['academic_year' => date('Y').'/'.(date('Y') + 1)]
            );
            $classId = $class->id;
        }

        $cleanWa = $this->normalizeWhatsApp($rawWa);

        if ($student) {
            $attributes = [
                'nisn' => $nisn !== '' ? $nisn : $student->nisn,
                'name' => $name,
                'gender' => $gender ?? $student->gender,
                'class_id' => $classId ?? $student->class_id,
                'active' => true,
            ];

            // Siswa yang ditemukan lewat pemetaan ID Mesin: NIS lama diganti
            // dengan NIS file bila tersedia dan belum dipakai siswa lain.
            if ($nis !== '' && $student->nis !== $nis) {
                if ($this->nisTakenByOther($nis, $student->id)) {
                    $this->recordRowError($rowNumber, "NIS {$nis} sudah dipakai siswa lain.");
                } else {
                    $attributes['nis'] = $nis;
                }
            }

            $student->update($attributes);
            $outcome = 'updated';
        } else {
            $student = Student::create([
                'nis' => $nis,
                'nisn' => $nisn !== '' ? $nisn : null,
                'name' => $name,
                'gender' => $gender ?? 'L',
                'class_id' => $classId,
                'active' => true,
            ]);
            $outcome = 'created';
        }

        $this->syncGuardian($student, $guardianName, $relationship, $cleanWa, $name, $consent, $rowNumber);
        $this->syncDeviceMapping($student, $machineUserId, $rowNumber);

        return $outcome;
    }

    /**
     * Temukan siswa yang sudah ada untuk baris ini.
     *
     * Prioritas: NIS lebih dulu; bila tidak ketemu dan mesin terpilih, cari
     * lewat pemetaan ID Mesin (PIN) pada mesin tersebut. Dengan begitu baris
     * yang memuat PIN siswa lama akan memperbarui siswa itu, bukan membuat baru.
     */
    protected function resolveStudent(string $nis, string $machineUserId): ?Student
    {
        if ($nis !== '') {
            $student = Student::where('nis', $nis)->first();

            if ($student) {
                return $student;
            }
        }

        if ($machineUserId !== '' && ! empty($this->deviceId)) {
            $deviceUser = DeviceUser::where('device_id', $this->deviceId)
                ->where('machine_user_id', $machineUserId)
                ->first();

            if ($deviceUser?->student) {
                return $deviceUser->student;
            }
        }

        return null;
    }

    /**
     * Proses ulang log lama setelah baris import berhasil membuat pemetaan PIN.
     * Hanya log untuk PIN dan siswa yang benar-benar terpetakan yang diproses.
     *
     * @param  array<int, mixed>  $row
     */
    protected function replayMappingLogs(array $row): void
    {
        if (empty($this->deviceId)) {
            return;
        }

        $nis = isset($row[0]) ? trim((string) $row[0]) : '';
        $machineUserId = isset($row[8]) ? trim((string) $row[8]) : '';

        if ($machineUserId === '') {
            return;
        }

        $student = $this->resolveStudent($nis, $machineUserId);

        if (! $student) {
            return;
        }

        $mappingExists = DeviceUser::query()
            ->where('device_id', $this->deviceId)
            ->where('machine_user_id', $machineUserId)
            ->where('student_id', $student->id)
            ->exists();

        if (! $mappingExists) {
            return;
        }

        try {
            app(AttendanceLogProcessingService::class)->processLogsForMapping(
                $this->deviceId,
                $machineUserId,
            );
        } catch (\Throwable $e) {
            Log::warning('[ImportStudentsFromExcel] Gagal memproses ulang log pemetaan.', [
                'device_id' => $this->deviceId,
                'machine_user_id' => $machineUserId,
                'student_id' => $student->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Cek apakah NIS sudah dipakai siswa lain (termasuk yang ada di Sampah,
     * karena kolom unik tetap menahannya).
     */
    protected function nisTakenByOther(string $nis, int $ignoreStudentId): bool
    {
        return Student::withTrashed()
            ->where('nis', $nis)
            ->where('id', '!=', $ignoreStudentId)
            ->exists();
    }

    protected function recordRowError(int $rowNumber, string $message): void
    {
        if (count($this->rowErrors) >= 20) {
            return;
        }

        $this->rowErrors[] = "Baris {$rowNumber}: {$message}";
    }

    protected function syncGuardian(
        Student $student,
        string $guardianName,
        string $relationship,
        string $cleanWa,
        string $studentName,
        ?bool $consent,
        int $rowNumber = 0,
    ): void {
        $guardian = $student->guardians()->first();

        $finalName = $guardianName !== '' ? $guardianName : ($guardian?->name ?? 'Wali '.$studentName);
        $finalRelationship = $relationship !== '' ? $relationship : ($guardian?->relationship ?? 'Wali');
        $finalWa = $cleanWa !== '' ? $cleanWa : ($guardian?->wa_number ?? '');

        if ($guardian) {
            $consentAt = $guardian->consent_at;

            if ($consent === true) {
                if ($finalWa === '') {
                    $this->recordRowError($rowNumber, 'Persetujuan WA diisi Ya tetapi nomor WhatsApp kosong.');
                    $consentAt = null;
                } else {
                    $consentAt ??= now();
                }
            } elseif ($consent === false) {
                $consentAt = null;
            } elseif ($cleanWa !== '' && $cleanWa !== $guardian->wa_number) {
                $consentAt = null;
            }

            $guardian->update([
                'name' => $finalName,
                'relationship' => $finalRelationship,
                'wa_number' => $finalWa,
                'consent_at' => $consentAt,
                'active' => true,
            ]);

            return;
        }

        if ($consent === true && $finalWa === '') {
            $this->recordRowError($rowNumber, 'Persetujuan WA diisi Ya tetapi nomor WhatsApp kosong.');
        }

        Guardian::create([
            'student_id' => $student->id,
            'name' => $finalName,
            'relationship' => $finalRelationship,
            'wa_number' => $finalWa,
            'consent_at' => $consent === true && $finalWa !== '' ? now() : null,
            'active' => true,
        ]);
    }

    protected function parseConsent(string $value): ?bool
    {
        return match (mb_strtolower(trim($value))) {
            'ya', 'y', 'ya?', '1', 'true' => true,
            'tidak', 't', '0', 'false' => false,
            default => null,
        };
    }

    /**
     * Petakan PIN ke siswa dengan aturan yang sama seperti halaman Pemetaan.
     *
     * @return bool true bila pemetaan berhasil (atau sudah ada), false bila ditolak.
     */
    protected function syncDeviceMapping(Student $student, string $machineUserId, int $rowNumber = 0): bool
    {
        if ($machineUserId === '') {
            return true;
        }

        if (empty($this->deviceId)) {
            $this->recordRowError(
                $rowNumber,
                "Mesin fingerprint belum dipilih, PIN {$machineUserId} tidak dipetakan."
            );

            return false;
        }

        $existingForPin = DeviceUser::where('device_id', $this->deviceId)
            ->where('machine_user_id', $machineUserId)
            ->first();

        if ($existingForPin) {
            // Idempoten: siswa yang sama dengan PIN yang sama bukan konflik.
            if ((int) $existingForPin->student_id === (int) $student->id) {
                return true;
            }

            $this->recordRowError(
                $rowNumber,
                "PIN {$machineUserId} sudah dipakai siswa lain pada mesin ini."
            );

            return false;
        }

        // Satu siswa hanya boleh punya satu PIN per mesin. Menggantinya harus
        // lewat pelepasan pemetaan lama agar konsisten dengan halaman Pemetaan.
        $existingForStudent = DeviceUser::where('device_id', $this->deviceId)
            ->where('student_id', $student->id)
            ->first();

        if ($existingForStudent) {
            $this->recordRowError(
                $rowNumber,
                "{$student->name} sudah terpetakan pada PIN {$existingForStudent->machine_user_id} di mesin ini. Lepas pemetaan lama terlebih dahulu."
            );

            return false;
        }

        $mapping = DeviceUser::create([
            'device_id' => $this->deviceId,
            'machine_user_id' => $machineUserId,
            'student_id' => $student->id,
        ]);

        $this->logAudit('create_device_mapping', 'DeviceUser', $mapping->id, null, [
            'device_id' => $this->deviceId,
            'machine_user_id' => $machineUserId,
            'student_id' => $student->id,
            'student_name' => $student->name,
            'nis' => $student->nis,
            'source' => 'import_students_xlsx',
        ], $this->userId, $this->ipAddress);

        return true;
    }

    /**
     * Nomor WhatsApp wali dinormalisasi ke awalan 62. Kolom kosong tetap kosong;
     * tidak ada nomor placeholder yang dibuat.
     */
    protected function normalizeWhatsApp(string $rawWa): string
    {
        $clean = preg_replace('/[^0-9]/', '', $rawWa);

        if ($clean === '') {
            return '';
        }

        if (str_starts_with($clean, '0')) {
            return '62'.substr($clean, 1);
        }

        if (! str_starts_with($clean, '62')) {
            return '62'.$clean;
        }

        return $clean;
    }

    protected function loadTask(): TaskProgress
    {
        return TaskProgress::where('uuid', $this->progressUuid)->firstOrFail();
    }

    protected function cleanupUploadedFile(): void
    {
        if ($this->storedPath && is_file($this->storedPath) && ! File::delete($this->storedPath)) {
            Log::warning('[ImportStudentsFromExcel] Gagal menghapus berkas sementara.', [
                'path' => $this->storedPath,
            ]);
        }
    }
}
