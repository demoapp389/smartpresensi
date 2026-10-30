<?php

namespace Tests\Feature;

use App\Jobs\SendWhatsAppNotificationJob;
use App\Jobs\ValidateGuardianPhoneJob;
use App\Models\Attendance;
use App\Models\Guardian;
use App\Models\NotificationLog;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use App\Services\WhatsApp\FonnteNumberValidationService;
use App\Services\WhatsAppNotificationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class GuardianWhatsAppValidationTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;

    protected SchoolClass $schoolClass;

    protected Student $student;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('whatsapp.default', 'log');
        Config::set('whatsapp.fonnte.token', 'dummy-token');
        Config::set('whatsapp.fonnte.validate_url', 'https://api.fonnte.com/validate');

        $this->adminUser = User::factory()->create([
            'role' => 'admin',
        ]);

        $this->schoolClass = SchoolClass::create([
            'name' => 'X-IPA-1',
            'academic_year' => '2026/2027',
        ]);

        $this->student = Student::create([
            'nis' => '1001',
            'name' => 'Budi Santoso',
            'gender' => 'L',
            'class_id' => $this->schoolClass->id,
            'active' => true,
        ]);
    }

    public function test_guardian_observer_normalizes_phone_and_dispatches_job_on_create(): void
    {
        Queue::fake();

        $guardian = Guardian::create([
            'student_id' => $this->student->id,
            'name' => 'Hasan',
            'relationship' => 'Ayah',
            'wa_number' => '081234567890',
            'consent_at' => now(),
            'active' => true,
        ]);

        $this->assertEquals('6281234567890', $guardian->wa_number);
        $this->assertEquals('pending', $guardian->wa_status);

        Queue::assertPushed(ValidateGuardianPhoneJob::class, function ($job) use ($guardian) {
            return $job->guardianId === $guardian->id && $job->validatedBy === 'auto_create';
        });
    }

    public function test_guardian_observer_resets_status_and_dispatches_job_on_phone_change(): void
    {
        Queue::fake();

        $guardian = Guardian::create([
            'student_id' => $this->student->id,
            'name' => 'Hasan',
            'relationship' => 'Ayah',
            'wa_number' => '081234567890',
            'wa_status' => 'valid',
            'wa_validated_at' => now(),
            'consent_at' => now(),
            'active' => true,
        ]);

        // Ubah nama saja -> tidak reset status
        $guardian->update(['name' => 'Hasan Basri']);
        $this->assertEquals('valid', $guardian->fresh()->wa_status);

        // Ubah nomor telepon -> reset status ke pending & dispatch job
        $guardian->update(['wa_number' => '081298765432']);
        $fresh = $guardian->fresh();

        $this->assertEquals('6281298765432', $fresh->wa_number);
        $this->assertEquals('pending', $fresh->wa_status);
        $this->assertNull($fresh->wa_validated_at);

        Queue::assertPushed(ValidateGuardianPhoneJob::class, function ($job) use ($guardian) {
            return $job->guardianId === $guardian->id && $job->validatedBy === 'auto_update';
        });
    }

    public function test_validate_guardian_job_sets_valid_and_creates_log(): void
    {
        Http::fake([
            'https://api.fonnte.com/validate' => Http::response([
                'status' => true,
                'registered' => ['6281234567890'],
                'not_registered' => [],
            ], 200),
        ]);

        $guardian = Guardian::create([
            'student_id' => $this->student->id,
            'name' => 'Hasan',
            'relationship' => 'Ayah',
            'wa_number' => '081234567890',
            'consent_at' => now(),
            'active' => true,
        ]);

        $service = app(FonnteNumberValidationService::class);
        $job = new ValidateGuardianPhoneJob($guardian->id, 'manual_test');
        $job->handle($service);

        $guardian->refresh();
        $this->assertEquals('valid', $guardian->wa_status);
        $this->assertNotNull($guardian->wa_validated_at);
        $this->assertNull($guardian->wa_validation_error);

        $this->assertDatabaseHas('guardian_phone_validation_logs', [
            'guardian_id' => $guardian->id,
            'phone' => '6281234567890',
            'status' => 'valid',
            'validated_by' => 'manual_test',
        ]);
    }

    public function test_attendance_notification_queued_only_when_guardian_is_valid(): void
    {
        Queue::fake();

        $guardian = Guardian::create([
            'student_id' => $this->student->id,
            'name' => 'Hasan',
            'relationship' => 'Ayah',
            'wa_number' => '081234567890',
            'wa_status' => 'valid',
            'wa_validated_at' => now(),
            'consent_at' => now(),
            'active' => true,
        ]);

        $attendance = Attendance::create([
            'student_id' => $this->student->id,
            'school_date' => Carbon::today(),
            'status' => 'hadir',
            'check_in_at' => now(),
        ]);

        $notificationService = app(WhatsAppNotificationService::class);
        $logs = $notificationService->queueAttendanceNotifications($attendance, 'check_in');

        $this->assertCount(1, $logs);
        $this->assertEquals('queued', $logs[0]->status);
        $this->assertNull($logs[0]->error_message);

        Queue::assertPushed(SendWhatsAppNotificationJob::class);
    }

    public function test_attendance_notification_held_when_guardian_status_pending(): void
    {
        Queue::fake();

        $guardian = Guardian::create([
            'student_id' => $this->student->id,
            'name' => 'Hasan',
            'relationship' => 'Ayah',
            'wa_number' => '081234567890',
            'wa_status' => 'pending',
            'consent_at' => now(),
            'active' => true,
        ]);

        $attendance = Attendance::create([
            'student_id' => $this->student->id,
            'school_date' => Carbon::today(),
            'status' => 'hadir',
            'check_in_at' => now(),
        ]);

        $notificationService = app(WhatsAppNotificationService::class);
        $logs = $notificationService->queueAttendanceNotifications($attendance, 'check_in');

        $this->assertCount(1, $logs);
        $this->assertEquals('held', $logs[0]->status);
        $this->assertStringContainsString('Menunggu hasil validasi', $logs[0]->error_message);

        // SendWhatsAppNotificationJob tidak boleh dikirim langsung
        Queue::assertNotPushed(SendWhatsAppNotificationJob::class);
        // Validasi async dijadwalkan
        Queue::assertPushed(ValidateGuardianPhoneJob::class);
    }

    public function test_attendance_notification_cancelled_for_invalid_guardian_without_affecting_attendance(): void
    {
        Queue::fake();

        $guardian = Guardian::create([
            'student_id' => $this->student->id,
            'name' => 'Hasan',
            'relationship' => 'Ayah',
            'wa_number' => '081234567890',
            'wa_status' => 'invalid',
            'wa_validated_at' => now(),
            'consent_at' => now(),
            'active' => true,
        ]);

        $attendance = Attendance::create([
            'student_id' => $this->student->id,
            'school_date' => Carbon::today(),
            'status' => 'hadir',
            'check_in_at' => now(),
        ]);

        $notificationService = app(WhatsAppNotificationService::class);
        $logs = $notificationService->queueAttendanceNotifications($attendance, 'check_in');

        $this->assertCount(1, $logs);
        $this->assertEquals('cancelled', $logs[0]->status);
        $this->assertStringContainsString('tidak valid', $logs[0]->error_message);

        // Tidak boleh kirim pesan WhatsApp
        Queue::assertNotPushed(SendWhatsAppNotificationJob::class);

        // Record absensi tetap aman dan valid
        $this->assertDatabaseHas('attendances', [
            'id' => $attendance->id,
            'status' => 'hadir',
        ]);
    }

    public function test_manual_override_attendance_does_not_queue_notification(): void
    {
        Queue::fake();

        Guardian::create([
            'student_id' => $this->student->id,
            'name' => 'Hasan',
            'relationship' => 'Ayah',
            'wa_number' => '081234567890',
            'wa_status' => 'valid',
            'wa_validated_at' => now(),
            'consent_at' => now(),
            'active' => true,
        ]);

        $attendance = Attendance::create([
            'student_id' => $this->student->id,
            'school_date' => Carbon::today(),
            'status' => 'izin',
            'check_in_at' => now(),
            'is_manual_override' => true,
            'override_reason' => 'Surat izin resmi.',
        ]);

        $logs = app(WhatsAppNotificationService::class)
            ->queueAttendanceNotifications($attendance, 'check_in');

        $this->assertSame([], $logs);
        $this->assertDatabaseCount('notification_logs', 0);
        Queue::assertNotPushed(SendWhatsAppNotificationJob::class);
    }

    public function test_send_job_cancels_notification_when_attendance_is_manual_override(): void
    {
        $guardian = Guardian::create([
            'student_id' => $this->student->id,
            'name' => 'Hasan',
            'relationship' => 'Ayah',
            'wa_number' => '081234567890',
            'wa_status' => 'valid',
            'wa_validated_at' => now(),
            'consent_at' => now(),
            'active' => true,
        ]);

        $attendance = Attendance::create([
            'student_id' => $this->student->id,
            'school_date' => Carbon::today(),
            'status' => 'izin',
            'check_in_at' => now(),
            'is_manual_override' => true,
            'override_reason' => 'Surat izin resmi.',
        ]);

        $notification = NotificationLog::create([
            'attendance_id' => $attendance->id,
            'guardian_id' => $guardian->id,
            'event_type' => 'check_in',
            'idempotency_key' => 'manual-override-'.$attendance->id,
            'template_name' => 'check_in',
            'recipient_phone' => '6281234567890',
            'payload' => [],
            'status' => 'queued',
            'queued_at' => now(),
        ]);

        (new SendWhatsAppNotificationJob($notification->id))->handle();

        $notification->refresh();

        $this->assertSame('cancelled', $notification->status);
        $this->assertStringContainsString('dikoreksi manual', $notification->error_message);
        $this->assertSame(0, $notification->attempts);
    }

    public function test_send_job_skips_notification_when_lock_is_held(): void
    {
        $guardian = Guardian::create([
            'student_id' => $this->student->id,
            'name' => 'Hasan',
            'relationship' => 'Ayah',
            'wa_number' => '081234567890',
            'wa_status' => 'valid',
            'wa_validated_at' => now(),
            'consent_at' => now(),
            'active' => true,
        ]);

        $attendance = Attendance::create([
            'student_id' => $this->student->id,
            'school_date' => Carbon::today(),
            'status' => 'hadir',
            'check_in_at' => now(),
        ]);

        $notification = NotificationLog::create([
            'attendance_id' => $attendance->id,
            'guardian_id' => $guardian->id,
            'event_type' => 'check_in',
            'idempotency_key' => 'lock-test-'.$attendance->id,
            'template_name' => 'check_in',
            'recipient_phone' => '6281234567890',
            'payload' => [],
            'status' => 'queued',
            'queued_at' => now(),
        ]);

        $lock = Cache::lock('whatsapp-notification:'.$notification->id, 120);
        $this->assertTrue($lock->get());

        try {
            (new SendWhatsAppNotificationJob($notification->id))->handle();
        } finally {
            $lock->release();
        }

        $notification->refresh();

        $this->assertSame('queued', $notification->status);
        $this->assertSame(0, $notification->attempts);
    }

    public function test_release_held_notifications_when_validation_succeeds(): void
    {
        Queue::fake();

        $guardian = Guardian::create([
            'student_id' => $this->student->id,
            'name' => 'Hasan',
            'relationship' => 'Ayah',
            'wa_number' => '081234567890',
            'wa_status' => 'pending',
            'consent_at' => now(),
            'active' => true,
        ]);

        $attendance = Attendance::create([
            'student_id' => $this->student->id,
            'school_date' => Carbon::today(),
            'status' => 'hadir',
            'check_in_at' => now(),
        ]);

        $log = NotificationLog::create([
            'attendance_id' => $attendance->id,
            'guardian_id' => $guardian->id,
            'event_type' => 'check_in',
            'idempotency_key' => 'TEST_HELD_1',
            'template_name' => 'siswa_hadir_v1',
            'recipient_phone' => $guardian->wa_number,
            'status' => 'held',
            'attempts' => 0,
        ]);

        $service = app(FonnteNumberValidationService::class);
        $released = $service->releaseHeldNotifications($guardian);

        $this->assertEquals(1, $released);
        $this->assertEquals('queued', $log->fresh()->status);
        Queue::assertPushed(SendWhatsAppNotificationJob::class, function ($job) use ($log) {
            return $job->notificationId === $log->id;
        });
    }

    public function test_expired_held_notifications_cancelled_instead_of_released(): void
    {
        Queue::fake();

        $guardian = Guardian::create([
            'student_id' => $this->student->id,
            'name' => 'Hasan',
            'relationship' => 'Ayah',
            'wa_number' => '081234567890',
            'wa_status' => 'pending',
            'consent_at' => now(),
            'active' => true,
        ]);

        $attendance = Attendance::create([
            'student_id' => $this->student->id,
            'school_date' => Carbon::yesterday(),
            'status' => 'hadir',
            'check_in_at' => now()->subHours(30),
        ]);

        $log = NotificationLog::create([
            'attendance_id' => $attendance->id,
            'guardian_id' => $guardian->id,
            'event_type' => 'check_in',
            'idempotency_key' => 'TEST_EXPIRED_1',
            'template_name' => 'siswa_hadir_v1',
            'recipient_phone' => $guardian->wa_number,
            'status' => 'held',
            'attempts' => 0,
        ]);

        $log->timestamps = false;
        $log->created_at = now()->subHours(26);
        $log->save();

        $service = app(FonnteNumberValidationService::class);
        $released = $service->releaseHeldNotifications($guardian);

        $this->assertEquals(0, $released);
        $this->assertEquals('cancelled', $log->fresh()->status);
        $this->assertStringContainsString('kedaluwarsa', $log->fresh()->error_message);
        Queue::assertNotPushed(SendWhatsAppNotificationJob::class);
    }

    public function test_idempotency_key_prevents_duplicate_notifications(): void
    {
        Queue::fake();

        $guardian = Guardian::create([
            'student_id' => $this->student->id,
            'name' => 'Hasan',
            'relationship' => 'Ayah',
            'wa_number' => '081234567890',
            'wa_status' => 'valid',
            'consent_at' => now(),
            'active' => true,
        ]);

        $attendance = Attendance::create([
            'student_id' => $this->student->id,
            'school_date' => Carbon::today(),
            'status' => 'hadir',
            'check_in_at' => now(),
        ]);

        $notificationService = app(WhatsAppNotificationService::class);

        // Scan pertama
        $logs1 = $notificationService->queueAttendanceNotifications($attendance, 'check_in');
        $this->assertCount(1, $logs1);

        // Scan ulang / dobel scan pada hari yang sama
        $logs2 = $notificationService->queueAttendanceNotifications($attendance, 'check_in');
        $this->assertCount(0, $logs2);

        $this->assertDatabaseCount('notification_logs', 1);
    }

    public function test_revalidate_command_dispatches_jobs_for_stale_records(): void
    {
        Config::set('whatsapp.validation.auto_validate_on_save', false);
        Config::set('whatsapp.revalidation.enabled', true);
        Config::set('whatsapp.revalidation.interval_days', 30);

        // Guardian 1: divalidasi 40 hari lalu -> perlu revalidasi
        $g1 = Guardian::create([
            'student_id' => $this->student->id,
            'name' => 'Ayah 1',
            'relationship' => 'Ayah',
            'wa_number' => '081234567801',
            'wa_status' => 'valid',
            'wa_validated_at' => now()->subDays(40),
            'consent_at' => now(),
            'active' => true,
        ]);

        // Guardian 2: baru divalidasi kemarin -> tidak perlu
        $g2 = Guardian::create([
            'student_id' => $this->student->id,
            'name' => 'Ayah 2',
            'relationship' => 'Ayah',
            'wa_number' => '081234567802',
            'wa_status' => 'valid',
            'wa_validated_at' => now()->subDay(),
            'consent_at' => now(),
            'active' => true,
        ]);

        Queue::fake();

        $this->artisan('whatsapp:revalidate-guardians')
            ->assertSuccessful();

        Queue::assertPushed(ValidateGuardianPhoneJob::class, function ($job) use ($g1) {
            return $job->guardianId === $g1->id && $job->validatedBy === 'periodic_revalidation';
        });

        Queue::assertNotPushed(ValidateGuardianPhoneJob::class, function ($job) use ($g2) {
            return $job->guardianId === $g2->id;
        });
    }

    public function test_bulk_validate_controller_endpoint_runs_batch(): void
    {
        Config::set('whatsapp.validation.auto_validate_on_save', false);

        Http::fake([
            'https://api.fonnte.com/validate' => Http::response([
                'status' => true,
                'registered' => ['6281234567811'],
                'not_registered' => ['6281234567812'],
            ], 200),
        ]);

        $g1 = Guardian::create([
            'student_id' => $this->student->id,
            'name' => 'Ayah 1',
            'relationship' => 'Ayah',
            'wa_number' => '081234567811',
            'wa_status' => 'pending',
            'consent_at' => now(),
            'active' => true,
        ]);

        $g2 = Guardian::create([
            'student_id' => $this->student->id,
            'name' => 'Ayah 2',
            'relationship' => 'Ayah',
            'wa_number' => '081234567812',
            'wa_status' => 'pending',
            'consent_at' => now(),
            'active' => true,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->postJson(route('guardians.bulk-validate'), [
                'scope' => 'pending',
                'limit' => 50,
            ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'processed' => 2,
                'valid' => 1,
                'invalid' => 1,
            ]);

        $this->assertEquals('valid', $g1->fresh()->wa_status);
        $this->assertEquals('invalid', $g2->fresh()->wa_status);
    }

    public function test_validation_page_renders_client_side_datatables_without_server_pagination(): void
    {
        Config::set('whatsapp.validation.auto_validate_on_save', false);

        Guardian::create([
            'student_id' => $this->student->id,
            'name' => 'Ayah DataTables',
            'relationship' => 'Ayah',
            'wa_number' => '081234567899',
            'wa_status' => 'pending',
            'consent_at' => now(),
            'active' => true,
        ]);

        $html = $this->actingAs($this->adminUser)
            ->get(route('guardians.validation'))
            ->assertOk()
            ->getContent();

        // Data dikirim penuh ke DataTables sisi klien, bukan paginasi server.
        $this->assertStringContainsString('data-datatable', $html);
        $this->assertStringNotContainsString('Halaman 1 dari', $html);
    }
}
