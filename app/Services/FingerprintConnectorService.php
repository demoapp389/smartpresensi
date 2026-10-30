<?php

namespace App\Services;

use App\Models\AttendanceLog;
use App\Models\DeviceMachineUser;
use App\Models\DeviceSyncLog;
use App\Models\DeviceUser;
use App\Models\FingerprintDevice;
use App\Models\Student;
use App\Support\TaskProgressReporter;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Service untuk mengelola interaksi antara Laravel dan connector Solution X601 (LAN).
 * Sesuai ketentuan modul_finger.md:
 * - Komunikasi melalui API lokal / CLI read-only tanpa memerlukan CMD manual oleh admin.
 * - Tes koneksi, Sinkronisasi Siswa, dan Sinkronisasi Absensi secara otomatis.
 * - Menangani deduplikasi data siswa dan pencatatan riwayat sinkronisasi.
 */
class FingerprintConnectorService
{
    protected string $pythonBinary;

    protected string $entrypoint;

    protected ?string $legacyExePath;

    protected string $localApiUrl;

    protected string $localApiToken;

    /**
     * Keluarga OS yang dipakai untuk menentukan urutan prioritas connector.
     * Disuntik dari luar agar dapat diuji tanpa bergantung pada OS host.
     */
    protected string $platform;

    /**
     * Driver connector utama: 'auto' | 'csharp' | 'python'.
     */
    protected string $primaryDriver;

    protected DeviceSyncLogService $syncLogService;

    protected AttendanceLogProcessingService $logProcessor;

    public function __construct(
        protected FingerprintIngestService $ingestService,
        protected AttendanceProcessingService $processingService,
        protected WhatsAppNotificationService $waService,
        ?string $platform = null,
        ?DeviceSyncLogService $syncLogService = null,
        ?AttendanceLogProcessingService $logProcessor = null
    ) {
        $this->platform = $platform ?? PHP_OS_FAMILY;
        $this->syncLogService = $syncLogService ?? app(DeviceSyncLogService::class);
        $this->logProcessor = $logProcessor ?? app(AttendanceLogProcessingService::class);
        // Nilai dibaca lewat config() agar tetap benar saat `php artisan config:cache`.
        $this->primaryDriver = strtolower((string) config('services.connector.primary_driver', 'auto'));

        $this->pythonBinary = (string) (config('services.connector.python_binary')
            ?: ($this->platform === 'Windows' ? 'python' : 'python3'));
        $this->entrypoint = base_path((string) config('services.connector.entrypoint', 'connector/zkconnector.py'));

        $legacyV2 = base_path('connector/legacy-windows-csharp/SolutionX601ConnectorV2.exe');
        $legacyV1 = base_path('connector/legacy-windows-csharp/SolutionX601Connector.exe');
        $this->legacyExePath = file_exists($legacyV2) ? $legacyV2 : (file_exists($legacyV1) ? $legacyV1 : null);

        $this->localApiUrl = (string) config('services.connector.local_api_url', 'http://127.0.0.1:4371');
        $this->localApiToken = (string) config('services.connector.local_api_token', '');
    }

    /**
     * Pre-check konektivitas soket TCP / LAN ke IP dan Port mesin.
     * Mendukung multi-device probe tanpa memblokir perangkat lain.
     *
     * Hanya port milik mesin fingerprint yang dianggap online. Fallback ICMP ping
     * sengaja dihapus karena router atau switch di LAN yang sama bisa menjawab ping,
     * sehingga perangkat yang bukan mesin ikut terdeteksi online.
     */
    protected function checkSocketReachable(string $ip, int $port, float $timeout = 1.5): true|string
    {
        $errno = 0;
        $errstr = '';

        // 1. Cek port target utama (misal port 4370)
        $fp = @fsockopen($ip, $port, $errno, $errstr, $timeout);
        if ($fp) {
            fclose($fp);

            return true;
        }

        // 2. Jika port 4370 tidak merespons, cek port web SOAP (port 80)
        if ($port === 4370) {
            $fpWeb = @fsockopen($ip, 80, $errno, $errstr, 1.0);
            if ($fpWeb) {
                fclose($fpWeb);

                return true;
            }
        }

        return "Mesin Solution X601 di IP {$ip}:{$port} tidak merespons di jaringan LAN. Pastikan mesin menyala, kabel LAN terhubung ke jaringan/switch yang sama, dan IP mesin sudah benar.";
    }

    /**
     * Verifikasi konektivitas fisik riil ke mesin (pre-check soket LAN).
     * Dipakai oleh heartbeat terjadwal untuk menentukan status online/offline aktual.
     */
    public function checkConnectivity(FingerprintDevice $device): bool
    {
        return $this->checkSocketReachable($device->ip, (int) $device->port) === true;
    }

    /**
     * Uji koneksi ke mesin Solution X601 via connector SDK.
     */
    public function testConnection(FingerprintDevice $device, ?int $operatorId = null): array
    {
        // 1. Pre-check konektivitas soket jaringan LAN (cepat 1.5 detik)
        $socketStatus = $this->checkSocketReachable($device->ip, (int) $device->port);
        if ($socketStatus !== true) {
            $device->markOffline();

            DeviceSyncLog::create([
                'device_id' => $device->id,
                'sync_type' => 'test_connection',
                'status' => 'failed',
                'records_count' => 0,
                'message' => $socketStatus,
                'details' => [
                    'ip' => $device->ip,
                    'port' => $device->port,
                    'reason' => 'socket_unreachable',
                ],
                'operator_id' => $operatorId,
            ]);

            return [
                'success' => false,
                'message' => $socketStatus,
                'code' => -201,
            ];
        }

        // 2. Utamakan Local HTTP API Daemon (Port 4371) jika aktif dan responsif
        $response = $this->callHttpApi('/api/test', [
            'ip' => $device->ip,
            'port' => $device->port,
            'comm_key' => $device->comm_key,
        ]);

        // 3. Jika daemon tidak aktif / sibuk / timeout, fallback ke eksekusi langsung via CLI mandiri
        if (! $response || ! ($response['success'] ?? false)) {
            $cliResponse = $this->callCli(['--test', "--ip={$device->ip}", "--port={$device->port}", "--comm-key={$device->comm_key}"]);
            if ($cliResponse && ($cliResponse['success'] ?? false)) {
                $response = $cliResponse;
            } elseif (! $response) {
                $response = $cliResponse;
            }
        }

        $isSuccess = (bool) ($response['success'] ?? false);
        $message = $response['message'] ?? ($isSuccess ? 'Koneksi ke mesin berhasil.' : 'Gagal terhubung ke mesin.');

        // Update status perangkat
        if ($isSuccess) {
            $device->markOnline();
        } else {
            $device->markOffline();
        }

        // Catat riwayat sinkronisasi (PRD modul_finger.md #9)
        DeviceSyncLog::create([
            'device_id' => $device->id,
            'sync_type' => 'test_connection',
            'status' => $isSuccess ? 'success' : 'failed',
            'records_count' => 0,
            'message' => $message,
            'details' => [
                'driver' => 'connector_sdk',
                'ip' => $device->ip,
                'port' => $device->port,
                'response' => $response,
            ],
            'operator_id' => $operatorId,
        ]);

        return [
            'success' => $isSuccess,
            'message' => $message,
            'code' => $response['code'] ?? ($isSuccess ? 0 : -201),
        ];
    }

    /**
     * Deteksi kapabilitas mesin (kapasitas, dukungan event realtime) tanpa mengubah data.
     *
     * Memakai Local HTTP API daemon (`/api/probe`) bila aktif, lalu fallback ke CLI `--probe`.
     * Hasil disimpan pada kolom `capabilities` perangkat untuk memilih jalur ingest.
     */
    public function probe(FingerprintDevice $device): array
    {
        $socketStatus = $this->checkSocketReachable($device->ip, (int) $device->port);
        if ($socketStatus !== true) {
            return [
                'success' => false,
                'code' => -201,
                'message' => $socketStatus,
                'capabilities' => [],
            ];
        }

        $response = $this->callHttpApi('/api/probe', [
            'ip' => $device->ip,
            'port' => $device->port,
            'comm_key' => $device->comm_key,
        ]);

        if (! $response || ! ($response['success'] ?? false)) {
            $cliResponse = $this->callCli(['--probe', "--ip={$device->ip}", "--port={$device->port}", "--comm-key={$device->comm_key}"]);
            if ($cliResponse && ($cliResponse['success'] ?? false)) {
                $response = $cliResponse;
            } elseif (! $response) {
                $response = $cliResponse;
            }
        }

        $isSuccess = (bool) ($response['success'] ?? false);

        return [
            'success' => $isSuccess,
            'code' => $response['code'] ?? ($isSuccess ? 0 : -1),
            'message' => $response['message'] ?? ($isSuccess ? 'Kapabilitas mesin berhasil dideteksi.' : 'Gagal mendeteksi kapabilitas mesin.'),
            'capabilities' => $response['capabilities'] ?? [],
        ];
    }

    /**
     * Pastikan mesin dapat dijangkau sebelum sinkronisasi absensi.
     *
     * Jika mesin tidak terhubung (socket tidak reachable), catat satu entri DeviceSyncLog
     * dengan status 'failed' dan pesan "Mesin ... tidak terhubung.", lalu kembalikan false.
     * Jika mesin terhubung, kembalikan true dan mesin tetap dalam status online/offline saat ini.
     *
     * @return bool true jika mesin terhubung dan siap untuk sinkronisasi, false jika tidak.
     */
    public function ensureAttendanceReachable(FingerprintDevice $device, ?int $operatorId = null): bool
    {
        $socketStatus = $this->checkSocketReachable($device->ip, (int) $device->port);
        if ($socketStatus !== true) {
            $device->markOffline();

            $this->syncLogService->recordAttendanceSync(
                $device,
                'failed',
                0,
                "Mesin {$device->name} ({$device->ip}:{$device->port}) tidak terhubung.",
                ['ip' => $device->ip, 'port' => $device->port, 'reason' => 'socket_unreachable'],
                $operatorId
            );

            return false;
        }

        return true;
    }

    /**
     * Ambil data seluruh user/siswa yang terdaftar di mesin Solution X601 dan simpan ke database (read-only).
     *
     * @deprecated Gunakan {@see syncMachineUsers()} yang memakai mapping sebagai acuan utama.
     */
    public function syncStudents(FingerprintDevice $device, ?int $operatorId = null): array
    {
        // 1. Pre-check soket LAN
        $socketStatus = $this->checkSocketReachable($device->ip, (int) $device->port);
        if ($socketStatus !== true) {
            $device->markOffline();

            DeviceSyncLog::create([
                'device_id' => $device->id,
                'sync_type' => 'sync_students',
                'status' => 'failed',
                'records_count' => 0,
                'message' => $socketStatus,
                'details' => ['ip' => $device->ip, 'port' => $device->port, 'reason' => 'socket_unreachable'],
                'operator_id' => $operatorId,
            ]);

            return [
                'success' => false,
                'message' => $socketStatus,
                'code' => -201,
                'synced_count' => 0,
            ];
        }

        // Utamakan Local HTTP API Daemon (Port 4371), fallback ke CLI jika daemon tidak aktif
        $response = $this->callHttpApi('/api/sync-students', [
            'ip' => $device->ip,
            'port' => $device->port,
            'comm_key' => $device->comm_key,
        ], 30);

        if (! $response) {
            $response = $this->callCli(['--sync-students', "--ip={$device->ip}", "--port={$device->port}", "--comm-key={$device->comm_key}"]);
        }

        $isSuccess = (bool) ($response['success'] ?? false);

        if (! $isSuccess) {
            $errMsg = $response['message'] ?? 'Gagal mengambil data siswa dari mesin fingerprint.';
            $device->markOffline();

            DeviceSyncLog::create([
                'device_id' => $device->id,
                'sync_type' => 'sync_students',
                'status' => 'failed',
                'records_count' => 0,
                'message' => $errMsg,
                'details' => $response,
                'operator_id' => $operatorId,
            ]);

            return [
                'success' => false,
                'message' => $errMsg,
                'code' => $response['code'] ?? -1,
                'synced_count' => 0,
            ];
        }

        $users = $response['users'] ?? [];
        $newCount = 0;
        $updatedCount = 0;

        foreach ($users as $user) {
            $pin = trim((string) ($user['pin'] ?? ''));
            if ($pin === '') {
                continue;
            }

            // Mapping pada device_users adalah acuan utama, bukan NIS.
            $mapping = DeviceUser::where('device_id', $device->id)
                ->where('machine_user_id', $pin)
                ->first();

            if ($mapping) {
                // Nama siswa tidak pernah diambil dari mesin; mapping adalah identitas utama.
                $updatedCount++;
            } else {
                // Buat siswa baru tanpa mengisi NIS maupun nama dari PIN mesin.
                $newStudent = Student::create([
                    'nis' => null,
                    'name' => 'Siswa PIN '.$pin,
                    'active' => true,
                ]);

                DeviceUser::create([
                    'device_id' => $device->id,
                    'machine_user_id' => $pin,
                    'student_id' => $newStudent->id,
                ]);

                $newCount++;
            }
        }

        $totalSynced = count($users);
        $device->markOnline();

        $summaryMsg = "Sinkronisasi siswa berhasil. Total {$totalSynced} siswa terbaca ({$newCount} baru ditambahkan, {$updatedCount} data diperbarui).";

        DeviceSyncLog::create([
            'device_id' => $device->id,
            'sync_type' => 'sync_students',
            'status' => 'success',
            'records_count' => $totalSynced,
            'message' => $summaryMsg,
            'details' => [
                'total' => $totalSynced,
                'new' => $newCount,
                'updated' => $updatedCount,
            ],
            'operator_id' => $operatorId,
        ]);

        return [
            'success' => true,
            'message' => $summaryMsg,
            'total' => $totalSynced,
            'new' => $newCount,
            'updated' => $updatedCount,
        ];
    }

    /**
     * Kumpulkan daftar pengguna yang terdaftar pada mesin (PIN + nama).
     *
     * Hasil selalu disimpan sebagai pengguna mesin ({@see DeviceMachineUser}) sehingga
     * operator dapat memetakannya lewat antarmuka pemetaan. Bila $createStudents = true,
     * PIN yang belum memiliki mapping akan dibuatkan siswa baru tanpa NIS (NIS diisi
     * terpisah oleh operator) sekaligus dipetakan pada mesin ini.
     *
     * Identitas pengguna mesin adalah kombinasi device_id + fingerprint_user_id,
     * jadi PIN yang sama pada mesin berbeda tidak akan tertukar. Mapping pada
     * device_users selalu menjadi acuan utama agar perubahan NIS tidak membuat
     * siswa terduplikasi saat sinkronisasi berikutnya.
     *
     * @return array{success: bool, message: string, code?: int, total?: int, new?: int, updated?: int, students_created?: int, unmapped?: int}
     */
    public function syncMachineUsers(FingerprintDevice $device, ?int $operatorId = null, bool $createStudents = false, ?TaskProgressReporter $reporter = null): array
    {
        $failed = function (string $message, array $details = [], int $code = -1) use ($device, $operatorId): array {
            $device->markOffline();

            DeviceSyncLog::create([
                'device_id' => $device->id,
                'sync_type' => 'sync_students',
                'status' => 'failed',
                'records_count' => 0,
                'message' => $message,
                'details' => $details,
                'operator_id' => $operatorId,
            ]);

            return [
                'success' => false,
                'message' => $message,
                'code' => $code,
                'total' => 0,
                'new' => 0,
                'updated' => 0,
                'students_created' => 0,
                'unmapped' => 0,
            ];
        };

        // 1. Pre-check soket LAN
        $socketStatus = $this->checkSocketReachable($device->ip, (int) $device->port);
        if ($socketStatus !== true) {
            return $failed($socketStatus, [
                'ip' => $device->ip,
                'port' => $device->port,
                'reason' => 'socket_unreachable',
            ], -201);
        }

        // 2. Utamakan Local HTTP API Daemon (Port 4371), fallback ke CLI.
        $response = $this->callHttpApi('/api/sync-students', [
            'ip' => $device->ip,
            'port' => $device->port,
            'comm_key' => $device->comm_key,
        ], 30);

        if (! $response) {
            $response = $this->callCli(['--sync-students', "--ip={$device->ip}", "--port={$device->port}", "--comm-key={$device->comm_key}"]);
        }

        if (! ($response['success'] ?? false)) {
            return $failed(
                $response['message'] ?? 'Gagal mengambil data pengguna dari mesin fingerprint.',
                $response,
                $response['code'] ?? -1
            );
        }

        $users = $response['users'] ?? [];
        $syncedAt = now();
        $created = 0;
        $updated = 0;
        $studentsCreated = 0;

        $totalUsers = count($users);
        $reporter?->setTotal(
            $totalUsers,
            $totalUsers > 0 ? "Memproses {$totalUsers} pengguna dari mesin..." : 'Tidak ada pengguna pada mesin.'
        );

        // Muat sekali di awal agar tidak ada query per pengguna (satu mesin bisa
        // memuat ratusan PIN, dan ratusan update satu-per-satu membuat sinkronisasi
        // lambat karena setiap commit menunggu fsync MySQL).
        $existingPins = DeviceMachineUser::where('device_id', $device->id)->pluck('id', 'fingerprint_user_id');

        $mappings = $createStudents
            ? DeviceUser::where('device_id', $device->id)->with('student')->get()->keyBy('machine_user_id')
            : collect();

        /** @var array<string, array<string, mixed>> $rows */
        $rows = [];

        foreach ($users as $user) {
            $pin = trim((string) ($user['pin'] ?? ''));

            if ($pin === '') {
                continue;
            }

            $reporter?->advance(1, "Memproses pengguna PIN {$pin}...");

            $machineName = $this->normalizeMachineName((string) ($user['name'] ?? ''));

            $rows[$pin] = [
                'device_id' => $device->id,
                'fingerprint_user_id' => $pin,
                'machine_name' => $machineName,
                'synced_at' => $syncedAt,
                'created_at' => $syncedAt,
                'updated_at' => $syncedAt,
            ];

            if ($existingPins->has($pin)) {
                $updated++;
            } else {
                $created++;
            }

            if (! $createStudents) {
                continue;
            }

            $mapping = $mappings->get($pin);

            if ($mapping) {
                // Mapping yang sudah ada adalah identitas utama. Nama siswa tidak pernah
                // ditimpa oleh nama mesin (yang bisa terpotong/ter-escape); nama mesin
                // hanya disimpan sebagai petunjuk di device_machine_users.
                continue;
            }

            // Nama siswa baru selalu placeholder; nama asli hanya berasal dari
            // Excel/edit operator, tidak pernah dari mesin.
            $student = Student::create([
                'nis' => null,
                'name' => 'Siswa PIN '.$pin,
                'active' => true,
            ]);
            $studentsCreated++;

            $mappings->put($pin, DeviceUser::create([
                'device_id' => $device->id,
                'machine_user_id' => $pin,
                'student_id' => $student->id,
            ]));
        }

        // Satu statement upsert untuk semua PIN menggantikan update per baris.
        foreach (array_chunk(array_values($rows), 500) as $chunk) {
            DeviceMachineUser::upsert(
                $chunk,
                ['device_id', 'fingerprint_user_id'],
                ['machine_name', 'synced_at', 'updated_at']
            );
        }

        if ($createStudents) {
            // Log lama yang sebelumnya 'unmapped' kini sudah punya pemetaan: ubah menjadi
            // 'unprocessed' agar ikut diproses pada sinkronisasi absensi berikutnya.
            $mappedPins = DeviceUser::where('device_id', $device->id)->pluck('machine_user_id')->all();

            if ($mappedPins !== []) {
                AttendanceLog::where('device_id', $device->id)
                    ->where('status', 'unmapped')
                    ->whereIn('machine_user_id', $mappedPins)
                    ->update(['status' => 'unprocessed']);
            }
        }

        $device->markOnline();

        $unmapped = DeviceMachineUser::where('device_id', $device->id)->unmapped()->count();

        $summaryMsg = "Sinkronisasi pengguna berhasil. {$created} pengguna baru, {$updated} diperbarui.";
        if ($createStudents) {
            $summaryMsg .= " {$studentsCreated} siswa baru dibuat tanpa NIS & dipetakan otomatis.";
        }
        $summaryMsg .= " Total {$unmapped} pengguna belum dipetakan.";

        DeviceSyncLog::create([
            'device_id' => $device->id,
            'sync_type' => 'sync_students',
            'status' => 'success',
            'records_count' => $created + $updated,
            'message' => $summaryMsg,
            'details' => [
                'new' => $created,
                'updated' => $updated,
                'students_created' => $studentsCreated,
                'unmapped' => $unmapped,
                'creates_students' => $createStudents,
            ],
            'operator_id' => $operatorId,
        ]);

        return [
            'success' => true,
            'message' => $summaryMsg,
            'total' => $created + $updated,
            'new' => $created,
            'updated' => $updated,
            'students_created' => $studentsCreated,
            'unmapped' => $unmapped,
        ];
    }

    /**
     * Ambil data log absensi dari mesin Solution X601 dan proses ke database (read-only).
     */
    public function syncAttendances(FingerprintDevice $device, ?int $operatorId = null, ?TaskProgressReporter $reporter = null): array
    {
        // 1. Pre-check soket LAN
        $socketStatus = $this->checkSocketReachable($device->ip, (int) $device->port);
        if ($socketStatus !== true) {
            $device->markOffline();

            $this->syncLogService->recordAttendanceSync(
                $device,
                'failed',
                0,
                $socketStatus,
                ['ip' => $device->ip, 'port' => $device->port, 'reason' => 'socket_unreachable'],
                $operatorId
            );

            return [
                'success' => false,
                'message' => $socketStatus,
                'code' => -201,
                'ingested' => 0,
            ];
        }

        $since = $this->incrementalSince($device);

        // Utamakan Local HTTP API Daemon (Port 4371), fallback ke CLI jika daemon tidak aktif
        $query = [
            'ip' => $device->ip,
            'port' => $device->port,
            'comm_key' => $device->comm_key,
        ];

        if ($since !== null) {
            $query['since'] = $since;
        }

        $response = $this->callHttpApi('/api/sync-logs', $query, 60);

        if (! $response) {
            $arguments = ['--sync-logs', "--ip={$device->ip}", "--port={$device->port}", "--comm-key={$device->comm_key}"];

            if ($since !== null) {
                $arguments[] = "--since={$since}";
            }

            $response = $this->callCli($arguments);
        }

        $isSuccess = (bool) ($response['success'] ?? false);
        $code = (int) ($response['code'] ?? -1);

        if (! $isSuccess) {
            $errMsg = $response['message'] ?? 'Gagal mengambil riwayat absensi dari mesin fingerprint.';

            if ($code === -202) {
                return [
                    'success' => false,
                    'busy' => true,
                    'message' => $errMsg,
                    'code' => $code,
                    'ingested' => 0,
                ];
            }

            $device->markOffline();

            $this->syncLogService->recordAttendanceSync(
                $device,
                'failed',
                0,
                $errMsg,
                $response,
                $operatorId
            );

            return [
                'success' => false,
                'message' => $errMsg,
                'code' => $code,
                'ingested' => 0,
            ];
        }

        $rawLogs = $response['logs'] ?? [];

        // 1. Ingest batch ke database secara idempotent
        $ingestResult = $this->ingestService->ingestBatch($device, $rawLogs);

        // 2. Olah log yang belum diproses menjadi absensi.
        $pendingCount = $this->logProcessor->pendingCountForDevice($device);

        $totalScans = count($rawLogs);
        $reporter?->setTotal(
            $totalScans + $pendingCount,
            'Log mesin tersimpan, memproses absensi...'
        );
        $reporter?->advance($totalScans, 'Log mesin tersimpan, memproses absensi...');

        $processingResult = $this->logProcessor->processDueForDevice($device, $reporter);
        $processedCount = $processingResult['processed'];

        $device->markOnline();

        $summaryMsg = 'Sinkronisasi absensi selesai. Terbaca '.count($rawLogs)." scan ({$ingestResult['inserted']} log baru, {$ingestResult['duplicates_skipped']} log lama/duplikat dilewati).";

        $this->syncLogService->recordAttendanceSync(
            $device,
            'success',
            $ingestResult['inserted'],
            $summaryMsg,
            [
                'read_from_device' => count($rawLogs),
                'since' => $since,
                'accepted' => $ingestResult['accepted'],
                'missing' => $ingestResult['missing'],
                'new_inserted' => $ingestResult['inserted'],
                'duplicates' => $ingestResult['duplicates_skipped'],
                'attendances_processed' => $processedCount,
            ],
            $operatorId
        );

        return [
            'success' => true,
            'message' => $summaryMsg,
            'read_total' => count($rawLogs),
            'new_inserted' => $ingestResult['inserted'],
            'attendances_processed' => $processedCount,
        ];
    }

    /**
     * Waktu log terakhir di DB dikurangi lima menit agar clock skew mesin tidak
     * membuat log terlewat. Null berarti connector harus membaca seluruh log.
     */
    protected function incrementalSince(FingerprintDevice $device): ?string
    {
        $latest = AttendanceLog::where('device_id', $device->id)->max('occurred_at');

        if ($latest === null) {
            return null;
        }

        $timezone = $device->timezone ?: 'Asia/Jakarta';

        try {
            $latestAt = Carbon::createFromFormat('Y-m-d H:i:s', (string) $latest, $timezone);
        } catch (\Throwable) {
            return null;
        }

        if ($latestAt->gt(now($timezone)->addMinutes(5))) {
            return null;
        }

        return $latestAt->subMinutes(5)->format('Y-m-d H:i:s');
    }

    /**
     * Sinkronkan jam mesin melalui connector (aksi manual, dicatat sekali).
     */
    public function syncTime(FingerprintDevice $device, ?int $operatorId = null): array
    {
        $socketStatus = $this->checkSocketReachable($device->ip, (int) $device->port);

        if ($socketStatus !== true) {
            $device->markOffline();

            DeviceSyncLog::create([
                'device_id' => $device->id,
                'sync_type' => 'sync_time',
                'status' => 'failed',
                'records_count' => 0,
                'message' => $socketStatus,
                'details' => ['driver' => 'connector', 'ip' => $device->ip, 'port' => $device->port],
                'operator_id' => $operatorId,
            ]);

            return ['success' => false, 'message' => $socketStatus, 'code' => -201];
        }

        $response = $this->callHttpApi('/api/sync-time', [
            'ip' => $device->ip,
            'port' => $device->port,
            'comm_key' => $device->comm_key,
        ], 30);

        if (! $response) {
            $response = $this->callCli([
                '--sync-time',
                "--ip={$device->ip}",
                "--port={$device->port}",
                "--comm-key={$device->comm_key}",
            ]);
        }

        $success = (bool) ($response['success'] ?? false);
        $message = $response['message'] ?? ($success
            ? 'Jam mesin berhasil disinkronkan.'
            : 'Gagal menyinkronkan jam mesin.');
        $code = (int) ($response['code'] ?? -1);

        if (! $success && $code !== -202) {
            $device->markOffline();
        }

        DeviceSyncLog::create([
            'device_id' => $device->id,
            'sync_type' => 'sync_time',
            'status' => $success ? 'success' : 'failed',
            'records_count' => 0,
            'message' => $message,
            'details' => [
                'driver' => 'connector',
                'ip' => $device->ip,
                'port' => $device->port,
                'device_time' => $response['device_time'] ?? null,
                'drift_seconds' => $response['drift_seconds'] ?? null,
                'synced' => $response['synced'] ?? false,
            ],
            'operator_id' => $operatorId,
        ]);

        if ($success) {
            $device->markOnline();
        }

        return ['success' => $success, 'message' => $message, 'code' => $code, 'busy' => $code === -202];
    }

    /**
     * Rapikan nama yang datang dari mesin sebelum disimpan sebagai petunjuk pemetaan.
     *
     * Nama dari mesin bisa mengandung entitas XML (&amp;, &apos;) bila firmware tidak
     * mendekodenya, atau spasi/karakter kontrol berlebih. Nilai ini hanya dipakai
     * sebagai petunjuk pada device_machine_users; nama siswa asli tidak pernah
     * diambil dari sini.
     */
    protected function normalizeMachineName(string $name): ?string
    {
        $name = html_entity_decode($name, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? $name;
        $name = preg_replace('/\s+/u', ' ', $name) ?? $name;
        $name = trim($name);

        return $name === '' ? null : $name;
    }

    /**
     * Panggil Local HTTP API listener jika connector berjalan di latar belakang.
     */
    protected function callHttpApi(string $endpoint, array $queryParams = [], float $timeout = 3): ?array
    {
        try {
            $url = rtrim($this->localApiUrl, '/').$endpoint;
            $request = Http::connectTimeout(1)->timeout($timeout);

            if ($this->localApiToken !== '') {
                $request = $request->withHeaders(['X-Connector-Token' => $this->localApiToken]);
            }

            $res = $request->get($url, $queryParams);
            if ($res->successful()) {
                return $res->json();
            }
        } catch (\Throwable) {
            // Daemon tidak aktif, sibuk pada mesin lain, atau timeout, lanjut ke fallback CLI
        }

        return null;
    }

    /**
     * Eksekusi connector lintas-OS via Symfony Process.
     *
     * Urutan prioritas diatur oleh `connectorDriverOrder()`: di Windows binary C# legacy
     * didahulukan, di OS lain connector Python didahulukan. Jika kandidat pertama gagal
     * dijalankan (mis. interpreter tidak tersedia), kandidat berikutnya dicoba otomatis.
     */
    protected function callCli(array $arguments): array
    {
        $commands = $this->connectorCommands($arguments);

        if ($commands === []) {
            return [
                'success' => false,
                'code' => -999,
                'message' => 'Connector tidak ditemukan: entrypoint Python maupun binary C# legacy tidak tersedia.',
            ];
        }

        foreach ($commands as $cmd) {
            $result = $this->runConnectorProcess($cmd);
            if ($result !== null) {
                return $result;
            }
        }

        return [
            'success' => false,
            'code' => -998,
            'message' => 'Connector tidak dapat dijalankan (interpreter Python tidak ditemukan).',
        ];
    }

    /**
     * Susun daftar perintah kandidat sesuai ketersediaan berkas.
     *
     * @param  array<int, string>  $arguments
     * @return array<int, array<int, string>>
     */
    protected function connectorCommands(array $arguments): array
    {
        $python = file_exists($this->entrypoint)
            ? array_merge([$this->pythonBinary, $this->entrypoint], $arguments)
            : null;

        $csharp = ($this->legacyExePath !== null && file_exists($this->legacyExePath))
            ? array_merge([$this->legacyExePath], $arguments)
            : null;

        $commands = [];

        foreach ($this->connectorDriverOrder() as $driver) {
            if ($driver === 'python' && $python !== null) {
                $commands[] = $python;
            } elseif ($driver === 'csharp' && $csharp !== null) {
                $commands[] = $csharp;
            }
        }

        return $commands;
    }

    /**
     * Urutan driver connector yang dicoba.
     *
     * Aturan:
     * - `CONNECTOR_PRIMARY_DRIVER=csharp|python` memaksa urutan tertentu.
     * - `auto` (default): di Windows, binary C# didahulukan (sesuai ketetapan C# sebagai
     *   connector utama); di OS lain, Python didahulukan karena C# tidak dapat berjalan.
     *
     * @return array<int, string>
     */
    protected function connectorDriverOrder(): array
    {
        if ($this->primaryDriver === 'csharp') {
            return ['csharp', 'python'];
        }

        if ($this->primaryDriver === 'python') {
            return ['python', 'csharp'];
        }

        return $this->platform === 'Windows' ? ['csharp', 'python'] : ['python', 'csharp'];
    }

    /**
     * Jalankan satu perintah connector. Mengembalikan array hasil, atau null bila
     * proses tidak dapat dimulai (mis. interpreter Python tidak tersedia).
     *
     * @param  array<int, string>  $cmd
     */
    protected function runConnectorProcess(array $cmd): ?array
    {
        $process = new Process($cmd, base_path('connector')); // CWD = direktori connector
        $process->setTimeout(25); // Batas waktu koneksi TCP

        try {
            $process->run();
        } catch (\Throwable $e) {
            Log::warning('[FingerprintConnectorService] Gagal menjalankan connector', [
                'command' => $cmd,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $output = trim($process->getOutput());
        $errorOutput = trim($process->getErrorOutput());

        // Cari substring JSON dalam output
        $startPos = strpos($output, '{');
        $endPos = strrpos($output, '}');

        if ($startPos !== false && $endPos !== false && $endPos >= $startPos) {
            $jsonStr = substr($output, $startPos, $endPos - $startPos + 1);
            $decoded = json_decode($jsonStr, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        // Perintah tidak ditemukan -> coba kandidat berikutnya (fallback).
        if (! $process->isSuccessful() && $output === '' && $this->looksLikeMissingCommand($errorOutput)) {
            return null;
        }

        $exitCode = $process->getExitCode();
        $detailMsg = $output ?: ($errorOutput ?: "Connector keluar dengan exit code: {$exitCode}");

        return [
            'success' => $process->isSuccessful(),
            'code' => $exitCode,
            'message' => $detailMsg,
        ];
    }

    /**
     * Deteksi error "command not found" lintas shell (cmd, PowerShell, sh).
     */
    protected function looksLikeMissingCommand(string $errorOutput): bool
    {
        if ($errorOutput === '') {
            return false;
        }

        foreach (['not recognized', 'not found', 'No such file', 'is not recognized'] as $needle) {
            if (str_contains($errorOutput, $needle)) {
                return true;
            }
        }

        return false;
    }
}
