<?php

namespace App\Http\Controllers;

use App\Concerns\LogsAuditTrail;
use App\Http\Controllers\Concerns\HasHybridFilters;
use App\Jobs\ImportStudentsFromExcel;
use App\Models\AttendanceLog;
use App\Models\DeviceUser;
use App\Models\FingerprintDevice;
use App\Models\Guardian;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Services\AttendanceLogProcessingService;
use App\Services\AttendanceProcessingService;
use App\Services\SimpleXlsxExporter;
use App\Services\SimpleXlsxReader;
use App\Services\SoapFingerprintService;
use App\Support\TaskProgressReporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class StudentController extends Controller
{
    use HasHybridFilters;
    use LogsAuditTrail;

    protected function getFilterConfig(): array
    {
        return [];
    }

    public function __construct(
        protected SoapFingerprintService $soapService,
        protected AttendanceLogProcessingService $logProcessor,
    ) {}

    /**
     * Pencarian siswa untuk pemilih pada modal pemetaan PIN.
     *
     * Hanya-baca dan dibatasi agar ringan: operator mengetik nama atau NIS,
     * server mengembalikan kandidat singkat untuk dipilih.
     */
    public function searchOptions(Request $request): JsonResponse
    {
        $search = trim((string) $request->input('q', ''));

        if (mb_strlen($search) < 2) {
            return response()->json(['data' => []]);
        }

        $students = Student::query()
            ->where('active', true)
            ->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('nis', 'like', "%{$search}%");
            })
            ->with('schoolClass')
            ->orderBy('name')
            ->limit(20)
            ->get(['id', 'name', 'nis', 'class_id']);

        return response()->json([
            'data' => $students->map(fn (Student $student) => [
                'id' => $student->id,
                'name' => $student->name,
                'nis' => $student->nis,
                'class' => $student->schoolClass?->name ?? 'Tanpa Kelas',
            ])->values(),
        ]);
    }

    public function index(Request $request): View
    {
        $status = $request->input('status', 'active');
        $search = $request->input('search');
        $classId = $request->input('class_id');

        $baseQuery = $status === 'trashed' ? Student::onlyTrashed() : Student::query();

        $query = $baseQuery->with(['schoolClass', 'guardians', 'deviceUsers.device'])
            ->leftJoin('classes', 'students.class_id', '=', 'classes.id')
            ->select('students.*')
            ->orderBy('classes.name', 'asc')
            ->orderBy('students.name', 'asc');

        // Custom search across multiple columns
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('students.name', 'like', "%{$search}%")
                    ->orWhere('students.nis', 'like', "%{$search}%")
                    ->orWhere('students.nisn', 'like', "%{$search}%");
            });
        }

        // class_id filter (including 'unassigned')
        if ($classId === 'unassigned') {
            $query->whereNull('students.class_id');
        } elseif ($classId) {
            $query->where('students.class_id', $classId);
        }

        // Apply other filters from trait (device_id if needed)
        $query = $this->applyFilters($query, $request);

        $students = $query->get();
        $classes = SchoolClass::orderBy('name')->get();
        $devices = FingerprintDevice::where('active', true)->get();
        $trashedCount = Student::onlyTrashed()->count();
        $activeCount = Student::count();

        return view('students.index', compact(
            'students',
            'classes',
            'devices',
            'search',
            'classId',
            'status',
            'trashedCount',
            'activeCount'
        ));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'nis' => ['nullable', 'string', 'max:30', 'unique:students,nis'],
            'nisn' => ['nullable', 'string', 'max:30'],
            'name' => ['required', 'string', 'max:100'],
            'gender' => ['required', 'in:L,P'],
            'class_id' => ['required', 'exists:classes,id'],
            'active' => ['boolean'],

            // Data Wali Murid
            'guardian_name' => ['required', 'string', 'max:100'],
            'relationship' => ['required', 'string', 'max:50'],
            'wa_number' => ['nullable', 'string', 'max:25'],
            'consent_given' => ['nullable', 'boolean'],

            // Mapping Mesin Fingerprint Solution X601 (Opsional)
            'device_id' => ['nullable', 'exists:fingerprint_devices,id'],
            'machine_user_id' => ['nullable', 'string', 'max:50', 'regex:/^[A-Za-z0-9._-]+$/'],
        ]);

        $validated['machine_user_id'] = $this->normalizeMachineUserId($validated['machine_user_id'] ?? null);

        if (! empty($validated['device_id']) && $validated['machine_user_id'] !== null) {
            $this->ensureMachineIdIsAvailable((int) $validated['device_id'], $validated['machine_user_id']);
        }

        // Normalisasi nomor WA (E.164) ditangani terpusat oleh GuardianObserver::saving
        // agar konsisten dengan jalur import/validasi dan tidak ada duplikasi logika.
        // Nomor boleh dikosongkan; wali tanpa nomor tidak akan mendapat notifikasi.
        $mapping = DB::transaction(function () use ($validated, $request): ?DeviceUser {
            $student = Student::create([
                'nis' => $validated['nis'] ?? null,
                'nisn' => $validated['nisn'] ?? null,
                'name' => $validated['name'],
                'gender' => $validated['gender'],
                'class_id' => $validated['class_id'],
                'active' => $request->boolean('active', true),
            ]);

            Guardian::create([
                'student_id' => $student->id,
                'name' => $validated['guardian_name'],
                'relationship' => $validated['relationship'],
                'wa_number' => $validated['wa_number'] ?? null,
                'consent_at' => $request->boolean('consent_given') ? now() : null,
                'active' => true,
            ]);

            // Jika PIN mesin diisi, daftarkan pemetaan. Konflik sudah divalidasi
            // sebelum transaksi agar siswa tidak tersimpan setengah jadi.
            $mapping = null;

            if (! empty($validated['device_id']) && $validated['machine_user_id'] !== null) {
                $mapping = DeviceUser::create([
                    'device_id' => $validated['device_id'],
                    'machine_user_id' => $validated['machine_user_id'],
                    'student_id' => $student->id,
                ]);
            }

            $this->logAudit('create_student', 'Student', $student->id, null, $student->toArray());

            return $mapping;
        });

        $this->replayMappingLogs($mapping);

        return redirect()->route('students.index')
            ->with('success', 'Data siswa berhasil ditambahkan.');
    }

    public function update(Request $request, Student $student): RedirectResponse
    {
        $validated = $request->validate([
            'nis' => ['nullable', 'string', 'max:30', 'unique:students,nis,'.$student->id],
            'nisn' => ['nullable', 'string', 'max:30'],
            'name' => ['required', 'string', 'max:100'],
            'gender' => ['required', 'in:L,P'],
            'class_id' => ['required', 'exists:classes,id'],
            'active' => ['boolean'],

            // Data Wali
            'guardian_name' => ['required', 'string', 'max:100'],
            'relationship' => ['required', 'string', 'max:50'],
            'wa_number' => ['nullable', 'string', 'max:25'],
            'consent_given' => ['nullable', 'boolean'],

            // Mapping Mesin
            'device_id' => ['nullable', 'exists:fingerprint_devices,id'],
            'machine_user_id' => ['nullable', 'string', 'max:50', 'regex:/^[A-Za-z0-9._-]+$/'],
        ]);

        $validated['machine_user_id'] = $this->normalizeMachineUserId($validated['machine_user_id'] ?? null);

        if (! empty($validated['device_id']) && $validated['machine_user_id'] !== null) {
            $this->ensureMachineIdIsAvailable(
                (int) $validated['device_id'],
                $validated['machine_user_id'],
                $student->id
            );
        }

        $mapping = DB::transaction(function () use ($student, $validated, $request): ?DeviceUser {
            $before = $student->toArray();

            $student->update([
                'nis' => $validated['nis'] ?? null,
                'nisn' => $validated['nisn'] ?? null,
                'name' => $validated['name'],
                'gender' => $validated['gender'],
                'class_id' => $validated['class_id'],
                'active' => $request->boolean('active', true),
            ]);

            // Update atau create guardian
            $guardian = $student->guardians()->first();
            if ($guardian) {
                $guardian->update([
                    'name' => $validated['guardian_name'],
                    'relationship' => $validated['relationship'],
                    'wa_number' => $validated['wa_number'] ?? null,
                    'consent_at' => $request->boolean('consent_given') ? ($guardian->consent_at ?? now()) : null,
                ]);
            } else {
                Guardian::create([
                    'student_id' => $student->id,
                    'name' => $validated['guardian_name'],
                    'relationship' => $validated['relationship'],
                    'wa_number' => $validated['wa_number'] ?? null,
                    'consent_at' => $request->boolean('consent_given') ? now() : null,
                    'active' => true,
                ]);
            }

            // Update mapping mesin. Konflik PIN sudah divalidasi sebelum transaksi.
            $mapping = null;

            if (! empty($validated['device_id']) && $validated['machine_user_id'] !== null) {
                $mapping = DeviceUser::updateOrCreate(
                    ['device_id' => $validated['device_id'], 'student_id' => $student->id],
                    ['machine_user_id' => $validated['machine_user_id']]
                );
            }

            $this->logAudit('update_student', 'Student', $student->id, $before, $student->toArray());

            return $mapping;
        });

        $this->replayMappingLogs($mapping);

        return redirect()->route('students.index')
            ->with('success', 'Data siswa berhasil diperbarui.');
    }

    public function destroy(Student $student, Request $request): RedirectResponse
    {
        $before = $student->toArray();

        // Penghapusan di aplikasi TIDAK pernah menyentuh mesin fingerprint.
        // PIN & template sidik jari di mesin hanya dihapus lewat halaman Devices.
        $student->delete();

        $this->logAudit('delete_student', 'Student', $student->id, $before, ['deleted_at' => now()]);

        return redirect()->route('students.index')
            ->with('success', "Data siswa {$student->name} berhasil dipindahkan ke Sampah. Sidik jari di mesin fisik tetap AMAN.");
    }

    /**
     * Hapus beberapa siswa sekaligus (Soft Delete).
     */
    public function bulkDestroy(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer', 'exists:students,id'],
        ]);

        $ids = $validated['ids'];

        $students = Student::whereIn('id', $ids)->get();

        $deletedStudents = $students
            ->map(fn (Student $student) => ['id' => $student->id, 'name' => $student->name])
            ->all();
        $deletedCount = count($deletedStudents);

        DB::transaction(function () use ($ids, $deletedStudents) {
            Student::whereIn('id', $ids)->delete();

            $this->logAudit('delete_students_bulk', 'Student', 0, ['deleted' => $deletedStudents], ['deleted_at' => now()]);
        });

        return redirect()->route('students.index')
            ->with('success', "{$deletedCount} data siswa berhasil dipindahkan ke Sampah. Sidik jari di mesin fisik tetap AMAN.");
    }

    /**
     * Pulihkan data siswa dari Sampah (Restore Soft Delete).
     */
    public function restore(int $id, Request $request): RedirectResponse
    {
        $student = Student::onlyTrashed()->findOrFail($id);
        $student->restore();

        $reprocessed = $this->reprocessInactiveStudentLogs([$student->id]);

        $this->logAudit('restore_student', 'Student', $student->id, ['deleted_at' => $student->deleted_at], ['deleted_at' => null, 'reprocessed_logs' => $reprocessed]);

        $message = "Data siswa {$student->name} berhasil dipulihkan dari Sampah.";

        if ($reprocessed > 0) {
            $message .= " {$reprocessed} log scan yang tertunda berhasil diproses menjadi absensi.";
        }

        return redirect()->back()
            ->with('success', $message);
    }

    /**
     * Proses ulang log scan yang tertahan berstatus 'inactive_student' untuk siswa
     * yang baru saja dipulihkan dari Sampah, sehingga absensinya kembali tercatat.
     *
     * @param  array<int, int>  $studentIds
     */
    protected function reprocessInactiveStudentLogs(array $studentIds): int
    {
        if ($studentIds === []) {
            return 0;
        }

        $mappings = DeviceUser::whereIn('student_id', $studentIds)->get();

        if ($mappings->isEmpty()) {
            return 0;
        }

        $logs = AttendanceLog::where('status', 'inactive_student')
            ->where(function ($query) use ($mappings) {
                foreach ($mappings as $mapping) {
                    $query->orWhere(function ($inner) use ($mapping) {
                        $inner->where('device_id', $mapping->device_id)
                            ->where('machine_user_id', $mapping->machine_user_id);
                    });
                }
            })
            ->get();

        $processingService = app(AttendanceProcessingService::class);
        $processed = 0;

        foreach ($logs as $log) {
            $attendance = $processingService->processLog($log);

            if ($attendance && $log->fresh()?->status === 'processed') {
                $processed++;
            }
        }

        return $processed;
    }

    /**
     * Pulihkan beberapa data siswa sekaligus dari Sampah.
     */
    public function bulkRestore(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $ids = $validated['ids'];
        $count = Student::onlyTrashed()->whereIn('id', $ids)->restore();

        $reprocessed = $this->reprocessInactiveStudentLogs($ids);

        $this->logAudit('bulk_restore_students', 'Student', 0, ['ids' => $ids], ['restored_count' => $count, 'reprocessed_logs' => $reprocessed]);

        $message = "{$count} data siswa berhasil dipulihkan dari Sampah.";

        if ($reprocessed > 0) {
            $message .= " {$reprocessed} log scan yang tertunda berhasil diproses menjadi absensi.";
        }

        return redirect()->back()
            ->with('success', $message);
    }

    /**
     * Hapus data siswa secara permanen (Force Delete).
     */
    public function forceDelete(int $id, Request $request): RedirectResponse
    {
        $student = Student::onlyTrashed()->findOrFail($id);
        $before = $student->toArray();

        // Penghapusan di aplikasi TIDAK pernah menyentuh mesin fingerprint.
        $devicePins = $student->deviceUsers()->pluck('machine_user_id')->all();

        DB::transaction(function () use ($student, $before) {
            $student->guardians()->delete();
            $student->attendances()->each(function ($att) {
                $att->notifications()->delete();
                $att->delete();
            });
            $student->deviceUsers()->delete();
            $student->biometricTemplates()->delete();
            $student->forceDelete();

            $this->logAudit('force_delete_student', 'Student', $student->id, $before, ['permanent' => true]);
        });

        $machineNote = $devicePins
            ? ' PIN mesin ('.implode(', ', $devicePins).') masih tersimpan di perangkat.'
            : '';

        return redirect()->back()
            ->with('success', "Data siswa {$student->name} telah dihapus permanen.{$machineNote}");
    }

    /**
     * Hapus permanen beberapa data siswa sekaligus dari Sampah.
     */
    public function bulkForceDelete(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $ids = $validated['ids'];

        $students = Student::onlyTrashed()->with('deviceUsers')->whereIn('id', $ids)->get();

        $devicePins = $students
            ->flatMap(fn (Student $student) => $student->deviceUsers->pluck('machine_user_id'))
            ->unique()
            ->values()
            ->all();

        $count = $students->count();

        DB::transaction(function () use ($students, $ids, $count) {
            foreach ($students as $student) {
                $student->guardians()->delete();
                $student->attendances()->each(function ($att) {
                    $att->notifications()->delete();
                    $att->delete();
                });
                $student->deviceUsers()->delete();
                $student->biometricTemplates()->delete();
                $student->forceDelete();
            }

            $this->logAudit('bulk_force_delete_students', 'Student', 0, ['ids' => $ids], ['count' => $count, 'permanent' => true]);
        });

        $machineNote = $devicePins
            ? ' PIN mesin ('.implode(', ', $devicePins).') masih tersimpan di perangkat.'
            : '';

        return redirect()->back()
            ->with('success', "{$count} data siswa telah dihapus permanen.{$machineNote}");
    }

    /**
     * Petakan beberapa siswa sekaligus ke kelas tertentu secara massal.
     */
    public function bulkAssignClass(Request $request): RedirectResponse
    {
        $request->merge([
            'ids' => $request->input('ids') ?? $request->input('student_ids'),
        ]);

        $validated = $request->validate([
            'class_id' => ['required', 'exists:classes,id'],
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:students,id'],
        ]);

        $class = SchoolClass::findOrFail($validated['class_id']);
        $ids = $validated['ids'];
        $count = count($ids);

        DB::transaction(function () use ($ids, $class, $count) {
            Student::whereIn('id', $ids)->update([
                'class_id' => $class->id,
            ]);

            $this->logAudit('bulk_assign_student_class', 'Student', 0, ['student_ids' => $ids], ['class_id' => $class->id, 'class_name' => $class->name, 'count' => $count]);
        });

        return redirect()->back()
            ->with('success', "Berhasil memetakan {$count} siswa ke kelas {$class->name}.");
    }

    /**
     * Download format template Excel (.xlsx) untuk import data siswa.
     */
    public function downloadTemplate(): BinaryFileResponse
    {
        $headers = [
            'NIS',
            'NISN',
            'Nama Siswa',
            'Jenis Kelamin (L/P)',
            'Kelas',
            'Nama Wali',
            'Hubungan',
            'No WhatsApp Wali',
            'ID Mesin (Opsional, bukan NIS)',
            'Persetujuan WA (Ya/Tidak)',
        ];

        $sampleRows = [
            [
                '2026001001',
                '0012345678',
                'Ahmad Fauzi',
                'L',
                'X-RPL 1',
                'Budi Santoso',
                'Ayah',
                '081234567890',
                '101',
                'Ya',
            ],
            [
                '2026001002',
                '0012345679',
                'Siti Rahma',
                'P',
                'X-RPL 1',
                'Dewi Lestari',
                'Ibu',
                '081298765432',
                '102',
                'Tidak',
            ],
        ];

        return SimpleXlsxExporter::download('template_import_siswa.xlsx', $headers, $sampleRows, 'Template Siswa');
    }

    /**
     * Import data siswa dari file Excel (.xlsx).
     */
    public function import(Request $request): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'max:5120'],
            'device_id' => ['nullable', 'exists:fingerprint_devices,id'],
        ]);

        $file = $request->file('file');
        $extension = strtolower($file->getClientOriginalExtension());

        if ($extension !== 'xlsx') {
            return redirect()->route('students.index')
                ->withErrors(['file' => 'Format file tidak didukung. Harap unggah file Microsoft Excel dengan format .xlsx.']);
        }

        // File harus dipindahkan ke storage dulu karena proses dijalankan di queue worker,
        // bukan di dalam siklus request.
        $storedPath = $file->store('imports');
        $absolutePath = Storage::path($storedPath);

        // Pemeriksaan cepat jumlah baris supaya kesalahan input dilaporkan langsung
        // tanpa perlu menunggu worker berjalan.
        try {
            $rows = SimpleXlsxReader::parse($absolutePath);
        } catch (\Throwable $e) {
            Storage::delete($storedPath);

            return redirect()->route('students.index')
                ->withErrors(['file' => 'Gagal membaca isi file Excel: '.$e->getMessage()]);
        }

        if (count($rows) <= 1) {
            Storage::delete($storedPath);

            return redirect()->route('students.index')
                ->withErrors(['file' => 'File Excel kosong atau hanya berisi baris header judul.']);
        }

        $reporter = TaskProgressReporter::start(
            'students.import',
            'Import Data Siswa',
            count($rows) - 1,
            Auth::id()
        );

        ImportStudentsFromExcel::dispatch(
            $absolutePath,
            $file->getClientOriginalName(),
            $validated['device_id'] ?? null,
            Auth::id(),
            $request->ip(),
            $reporter->uuid()
        );

        if ($request->expectsJson()) {
            return response()->json([
                'uuid' => $reporter->uuid(),
                'progress_token' => $reporter->signature(),
                'type' => 'students.import',
                'total' => count($rows) - 1,
                'message' => 'Import sedang berjalan di latar belakang.',
            ], 202);
        }

        return redirect()->route('students.index')
            ->with('success', "Import sedang berjalan di latar belakang. File {$file->getClientOriginalName()} akan diproses.");
    }

    private function replayMappingLogs(?DeviceUser $mapping): void
    {
        if ($mapping === null) {
            return;
        }

        $this->logProcessor->processLogsForMapping(
            (int) $mapping->device_id,
            (string) $mapping->machine_user_id
        );
    }

    private function normalizeMachineUserId(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function ensureMachineIdIsAvailable(int $deviceId, string $machineUserId, ?int $ignoreStudentId = null): void
    {
        $query = DeviceUser::where('device_id', $deviceId)
            ->where('machine_user_id', $machineUserId);

        if ($ignoreStudentId !== null) {
            $query->where('student_id', '!=', $ignoreStudentId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'machine_user_id' => "ID Mesin {$machineUserId} sudah digunakan siswa lain pada mesin ini.",
            ]);
        }
    }
}
