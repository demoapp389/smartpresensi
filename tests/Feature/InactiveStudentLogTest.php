<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AttendanceLog;
use App\Models\DeviceUser;
use App\Models\FingerprintDevice;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use App\Services\AttendanceProcessingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Siswa yang dipindahkan ke Sampah tidak boleh dihapus dari mesin fingerprint.
 * Scan berikutnya dari PIN miliknya ditandai 'inactive_student' agar tidak
 * tercampur dengan PIN yang benar-benar belum dikenal (unmapped).
 */
class InactiveStudentLogTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private SchoolClass $class;

    private FingerprintDevice $device;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();

        $this->class = SchoolClass::create([
            'name' => 'X-RPL 4',
            'academic_year' => '2026/2027',
        ]);

        $this->device = FingerprintDevice::create([
            'name' => 'Solution X601 Gerbang',
            'ip' => '192.168.1.210',
            'port' => 4370,
            'comm_key' => '0',
            'active' => true,
        ]);
    }

    private function createMappedStudent(string $nis, string $name): Student
    {
        $student = Student::create([
            'nis' => $nis,
            'name' => $name,
            'gender' => 'L',
            'class_id' => $this->class->id,
            'active' => true,
        ]);

        DeviceUser::create([
            'device_id' => $this->device->id,
            'machine_user_id' => $nis,
            'student_id' => $student->id,
        ]);

        return $student;
    }

    private function createUnprocessedLog(string $pin): AttendanceLog
    {
        $scanTime = Carbon::parse('2026-10-14 07:05:00', 'Asia/Jakarta');

        return AttendanceLog::create([
            'device_id' => $this->device->id,
            'machine_user_id' => $pin,
            'occurred_at' => $scanTime,
            'received_at' => $scanTime->copy()->addMinute(),
            'source_key' => $this->device->id.'_'.$pin.'_'.$scanTime->timestamp,
            'status' => 'unprocessed',
        ]);
    }

    public function test_scan_from_soft_deleted_student_is_marked_inactive_student(): void
    {
        $student = $this->createMappedStudent('6001', 'Siswa Terhapus');
        $log = $this->createUnprocessedLog('6001');

        $this->actingAs($this->admin)->delete(route('students.destroy', $student));

        $result = app(AttendanceProcessingService::class)->processLog($log);

        $this->assertNull($result, 'Siswa terhapus tidak boleh menghasilkan record absensi.');
        $this->assertSame('inactive_student', $log->fresh()->status);
        $this->assertDatabaseCount('attendances', 0);
    }

    public function test_inactive_student_log_is_not_listed_as_unmapped(): void
    {
        $student = $this->createMappedStudent('6002', 'Siswa Tidak Terpetakan');
        $log = $this->createUnprocessedLog('6002');

        $this->actingAs($this->admin)->delete(route('students.destroy', $student));
        app(AttendanceProcessingService::class)->processLog($log);

        $this->assertDatabaseMissing('attendance_logs', [
            'id' => $log->id,
            'status' => 'unmapped',
        ]);

        $this->actingAs($this->admin)
            ->get(route('logs.unmapped'))
            ->assertOk()
            ->assertDontSee('6002');
    }

    public function test_raw_log_page_can_filter_inactive_student_status(): void
    {
        $student = $this->createMappedStudent('6003', 'Siswa Difilter');
        $log = $this->createUnprocessedLog('6003');

        $this->actingAs($this->admin)->delete(route('students.destroy', $student));
        app(AttendanceProcessingService::class)->processLog($log);

        $response = $this->actingAs($this->admin)->get(route('logs.raw', ['status' => 'inactive_student']));

        $response->assertOk();
        $response->assertSee('Siswa Terhapus');
        $this->assertSame(1, AttendanceLog::where('status', 'inactive_student')->count());
    }

    public function test_restoring_student_reprocesses_pending_inactive_student_logs(): void
    {
        $student = $this->createMappedStudent('6004', 'Siswa Dipulihkan');
        $log = $this->createUnprocessedLog('6004');

        $this->actingAs($this->admin)->delete(route('students.destroy', $student));
        app(AttendanceProcessingService::class)->processLog($log);
        $this->assertSame('inactive_student', $log->fresh()->status);

        $response = $this->actingAs($this->admin)->post(route('students.restore', $student->id));

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertSame('processed', $log->fresh()->status);

        $attendance = Attendance::where('student_id', $student->id)->first();
        $this->assertNotNull($attendance, 'Absensi scan harus kembali dibuat setelah siswa dipulihkan.');
        $this->assertNotNull($attendance->check_in_at);
    }

    public function test_bulk_restoring_students_reprocesses_pending_logs(): void
    {
        $first = $this->createMappedStudent('6005', 'Siswa Bulk Satu');
        $second = $this->createMappedStudent('6006', 'Siswa Bulk Dua');

        $firstLog = $this->createUnprocessedLog('6005');
        $secondLog = $this->createUnprocessedLog('6006');

        $this->actingAs($this->admin)->delete(route('students.bulk-destroy'), [
            'ids' => [$first->id, $second->id],
        ]);

        $processing = app(AttendanceProcessingService::class);
        $processing->processLog($firstLog);
        $processing->processLog($secondLog);

        $this->actingAs($this->admin)->post(route('students.bulk-restore'), [
            'ids' => [$first->id, $second->id],
        ]);

        $this->assertSame('processed', $firstLog->fresh()->status);
        $this->assertSame('processed', $secondLog->fresh()->status);
        $this->assertDatabaseCount('attendances', 2);
    }

    public function test_permanently_deleted_student_still_maps_pin_as_unmapped(): void
    {
        $student = $this->createMappedStudent('6007', 'Siswa Dihapus Permanen');
        $log = $this->createUnprocessedLog('6007');

        $this->actingAs($this->admin)->delete(route('students.destroy', $student));
        $this->actingAs($this->admin)->delete(route('students.force-delete', $student->id));

        $result = app(AttendanceProcessingService::class)->processLog($log);

        $this->assertNull($result);
        $this->assertSame('unmapped', $log->fresh()->status, 'Mapping sudah hilang permanen, jadi log-nya unmapped.');
    }
}
