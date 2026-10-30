<?php

namespace App\Http\Controllers;

use App\Concerns\LogsAuditTrail;
use App\Http\Controllers\Concerns\HasHybridFilters;
use App\Jobs\BulkDeviceOperation;
use App\Jobs\SyncDeviceAttendance;
use App\Jobs\SyncDeviceUsers;
use App\Models\DeviceMachineUser;
use App\Models\DeviceSyncLog;
use App\Models\DeviceUser;
use App\Models\FingerprintDevice;
use App\Models\Student;
use App\Services\BiometricTemplateService;
use App\Services\BulkDeviceOperationService;
use App\Services\ConnectorConfigBuilder;
use App\Services\DeviceLockService;
use App\Services\DeviceMappingService;
use App\Services\FingerprintConnectorService;
use App\Services\SoapFingerprintService;
use App\Support\TaskProgressReporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FingerprintDeviceController extends Controller
{
    use HasHybridFilters;
    use LogsAuditTrail;

    protected function getFilterConfig(): array
    {
        return [];
    }

    public function __construct(
        protected FingerprintConnectorService $connectorService,
        protected SoapFingerprintService $soapService,
        protected DeviceLockService $lockService,
        protected ConnectorConfigBuilder $connectorConfigBuilder,
        protected BiometricTemplateService $biometricTemplateService,
        protected DeviceMappingService $mappingService,
        protected BulkDeviceOperationService $bulkOperationService,
    ) {}

    /**
     * Daftar mesin fingerprint sebagai tabel data.
     *
     * Data diambil penuh (tanpa `paginate`) karena tabel ini memakai DataTables
     * untuk pencarian, pengurutan, dan pagination di sisi browser. Memaginate di
     * server bersamaan dengan DataTables akan membuat pengguna hanya melihat
     * satu halaman server, lalu DataTables memaginate ulang halaman tersebut,
     * sehingga baris berikutnya tidak pernah bisa dicari.
     *
     * Filter `?search=` tetap didukung supaya halaman bisa dipanggil langsung
     * dengan preset pencarian.
     *
     * Status koneksi yang ditampilkan berasal dari pemeriksaan aktual terakhir
     * (disimpan saat tes koneksi, sinkronisasi, atau health check). Halaman ini
     * sengaja TIDAK menjalankan uji koneksi otomatis saat dirender agar tabel
     * tetap cepat dimuat dan tidak membebani jaringan.
     */
    public function index(Request $request): View
    {
        $query = FingerprintDevice::withCount(['deviceUsers', 'attendanceLogs', 'syncLogs'])
            ->with('latestSyncLog');

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('ip', 'like', "%{$search}%");
            });
        }

        $devices = $query->latest('id')->get();

        $search = $request->input('search', '');

        return view('devices.index', compact('devices', 'search'));
    }

    /**
     * Halaman detail satu mesin fingerprint (satu halaman, tanpa tab):
     * ringkasan, sinkronisasi, konfigurasi, dan pemeliharaan.
     *
     * Riwayat sinkronisasi per mesin tidak lagi ditampilkan di sini — halaman
     * khusus `devices.sync-history` sudah menyediakannya (dengan filter) dan
     * ditautkan dari kartu Sinkronisasi.
     */
    public function show(FingerprintDevice $device): View
    {
        $device->loadCount(['deviceUsers', 'attendanceLogs', 'syncLogs']);

        // Mesin lain sebagai target kloning / distribusi sidik jari.
        $otherDevices = FingerprintDevice::where('id', '!=', $device->id)
            ->orderBy('name')
            ->get();

        return view('devices.show', compact('device', 'otherDevices'));
    }

    /**
     * Halaman pemetaan PIN siswa berbasis data pengguna hasil sinkronisasi mesin.
     *
     * Alurnya: sinkronkan pengguna dari mesin, lalu petakan PIN yang tersedia ke
     * siswa. Halaman ini hanya menampilkan data; tidak ada perubahan ke mesin.
     */
    public function mappings(Request $request): View
    {
        $devices = FingerprintDevice::orderBy('name')->get();

        $selectedDeviceId = $request->input('device_id') ?: $devices->first()?->id;
        $tab = in_array($request->input('tab'), ['unmapped', 'mapped'], true)
            ? $request->input('tab')
            : 'unmapped';

        // ── Ringkasan jumlah (dihitung untuk satu mesin terpilih) ──
        $baseMachineUsers = DeviceMachineUser::when($selectedDeviceId, fn ($q) => $q->where('device_id', $selectedDeviceId));

        $summary = [
            'mapped' => (clone $baseMachineUsers)->mapped()->count(),
            'unmapped' => (clone $baseMachineUsers)->unmapped()->count(),
        ];

        // ── Tabel utama ──
        $machineUsers = null;
        $mappings = null;

        if ($tab === 'mapped') {
            // Tab Terpetakan dibaca dari tabel pemetaan agar pemetaan lama yang
            // belum pernah tersinkronisasi ke tabel pengguna tetap terlihat.
            $mappings = DeviceUser::with(['device', 'student.schoolClass'])
                ->when($selectedDeviceId, fn ($q) => $q->where('device_id', $selectedDeviceId))
                ->latest('id')
                ->get();
        } else {
            $machineUsers = $baseMachineUsers
                ->unmapped()
                ->orderBy('fingerprint_user_id')
                ->get();

            // Relasi `mapping` memakai whereColumn lintas tabel (cocok untuk
            // whereHas/whereDoesntHave, tetapi tidak bisa di-eager-load Eloquent).
            // Karena itu pemetaan diambil terpisah lalu ditempelkan per pengguna.
            $mappingsByPin = DeviceUser::with('student.schoolClass')
                ->when($selectedDeviceId, fn ($q) => $q->where('device_id', $selectedDeviceId))
                ->whereIn('machine_user_id', $machineUsers->pluck('fingerprint_user_id'))
                ->get()
                ->keyBy('machine_user_id');

            $machineUsers->each(fn (DeviceMachineUser $machineUser) => $machineUser->setRelation('mapping', $mappingsByPin->get($machineUser->fingerprint_user_id)));
        }

        // Data dikirim penuh untuk tab aktif (tanpa paginate): tabel memakai
        // DataTables di browser, dan memaginate di server akan memotong baris
        // yang tidak pernah bisa dicari pengguna. Pencarian & pengurutan
        // ditangani DataTables di sisi klien, bukan filter server terpisah.
        return view('devices.mappings', compact(
            'devices',
            'selectedDeviceId',
            'tab',
            'summary',
            'machineUsers',
            'mappings'
        ));
    }

    /**
     * Halaman riwayat sinkronisasi seluruh mesin dengan filter.
     */
    public function syncHistory(Request $request): View
    {
        $devices = FingerprintDevice::orderBy('name')->get();

        $query = DeviceSyncLog::with(['device', 'operator'])->latest('id');

        $selectedDeviceId = $request->input('device_id');
        if ($selectedDeviceId) {
            $query->where('device_id', $selectedDeviceId);
        }

        $syncType = $request->input('sync_type');
        if ($syncType) {
            $query->where('sync_type', $syncType);
        }

        $status = $request->input('status');
        if ($status) {
            $query->where('status', $status);
        }

        // Filter diproses di server; pagination juga server-side agar halaman
        // tetap ringan meskipun riwayat sudah menumpuk.
        $syncLogs = $query->paginate(25)->withQueryString();

        return view('devices.sync-history', compact('syncLogs', 'devices', 'selectedDeviceId', 'syncType', 'status'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'ip' => ['required', 'ip', 'unique:fingerprint_devices,ip'],
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            'comm_key' => ['nullable', 'string', 'max:50'],
            'timezone' => ['required', 'string', 'max:50'],
        ], [
            'ip.unique' => 'IP ini sudah terdaftar pada perangkat lain. Satu mesin hanya boleh didaftarkan satu kali.',
        ]);

        $validated['comm_key'] = $validated['comm_key'] ?? '0';
        $validated['secret_token'] = 'x601_sec_'.Str::random(24);
        $validated['status'] = 'offline';
        $validated['active'] = true;

        $device = FingerprintDevice::create($validated);

        $this->logAudit('create_device', 'FingerprintDevice', $device->id, null, $device->toArray());

        return redirect()->route('devices.show', $device)
            ->with('success', 'Perangkat '.$device->name.' berhasil didaftarkan.');
    }

    public function update(Request $request, FingerprintDevice $device): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'ip' => ['required', 'ip', Rule::unique('fingerprint_devices', 'ip')->ignore($device->id)],
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            'comm_key' => ['nullable', 'string', 'max:50'],
            'timezone' => ['required', 'string', 'max:50'],
            'status' => ['required', 'in:active,maintenance'],
        ], [
            'ip.unique' => 'IP ini sudah terdaftar pada perangkat lain. Satu mesin hanya boleh didaftarkan satu kali.',
        ]);

        $validated['comm_key'] = $validated['comm_key'] ?? '0';

        // Logika Status Fisik vs Administratif:
        // Status "online" (active) HANYA boleh dihasilkan dari verifikasi riil LAN (Tes Koneksi atau Heartbeat/Log Ingest).
        // Jika status diset maintenance, simpan maintenance.
        // Jika status diset active, tetapi IP/Port berubah atau sebelumnya offline, pertahankan offline sampai diverifikasi.
        if ($validated['status'] === 'maintenance') {
            $newStatus = 'maintenance';
        } else {
            $isNetworkConfigChanged = ($device->ip !== $validated['ip'] || (int) $device->port !== (int) $validated['port']);
            if ($isNetworkConfigChanged || $device->status === 'maintenance') {
                $newStatus = 'offline';
            } else {
                $newStatus = $device->status; // pertahankan status fisik riil (active/offline)
            }
        }
        $validated['status'] = $newStatus;

        $before = $device->toArray();
        $device->update($validated);

        $this->logAudit('update_device', 'FingerprintDevice', $device->id, $before, $device->toArray());

        $msg = 'Konfigurasi perangkat '.$device->name.' berhasil diperbarui.';
        if ($newStatus === 'offline' && $device->status !== 'maintenance') {
            $msg .= ' Status koneksi saat ini Offline (gunakan tombol "Tes Koneksi" untuk memverifikasi jaringan).';
        }

        return redirect()->route('devices.show', $device)->with('success', $msg);
    }

    public function destroy(Request $request, FingerprintDevice $device): RedirectResponse
    {
        $deviceName = $device->name;
        $before = $device->toArray();

        DB::transaction(function () use ($device, $before) {
            $device->delete();

            $this->logAudit('delete_device', 'FingerprintDevice', $device->id, $before, null);
        });

        return redirect()->route('devices.index')
            ->with('success', 'Perangkat '.$deviceName.' berhasil dihapus.');
    }

    public function rotateToken(FingerprintDevice $device, Request $request): RedirectResponse
    {
        $newToken = 'x601_sec_'.Str::random(24);
        $device->update(['secret_token' => $newToken]);

        $this->logAudit('rotate_device_token', 'FingerprintDevice', $device->id, null, ['token_rotated' => true]);

        return redirect()->route('devices.show', $device)
            ->with('success', 'Token autentikasi connector berhasil diperbarui. Unduh ulang connector_config.ini dan pasang pada semua mesin agar token baru dipakai.');
    }

    /**
     * Simpan pemetaan PIN mesin ke siswa.
     *
     * Aturan unik/konflik PIN dan pencatatan audit ditangani `DeviceMappingService`.
     */
    public function storeMapping(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'device_id' => ['required', 'exists:fingerprint_devices,id'],
            'machine_user_id' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9._-]+$/'],
            'student_id' => ['required', 'exists:students,id'],
        ]);

        $device = FingerprintDevice::findOrFail((int) $validated['device_id']);
        $student = Student::findOrFail((int) $validated['student_id']);
        $pin = trim($validated['machine_user_id']);

        $returnTo = $request->input('return_to', route('devices.mappings', ['device_id' => $device->id]));

        $result = $this->mappingService->store($device, $student, $pin);

        return redirect()->to($returnTo)->with($result['status'], $result['message']);
    }

    /**
     * Ubah siswa yang terhubung pada sebuah pemetaan PIN.
     *
     * Nomor PIN pada mesin tidak diubah, dan tidak ada data biometrik yang
     * disentuh di server maupun di mesin.
     */
    public function updateMapping(Request $request, DeviceUser $mapping): RedirectResponse
    {
        $validated = $request->validate([
            'student_id' => ['required', 'exists:students,id'],
        ]);

        $student = Student::findOrFail((int) $validated['student_id']);

        $returnTo = $request->input('return_to', route('devices.mappings', ['device_id' => $mapping->device_id]));

        $result = $this->mappingService->reassign($mapping, $student);

        return redirect()->to($returnTo)->with($result['status'], $result['message']);
    }

    /**
     * Unduh file `connector_config.ini` siap pakai untuk connector LAN.
     *
     * Berisi seluruh mesin aktif dalam satu file (section `[default]` global +
     * `[device:<key>]` per mesin) agar satu proses connector dapat melayani semua
     * mesin pada jaringan yang sama. Secret token sengaja TIDAK pernah dirender
     * pada HTML halaman; sebagai gantinya operator mengunduh file konfigurasi ini.
     * Setiap unduhan dicatat pada audit log.
     */
    public function connectorConfig(FingerprintDevice $device): StreamedResponse
    {
        $this->logRiskyAction($device, 'download_connector_config');

        $content = $this->connectorConfigBuilder->build($device);

        return response()->streamDownload(function () use ($content) {
            echo $content;
        }, 'connector_config.ini', ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    /**
     * Lepas pemetaan PIN dari siswa.
     *
     * Hanya relasi pemetaan di server yang dihapus. Data pengguna pada mesin
     * (termasuk sidik jari dan wajah) TIDAK dihapus atau diubah, sehingga
     * pemetaan dapat dipasang kembali tanpa perekaman ulang.
     */
    public function destroyMapping(Request $request, DeviceUser $mapping): RedirectResponse
    {
        $returnTo = $request->input('return_to', route('devices.mappings', ['device_id' => $mapping->device_id]));

        $result = $this->mappingService->release($mapping);

        return redirect()->to($returnTo)->with($result['status'], $result['message']);
    }

    /**
     * Backup template sidik jari dari mesin fisik ke database server.
     */
    public function backupFingerprint(DeviceUser $mapping): RedirectResponse
    {
        $device = $mapping->device;
        $student = $mapping->student;

        if (! $device || ! $student) {
            return redirect()->route('devices.mappings')
                ->with('error', 'Data mesin atau siswa tidak valid.');
        }

        $result = $this->biometricTemplateService->backupTemplate(
            $device,
            $student,
            $mapping->machine_user_id,
            Auth::id()
        );

        return redirect()->route('devices.mappings')
            ->with($result['success'] ? 'success' : 'error', $result['message']);
    }

    /**
     * Distribusikan template sidik jari siswa ke terminal mesin lain.
     */
    public function distributeFingerprint(Request $request, DeviceUser $mapping): RedirectResponse
    {
        $validated = $request->validate([
            'target_device_id' => ['required', 'exists:fingerprint_devices,id'],
        ]);

        $targetDevice = FingerprintDevice::findOrFail($validated['target_device_id']);

        if ($targetDevice->id === $mapping->device_id) {
            return redirect()->route('devices.mappings')
                ->with('error', 'Mesin target harus berbeda dengan mesin asal.');
        }

        $result = $this->biometricTemplateService->distributeTemplate($mapping, $targetDevice, Auth::id());

        return redirect()->route('devices.mappings')
            ->with($result['success'] ? 'success' : 'error', $result['message']);
    }

    /**
     * Uji konektivitas mesin menggunakan ZKEM SDK (PRD modul_finger.md #2).
     */
    public function testConnection(FingerprintDevice $device): RedirectResponse
    {
        $back = request()->input('return_to') ?: route('devices.show', $device);

        // 1. Uji koneksi via C# Connector SDK (Port 4370)
        $result = $this->connectorService->testConnection($device, Auth::id());

        if ($result['success']) {
            $this->recordProbe($device, 'connector');

            return redirect()->to($back)
                ->with('success', $result['message']);
        }

        // 2. Fallback cerdas: Web SOAP (Port 80) jika konektor C# gagal atau mesin mendukung Web SOAP
        $soapResult = $this->soapService->testConnection($device, Auth::id());
        if ($soapResult['success']) {
            $device->markOnline();
            $this->recordProbe($device, 'soap');

            return redirect()->to($back)
                ->with('success', $soapResult['message'].' (via Web SOAP)');
        }

        return redirect()->to($back)
            ->with('error', $result['message']);
    }

    /**
     * Simpan kapabilitas mesin dan tetapkan jalur ingest utama sesuai hasil tes koneksi.
     *
     * Best-effort: kegagalan probe tidak boleh menggagalkan respons tombol "Tes Koneksi".
     */
    protected function recordProbe(FingerprintDevice $device, string $driver): void
    {
        $probe = $driver === 'connector'
            ? $this->connectorService->probe($device)
            : ['success' => false, 'capabilities' => []];

        $device->update([
            'ingest_driver' => $driver,
            'capabilities' => ($probe['success'] ?? false) ? $probe['capabilities'] : $device->capabilities,
            'last_probe_at' => now(),
        ]);
    }

    /**
     * Sinkronkan daftar pengguna yang terdaftar pada mesin (PIN + nama).
     *
     * Secara opsional (default nonaktif) juga membuat data siswa baru untuk PIN yang
     * belum memiliki mapping. NIS sengaja dibiarkan kosong; PIN hanya disimpan pada
     * pemetaan mesin. Mapping yang sudah ada tidak diubah meskipun NIS siswa berubah.
     * Tidak mengubah apa pun pada mesin.
     */
    public function syncStudents(Request $request, FingerprintDevice $device): RedirectResponse|JsonResponse
    {
        $fromMappings = $request->boolean('from_mappings');

        $offlineResponse = $fromMappings
            ? $this->offlineActionResponse($device, 'devices.mappings', ['device_id' => $device->id])
            : $this->offlineActionResponse($device, 'devices.show', [$device]);

        if ($offlineResponse) {
            return $offlineResponse;
        }

        $createStudents = $request->boolean('create_students');

        if ($request->expectsJson()) {
            $reporter = TaskProgressReporter::start('devices.sync-students', 'Sinkronisasi Siswa', 0, Auth::id());
            SyncDeviceUsers::dispatch($device->id, Auth::id(), $createStudents, $reporter->uuid());

            return response()->json([
                'uuid' => $reporter->uuid(),
                'progress_token' => $reporter->signature(),
                'type' => 'devices.sync-students',
                'total' => 0,
                'message' => 'Sinkronisasi siswa sedang berjalan di latar belakang.',
            ], 202);
        }

        $result = $this->connectorService->syncMachineUsers($device, Auth::id(), $createStudents);

        $back = $request->boolean('from_mappings')
            ? route('devices.mappings', ['device_id' => $device->id])
            : route('devices.show', $device);

        if ($result['success']) {
            return redirect()->to($back)
                ->with('success', $result['message']);
        }

        return redirect()->to($back)
            ->with('error', $result['message']);
    }

    /**
     * Ambil riwayat absensi dari mesin secara read-only sesuai `ingest_driver`
     * perangkat (connector C# atau Web SOAP), tanpa fallback lintas driver.
     */
    public function syncAttendances(Request $request, FingerprintDevice $device): RedirectResponse|JsonResponse
    {
        if ($response = $this->offlineActionResponse($device, 'devices.show', [$device])) {
            return $response;
        }

        if ($request->expectsJson()) {
            $reporter = TaskProgressReporter::start('devices.sync-attendance', 'Sinkronisasi Absensi', 0, Auth::id());
            SyncDeviceAttendance::dispatch($device->id, Auth::id(), $reporter->uuid());

            return response()->json([
                'uuid' => $reporter->uuid(),
                'progress_token' => $reporter->signature(),
                'type' => 'devices.sync-attendance',
                'total' => 0,
                'message' => 'Sinkronisasi absensi sedang berjalan di latar belakang.',
            ], 202);
        }

        $driver = $device->ingestDriver();

        // Pre-flight soket hanya untuk connector; mesin SOAP memakai port web sendiri.
        if ($driver === 'connector' && ! $this->connectorService->ensureAttendanceReachable($device, Auth::id())) {
            return redirect()->route('devices.show', $device)
                ->with('error', 'Mesin '.$device->name.' tidak terhubung.');
        }

        // Lease per mesin: cegah tumpang tindih dengan scheduler/daemon agar tidak ada ingest ganda.
        $outcome = $this->lockService->run($device, function () use ($device, $driver) {
            if ($driver === 'soap') {
                return [
                    'driver' => 'soap',
                    'result' => $this->soapService->pullAttendanceLogs($device, Auth::id()),
                ];
            }

            return [
                'driver' => 'connector',
                'result' => $this->connectorService->syncAttendances($device, Auth::id()),
            ];
        });

        if ($outcome['locked']) {
            return redirect()->route('devices.show', $device)
                ->with('error', 'Mesin sedang diproses oleh proses lain (sinkronisasi otomatis/aksi lain). Coba lagi sebentar.');
        }

        $data = $outcome['result'];
        $result = $data['result'];

        if (! ($result['success'] ?? false)) {
            $message = ($result['busy'] ?? false)
                ? 'Mesin sedang sibuk diproses connector. Coba lagi sebentar.'
                : ($result['message'] ?? 'Gagal menarik absensi.');

            return redirect()->route('devices.show', $device)->with('error', $message);
        }

        $suffix = $data['driver'] === 'soap' ? ' (via Web SOAP)' : ' (via C# Connector)';

        return redirect()->route('devices.show', $device)
            ->with('success', ($result['message'] ?? 'Sinkronisasi absensi selesai.').$suffix);
    }

    /**
     * Sinkronisasikan tanggal dan jam mesin dengan jalur ingest utamanya.
     */
    public function syncTime(FingerprintDevice $device): RedirectResponse|JsonResponse
    {
        if ($response = $this->offlineActionResponse($device, 'devices.show', [$device])) {
            return $response;
        }

        $result = $device->ingestDriver() === 'soap'
            ? $this->soapService->syncTime($device, Auth::id())
            : $this->connectorService->syncTime($device, Auth::id());

        if ($result['success']) {
            return redirect()->route('devices.show', $device)
                ->with('success', $result['message']);
        }

        return redirect()->route('devices.show', $device)
            ->with('error', $result['message']);
    }

    /**
     * Tarik riwayat absensi langsung via HTTP Web SOAP untuk mesin driver SOAP.
     */
    public function pullSoapAttendances(FingerprintDevice $device): RedirectResponse|JsonResponse
    {
        if ($response = $this->offlineActionResponse($device, 'devices.show', [$device])) {
            return $response;
        }

        if ($device->ingestDriver() !== 'soap') {
            return redirect()->route('devices.show', $device)
                ->with('error', 'Mesin ini memakai connector C#. Gunakan tombol Sinkron Absensi.');
        }

        $outcome = $this->lockService->run(
            $device,
            fn () => $this->soapService->pullAttendanceLogs($device, Auth::id())
        );

        if ($outcome['locked']) {
            return redirect()->route('devices.show', $device)
                ->with('error', 'Mesin sedang diproses oleh proses lain (sinkronisasi otomatis/aksi lain). Coba lagi sebentar.');
        }

        $result = $outcome['result'];

        if ($result['success']) {
            return redirect()->route('devices.show', $device)
                ->with('success', $result['message']);
        }

        return redirect()->route('devices.show', $device)
            ->with('error', $result['message']);
    }

    /**
     * Reboot / Restart mesin secara remote (Native Web SOAP SDK).
     */
    public function restartMachine(FingerprintDevice $device): RedirectResponse|JsonResponse
    {
        if ($response = $this->offlineActionResponse($device, 'devices.show', [$device])) {
            return $response;
        }

        $result = $this->soapService->restartMachine($device, Auth::id());

        $this->logRiskyAction($device, 'restart_device', ['success' => $result['success']]);

        if ($result['success']) {
            return redirect()->route('devices.show', $device)
                ->with('success', $result['message']);
        }

        return redirect()->route('devices.show', $device)
            ->with('error', $result['message']);
    }

    /**
     * Bersihkan log absensi di memori mesin (Native Web SOAP SDK).
     */
    public function clearMachineLogs(FingerprintDevice $device): RedirectResponse|JsonResponse
    {
        if ($response = $this->offlineActionResponse($device, 'devices.show', [$device])) {
            return $response;
        }

        $result = $this->soapService->clearAttendanceLogs($device, Auth::id());

        $this->logRiskyAction($device, 'clear_device_logs', ['success' => $result['success']]);

        if ($result['success']) {
            return redirect()->route('devices.show', $device)
                ->with('success', $result['message']);
        }

        return redirect()->route('devices.show', $device)
            ->with('error', $result['message']);
    }

    /**
     * Hapus satu pengguna (PIN) dari memori mesin — aksi destruktif.
     *
     * Dilindungi verifikasi berlapis: hanya admin, PIN dibatasi karakternya,
     * operator harus mengetik frasa konfirmasi persis "HAPUS <PIN>" yang
     * divalidasi ulang di server (bukan hanya di UI), lease per-mesin mencegah
     * tumpang tindih, dan setiap percobaan dicatat pada Log Audit Sistem.
     */
    public function deleteMachineUser(Request $request, FingerprintDevice $device): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'pin' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9._-]+$/'],
            'confirm' => ['required', 'string'],
        ], [
            'pin.regex' => 'PIN hanya boleh berisi huruf, angka, titik, garis bawah, atau tanda hubung.',
        ]);

        $pin = trim($validated['pin']);
        $expected = 'HAPUS '.$pin;

        // Verifikasi kedua di server: tolak apa pun yang tidak persis sama.
        if (trim($validated['confirm']) !== $expected) {
            return redirect()->route('devices.show', $device)
                ->with('error', "Konfirmasi tidak cocok. Ketikkan persis \"{$expected}\" untuk melanjutkan. Tidak ada data yang dihapus.");
        }

        if ($response = $this->offlineActionResponse($device, 'devices.show', [$device])) {
            return $response;
        }

        $outcome = $this->lockService->run(
            $device,
            fn () => $this->soapService->deleteUser($device, $pin, Auth::id())
        );

        if ($outcome['locked']) {
            return redirect()->route('devices.show', $device)
                ->with('error', 'Mesin sedang diproses oleh proses lain (sinkronisasi otomatis/aksi lain). Coba lagi sebentar.');
        }

        $result = $outcome['result'];

        $this->logRiskyAction($device, 'delete_machine_user', [
            'pin' => $pin,
            'success' => $result['success'],
        ]);

        return redirect()->route('devices.show', $device)
            ->with($result['success'] ? 'success' : 'error', $result['message']);
    }

    /**
     * Kirim nama siswa dan PIN ke mesin agar tampil di layar LCD saat absen (Native Web SOAP SDK).
     */
    public function pushMappingToMachine(DeviceUser $mapping): RedirectResponse|JsonResponse
    {
        $device = $mapping->device;
        $student = $mapping->student;

        if (! $device || ! $student) {
            return redirect()->route('devices.mappings')
                ->with('error', 'Data mesin atau siswa tidak ditemukan.');
        }

        if ($response = $this->offlineActionResponse($device, 'devices.mappings')) {
            return $response;
        }

        $result = $this->soapService->pushUserInfo($device, $mapping->machine_user_id, $student->name, Auth::id());

        if ($result['success']) {
            return redirect()->route('devices.mappings')
                ->with('success', $result['message']);
        }

        return redirect()->route('devices.mappings')
            ->with('error', $result['message']);
    }

    /**
     * Kirim nama seluruh siswa terpetakan ke layar LCD mesin secara massal (1-Klik).
     */
    public function bulkPushNames(FingerprintDevice $device): RedirectResponse|JsonResponse
    {
        if (! $this->bulkOperationService->hasMappings($device)) {
            return redirect()->route('devices.show', $device)
                ->with('error', "Belum ada siswa yang dipetakan pada mesin {$device->name}.");
        }

        return $this->bulkOperationService->dispatch(
            BulkDeviceOperation::OP_PUSH_NAMES,
            $device,
            null,
            "Kirim Nama Siswa ke {$device->name}"
        );
    }

    /**
     * Backup seluruh template sidik jari dari mesin ke database server secara massal (1-Klik).
     */
    public function bulkBackupFingerprints(FingerprintDevice $device): RedirectResponse|JsonResponse
    {
        if (! $this->bulkOperationService->hasMappings($device)) {
            return redirect()->route('devices.show', $device)
                ->with('error', "Belum ada siswa yang dipetakan pada mesin {$device->name}.");
        }

        return $this->bulkOperationService->dispatch(
            BulkDeviceOperation::OP_BACKUP_FINGERPRINTS,
            $device,
            null,
            "Backup Sidik Jari {$device->name}"
        );
    }

    /**
     * Kloning seluruh siswa & sidik jari dari satu mesin ke mesin lain secara massal (1-Klik).
     */
    public function bulkCloneDevice(Request $request, FingerprintDevice $device): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'target_device_id' => ['required', 'exists:fingerprint_devices,id'],
        ]);

        $targetDevice = FingerprintDevice::findOrFail($validated['target_device_id']);

        if ($targetDevice->id === $device->id) {
            return redirect()->route('devices.show', $device)
                ->with('error', 'Mesin target harus berbeda dengan mesin asal.');
        }

        if (! $this->bulkOperationService->hasMappings($device)) {
            return redirect()->route('devices.show', $device)
                ->with('error', "Mesin {$device->name} belum memiliki data siswa untuk dikloning.");
        }

        return $this->bulkOperationService->dispatch(
            BulkDeviceOperation::OP_CLONE,
            $device,
            $targetDevice->id,
            "Kloning {$device->name} ke {$targetDevice->name}"
        );
    }

    /**
     * Tolak aksi perangkat sebelum menyentuh task, queue, atau SOAP bila badge
     * mesin sedang offline.
     *
     * @param  array<int|string, mixed>  $backParameters
     */
    private function offlineActionResponse(
        FingerprintDevice $device,
        string $backRoute,
        array $backParameters = [],
        string $subject = 'Mesin'
    ): RedirectResponse|JsonResponse|null {
        $message = $device->offlineActionMessage($subject);

        if ($message === null) {
            return null;
        }

        if (request()->expectsJson()) {
            return response()->json(['message' => $message], 422);
        }

        return redirect()->route($backRoute, $backParameters)->with('error', $message);
    }

    /**
     * Catat aksi berisiko terhadap mesin ke audit log.
     *
     * Aksi berisiko (reboot, bersihkan log, kloning, operasi massal) dicatat
     * di samping DeviceSyncLog supaya jejaknya terlihat pada Log Audit Sistem.
     *
     * @param  array<string, mixed>  $context
     */
    protected function logRiskyAction(FingerprintDevice $device, string $action, array $context = []): void
    {
        $this->logAudit($action, 'FingerprintDevice', $device->id, null, ['device' => $device->name] + $context);
    }

    /**
     * Halaman API khusus yang digunakan oleh AJAX polling untuk membaca status mesin.
     *
     * Endpoint ini sengaja read-only dan tidak melakukan probe LAN. Verifikasi
     * konektivitas fisik dilakukan oleh `fingerprint:health-check` agar request
     * halaman tidak menunggu timeout jaringan.
     *
     * Dikembalikan JSON yang berisi status, HTML badge, dan cap waktu terbaru.
     */
    public function deviceStatus(FingerprintDevice $device): JsonResponse
    {
        $now = now();
        $displayStatus = $device->displayStatus();

        return response()->json([
            'device_id' => $device->id,
            'status' => $device->status,
            'display_status' => $displayStatus,
            'last_seen_at' => $device->last_seen_at,
            'checked_at' => $now->toISOString(),
            'html' => view('components.device-status', ['status' => $displayStatus])->render(),
        ]);
    }
}
