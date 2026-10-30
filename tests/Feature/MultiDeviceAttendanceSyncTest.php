<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AttendanceLog;
use App\Models\DeviceUser;
use App\Models\FingerprintDevice;
use App\Models\Guardian;
use App\Models\NotificationLog;
use App\Models\SchoolClass;
use App\Models\SchoolSchedule;
use App\Models\Student;
use App\Services\AttendanceProcessingService;
use App\Services\FingerprintIngestService;
use App\Services\SoapFingerprintService;
use App\Services\WhatsAppNotificationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Perlindungan multi-mesin: anti-duplikasi lintas jalur ingest (connector & Web SOAP),
 * jam pulang sekali (Opsi A), dan penghematan kuota WA per nomor wali.
 */
class MultiDeviceAttendanceSyncTest extends TestCase
{
    use RefreshDatabase;

    private FingerprintDevice $device;

    private SchoolClass $class;

    protected function setUp(): void
    {
        parent::setUp();

        $this->class = SchoolClass::create([
            'name' => 'X-RPL 1',
            'academic_year' => '2026/2027',
        ]);

        $this->device = FingerprintDevice::create([
            'name' => 'Solution X601 Gerbang',
            'ip' => '192.168.1.211',
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

    public function test_canonical_source_key_matches_connector_format(): void
    {
        $time = Carbon::create(2026, 10, 14, 7, 5, 12);

        $this->assertSame('x601_101_'.$time->timestamp, FingerprintIngestService::canonicalSourceKey('101', $time));
    }

    public function test_repeated_canonical_log_is_deduplicated_across_ingest_paths(): void
    {
        $this->createMappedStudent('7001', 'Budi');

        $time = Carbon::create(2026, 10, 14, 7, 5, 12);
        $item = [
            'machine_user_id' => '7001',
            'occurred_at' => $time->toDateTimeString(),
        ];

        $service = app(FingerprintIngestService::class);

        $first = $service->ingestBatch($this->device, [$item]);
        $second = $service->ingestBatch($this->device, [$item]);

        $this->assertSame(1, $first['inserted']);
        $this->assertSame(0, $second['inserted']);
        $this->assertSame(1, $second['duplicates_skipped']);
        $this->assertSame(1, AttendanceLog::where('device_id', $this->device->id)->count());
    }

    public function test_soap_pull_stores_canonical_source_key_so_it_merges_with_connector(): void
    {
        $this->createMappedStudent('7002', 'Citra');

        $scanTime = Carbon::create(2026, 10, 14, 7, 6, 0);
        $canonicalKey = FingerprintIngestService::canonicalSourceKey('7002', $scanTime);

        // Simulasi jalur connector sudah menyimpan log yang sama lebih dulu.
        app(FingerprintIngestService::class)->ingestBatch($this->device, [[
            'machine_user_id' => '7002',
            'occurred_at' => $scanTime->toDateTimeString(),
            'source_key' => $canonicalKey,
        ]]);

        $service = new class(app(FingerprintIngestService::class), app(AttendanceProcessingService::class), app(WhatsAppNotificationService::class)) extends SoapFingerprintService
        {
            public string $simulatedResponse = '';

            public function sendSoapRequest(string $ip, int $port, string $soapXml, float $timeout = 3.0): ?string
            {
                return $this->simulatedResponse;
            }
        };

        $service->simulatedResponse = "HTTP/1.0 200 OK\r\n\r\n<GetAttLogResponse>"
            .'<Row><PIN>7002</PIN><DateTime>2026-10-14 07:06:00</DateTime><Verified>1</Verified><Status>0</Status></Row>'
            .'</GetAttLogResponse>';

        $result = $service->pullAttendanceLogs($this->device);

        $this->assertTrue($result['success']);
        $this->assertSame(0, $result['records_count'], 'Log yang sama dari jalur berbeda tidak boleh tersimpan dua kali.');
        $this->assertSame(1, AttendanceLog::where('device_id', $this->device->id)->count());
    }

    public function test_checkout_is_recorded_once_at_first_afternoon_scan(): void
    {
        $student = $this->createMappedStudent('7003', 'Dedi');

        SchoolSchedule::create([
            'day_pattern' => 'regular',
            'day_name' => 'Rabu',
            'day_of_week' => 3,
            'check_in_start' => '06:00:00',
            'start_at' => '07:00:00',
            'late_after' => '07:15:00',
            'check_out_start' => '14:00:00',
            'end_at' => '16:00:00',
            'is_active' => true,
        ]);

        $processing = app(AttendanceProcessingService::class);

        $makeLog = function (string $time) use ($student) {
            $scan = Carbon::parse($time, 'Asia/Jakarta');

            return AttendanceLog::create([
                'device_id' => $this->device->id,
                'machine_user_id' => $student->nis,
                'occurred_at' => $scan,
                'received_at' => $scan->copy()->addMinute(),
                'source_key' => FingerprintIngestService::canonicalSourceKey($student->nis, $scan),
                'status' => 'unprocessed',
            ]);
        };

        $processing->processLog($makeLog('2026-10-14 07:05:00'));

        $firstAfternoon = $makeLog('2026-10-14 14:05:00');
        $processing->processLog($firstAfternoon);

        $secondAfternoon = $makeLog('2026-10-14 14:30:00');
        $processing->processLog($secondAfternoon);

        $attendance = Attendance::where('student_id', $student->id)->firstOrFail();

        $this->assertSame('2026-10-14 07:05:00', $attendance->check_in_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-14 14:05:00', $attendance->check_out_at->format('Y-m-d H:i:s'));
        $this->assertSame('processed', $firstAfternoon->fresh()->status);
        $this->assertSame('ignored', $secondAfternoon->fresh()->status);
        $this->assertSame(1, Attendance::where('student_id', $student->id)->count());
        $this->assertSame('check_out', $processing->notificationEventType($attendance));
    }

    public function test_late_scan_after_auto_alpa_returns_to_scan_flow_and_clears_automatic_reason(): void
    {
        $student = $this->createMappedStudent('7007', 'Hana');

        SchoolSchedule::create([
            'day_pattern' => 'regular',
            'day_name' => 'Rabu',
            'day_of_week' => 3,
            'check_in_start' => '06:00:00',
            'start_at' => '07:00:00',
            'late_after' => '07:15:00',
            'check_out_start' => '14:00:00',
            'end_at' => '16:00:00',
            'is_active' => true,
        ]);

        $attendance = Attendance::create([
            'student_id' => $student->id,
            'school_date' => '2026-10-14',
            'status' => 'alpa',
            'override_reason' => 'Auto-Alpa H+1: tidak ada scan masuk dan pulang serta belum ditinjau.',
            'is_manual_override' => false,
            'policy_version' => 'v1.1-auto-alpa',
        ]);

        $scan = Carbon::parse('2026-10-14 07:05:00', 'Asia/Jakarta');
        $log = AttendanceLog::create([
            'device_id' => $this->device->id,
            'machine_user_id' => $student->nis,
            'occurred_at' => $scan,
            'received_at' => $scan->copy()->addMinute(),
            'source_key' => FingerprintIngestService::canonicalSourceKey($student->nis, $scan),
            'status' => 'unprocessed',
        ]);

        app(AttendanceProcessingService::class)->processLog($log);

        $attendance->refresh();

        $this->assertSame('hadir', $attendance->status);
        $this->assertSame('2026-10-14 07:05:00', $attendance->check_in_at->format('Y-m-d H:i:s'));
        $this->assertNull($attendance->override_reason);
        $this->assertNull($attendance->operator_id);
        $this->assertSame('v1.0', $attendance->policy_version);
    }

    public function test_completing_scan_pair_clears_scan_reason_for_pending_attendance(): void
    {
        $student = $this->createMappedStudent('7008', 'Intan');

        SchoolSchedule::create([
            'day_pattern' => 'regular',
            'day_name' => 'Rabu',
            'day_of_week' => 3,
            'check_in_start' => '06:00:00',
            'start_at' => '07:00:00',
            'late_after' => '07:15:00',
            'check_out_start' => '14:00:00',
            'end_at' => '16:00:00',
            'is_active' => true,
        ]);

        $processing = app(AttendanceProcessingService::class);

        $makeLog = function (string $time) use ($student) {
            $scan = Carbon::parse($time, 'Asia/Jakarta');

            return AttendanceLog::create([
                'device_id' => $this->device->id,
                'machine_user_id' => $student->nis,
                'occurred_at' => $scan,
                'received_at' => $scan->copy()->addMinute(),
                'source_key' => FingerprintIngestService::canonicalSourceKey($student->nis, $scan),
                'status' => 'unprocessed',
            ]);
        };

        $processing->processLog($makeLog('2026-10-14 07:05:00'));

        Attendance::where('student_id', $student->id)->update([
            'override_reason' => 'Mesin pulang sedang bermasalah.',
        ]);

        $processing->processLog($makeLog('2026-10-14 14:05:00'));

        $attendance = Attendance::where('student_id', $student->id)->firstOrFail();

        $this->assertSame('hadir', $attendance->status);
        $this->assertNull($attendance->override_reason);
        $this->assertNull($attendance->operator_id);
    }

    public function test_afternoon_scan_without_morning_check_in_is_checkout_only_and_pending_review(): void
    {
        $student = $this->createMappedStudent('7006', 'Gita');

        SchoolSchedule::create([
            'day_pattern' => 'regular',
            'day_name' => 'Rabu',
            'day_of_week' => 3,
            'check_in_start' => '06:00:00',
            'start_at' => '07:00:00',
            'late_after' => '07:15:00',
            'check_out_start' => '14:00:00',
            'end_at' => '16:00:00',
            'is_active' => true,
        ]);

        $scan = Carbon::parse('2026-10-14 14:05:00', 'Asia/Jakarta');
        $log = AttendanceLog::create([
            'device_id' => $this->device->id,
            'machine_user_id' => $student->nis,
            'occurred_at' => $scan,
            'received_at' => $scan->copy()->addMinute(),
            'source_key' => FingerprintIngestService::canonicalSourceKey($student->nis, $scan),
            'status' => 'unprocessed',
        ]);

        $processing = app(AttendanceProcessingService::class);
        $attendance = $processing->processLog($log);

        $this->assertNotNull($attendance);
        $this->assertNull($attendance->check_in_at);
        $this->assertSame('2026-10-14 14:05:00', $attendance->check_out_at->format('Y-m-d H:i:s'));
        $this->assertSame('perlu_tinjauan', $attendance->status);
        $this->assertSame('tidak_absen', $attendance->check_in_status);
        $this->assertSame('perlu_verifikasi', $attendance->check_out_status);
        $this->assertSame('processed', $log->fresh()->status);
        $this->assertNull($processing->notificationEventType($attendance));
    }

    public function test_guardians_sharing_one_phone_receive_a_single_notification_per_event(): void
    {
        Queue::fake();

        $student = Student::create([
            'nis' => '7004',
            'name' => 'Eka',
            'gender' => 'P',
            'class_id' => $this->class->id,
            'active' => true,
        ]);

        foreach (['Ayah' => 'Eka Senior', 'Ibu' => 'Eka Junior'] as $relation => $name) {
            Guardian::create([
                'student_id' => $student->id,
                'name' => $name,
                'relationship' => $relation,
                'wa_number' => '081234567890',
                'wa_status' => 'valid',
                'wa_validated_at' => now(),
                'consent_at' => now(),
                'active' => true,
            ]);
        }

        $attendance = Attendance::create([
            'student_id' => $student->id,
            'school_date' => Carbon::today(),
            'status' => 'hadir',
            'check_in_at' => now(),
        ]);

        $logs = app(WhatsAppNotificationService::class)->queueAttendanceNotifications($attendance, 'check_in');

        $this->assertCount(1, $logs, 'Dua wali dengan nomor sama harus menerima satu pesan saja.');
        $this->assertSame('6281234567890', $logs[0]->recipient_phone);
        $this->assertSame(1, NotificationLog::where('attendance_id', $attendance->id)->where('event_type', 'check_in')->count());
    }

    public function test_first_check_in_is_not_overwritten_by_later_scan(): void
    {
        $student = $this->createMappedStudent('7005', 'Fajar');
        $processing = app(AttendanceProcessingService::class);

        $makeLog = function (string $time) use ($student) {
            $scan = Carbon::parse($time, 'Asia/Jakarta');

            return AttendanceLog::create([
                'device_id' => $this->device->id,
                'machine_user_id' => $student->nis,
                'occurred_at' => $scan,
                'received_at' => $scan->copy()->addMinute(),
                'source_key' => FingerprintIngestService::canonicalSourceKey($student->nis, $scan),
                'status' => 'unprocessed',
            ]);
        };

        $processing->processLog($makeLog('2026-10-14 07:05:00'));

        $second = $makeLog('2026-10-14 07:06:00');
        $processing->processLog($second);

        $attendance = Attendance::where('student_id', $student->id)->firstOrFail();

        $this->assertSame('2026-10-14 07:05:00', $attendance->check_in_at->format('Y-m-d H:i:s'));
        $this->assertSame('ignored', $second->fresh()->status);
    }
}
