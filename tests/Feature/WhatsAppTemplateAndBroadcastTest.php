<?php

namespace Tests\Feature;

use App\Jobs\SendWhatsAppBroadcastJob;
use App\Models\Guardian;
use App\Models\NotificationLog;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\TaskProgress;
use App\Models\User;
use App\Models\WhatsAppTemplate;
use App\Services\WhatsApp\WhatsAppManager;
use App\Support\TaskProgressReporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WhatsAppTemplateAndBroadcastTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $operator;

    protected SchoolClass $classA;

    protected SchoolClass $classB;

    protected Student $student1;

    protected Student $student2;

    protected Student $student3;

    protected Guardian $guardian1;

    protected Guardian $guardian2;

    protected Guardian $guardian3;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role' => 'admin',
        ]);

        $this->operator = User::factory()->create([
            'role' => 'operator',
        ]);

        $this->classA = SchoolClass::create([
            'name' => 'X-MIPA-1',
            'academic_year' => '2026/2027',
        ]);

        $this->classB = SchoolClass::create([
            'name' => 'X-MIPA-2',
            'academic_year' => '2026/2027',
        ]);

        $this->student1 = Student::create([
            'nis' => '1001',
            'name' => 'Ahmad Fauzi',
            'class_id' => $this->classA->id,
            'active' => true,
        ]);

        $this->student2 = Student::create([
            'nis' => '1002',
            'name' => 'Budi Santoso',
            'class_id' => $this->classA->id,
            'active' => true,
        ]);

        // Student 3 in class B
        $this->student3 = Student::create([
            'nis' => '1003',
            'name' => 'Citra Lestari',
            'class_id' => $this->classB->id,
            'active' => true,
        ]);

        // Guardian 1 for student 1
        $this->guardian1 = Guardian::create([
            'student_id' => $this->student1->id,
            'name' => 'Hendra Fauzi',
            'wa_number' => '6281200000001',
            'wa_status' => 'valid',
            'relationship' => 'ayah',
            'active' => true,
        ]);

        // Guardian 2 for student 2 (shares same phone number as guardian 1 - testing deduplication)
        $this->guardian2 = Guardian::create([
            'student_id' => $this->student2->id,
            'name' => 'Hendra Fauzi (Wali Budi)',
            'wa_number' => '6281200000001',
            'wa_status' => 'valid',
            'relationship' => 'wali',
            'active' => true,
        ]);

        // Guardian 3 for student 3
        $this->guardian3 = Guardian::create([
            'student_id' => $this->student3->id,
            'name' => 'Dewi Lestari',
            'wa_number' => '6281200000002',
            'wa_status' => 'valid',
            'relationship' => 'ibu',
            'active' => true,
        ]);

        // Update basic WhatsApp templates for test consistency
        WhatsAppTemplate::updateOrCreate(
            ['event_type' => 'check_in'],
            [
                'name' => 'Presensi Masuk Tepat Waktu',
                'content' => 'Halo :nama_wali, :nama_siswa telah hadir di :nama_sekolah pada :tanggal jam :waktu.',
                'description' => 'Notifikasi saat siswa tap kartu / sidik jari sebelum jam batas terlambat.',
                'is_active' => true,
            ]
        );

        WhatsAppTemplate::updateOrCreate(
            ['event_type' => 'broadcast'],
            [
                'name' => 'Siaran Pengumuman Sekolah',
                'content' => "Yth. :nama_wali,\n\n:pesan\n\nSalam hormat,\n:nama_sekolah",
                'description' => 'Template umum untuk pengumuman massal sekolah kepada wali murid.',
                'is_active' => true,
            ]
        );
    }

    public function test_admin_can_view_whatsapp_template_editor(): void
    {
        $response = $this->actingAs($this->admin)->get(route('whatsapp.templates.index'));

        $response->assertStatus(200);
        $response->assertSee('Template Pesan WhatsApp');
        $response->assertSee('Presensi Masuk Tepat Waktu');
        $response->assertSee('Siaran Pengumuman Sekolah');
    }

    public function test_operator_cannot_view_whatsapp_template_editor(): void
    {
        $response = $this->actingAs($this->operator)->get(route('whatsapp.templates.index'));

        $response->assertStatus(403);
    }

    public function test_admin_can_update_whatsapp_template(): void
    {
        $template = WhatsAppTemplate::where('event_type', 'check_in')->first();

        $response = $this->actingAs($this->admin)->put(route('whatsapp.templates.update', $template), [
            'name' => 'Presensi Masuk Diperbarui',
            'content' => 'Selamat pagi :nama_wali, ananda :nama_siswa hadir pukul :waktu.',
            'is_active' => 1,
        ]);

        $response->assertRedirect(route('whatsapp.templates.index'));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('whatsapp_templates', [
            'id' => $template->id,
            'name' => 'Presensi Masuk Diperbarui',
            'content' => 'Selamat pagi :nama_wali, ananda :nama_siswa hadir pukul :waktu.',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $this->admin->id,
            'action' => 'update_whatsapp_template',
            'entity_type' => 'WhatsAppTemplate',
            'entity_id' => $template->id,
        ]);
    }

    public function test_preview_whatsapp_template_renders_placeholders(): void
    {
        $response = $this->actingAs($this->admin)->postJson(route('whatsapp.templates.preview'), [
            'content' => 'Halo :nama_wali, ananda :nama_siswa kelas :kelas hadir di :nama_sekolah pada :waktu.',
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['rendered', 'char_count']);
        $this->assertStringContainsString('Muhammad Rizky Pratama', $response->json('rendered'));
        $this->assertStringContainsString('X-IPA-1', $response->json('rendered'));
    }

    public function test_both_admin_and_operator_can_view_broadcast_composer(): void
    {
        $adminResponse = $this->actingAs($this->admin)->get(route('whatsapp.broadcast.create'));
        $adminResponse->assertStatus(200);
        $adminResponse->assertSee('Siaran Pengumuman Massal');

        $operatorResponse = $this->actingAs($this->operator)->get(route('whatsapp.broadcast.create'));
        $operatorResponse->assertStatus(200);
        $operatorResponse->assertSee('Siaran Pengumuman Massal');
    }

    public function test_recipient_count_endpoint_calculates_correctly(): void
    {
        // 3 active students, 2 unique phone numbers across all students
        $responseAll = $this->actingAs($this->admin)->json('POST', route('whatsapp.broadcast.recipient-count'), [
            'target_type' => 'all',
        ]);

        $responseAll->assertStatus(200);
        $responseAll->assertJson([
            'total_students' => 3,
            'total_guardians' => 2,
            'valid_guardians' => 2,
        ]);

        // Filter by class A only (students 1 & 2 share same phone number: 1 unique recipient)
        $responseClass = $this->actingAs($this->admin)->json('POST', route('whatsapp.broadcast.recipient-count'), [
            'target_type' => 'class',
            'class_ids' => [$this->classA->id],
        ]);

        $responseClass->assertStatus(200);
        $responseClass->assertJson([
            'total_students' => 2,
            'total_guardians' => 1,
            'valid_guardians' => 1,
        ]);
    }

    public function test_store_broadcast_queues_job_and_creates_audit_log(): void
    {
        Queue::fake();

        $response = $this->actingAs($this->admin)->post(route('whatsapp.broadcast.store'), [
            'target_type' => 'all',
            'message' => 'Diberitahukan bahwa besok hari Senin diadakan upacara bendera wajib.',
        ]);

        $response->assertRedirect(route('whatsapp.broadcast.create'));
        $response->assertSessionHas('success');

        Queue::assertPushed(SendWhatsAppBroadcastJob::class, function ($job) {
            return $job->targetType === 'all'
                && str_contains($job->messageText, 'upacara bendera');
        });

        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $this->admin->id,
            'action' => 'create_whatsapp_broadcast',
        ]);
    }

    public function test_store_broadcast_returns_json_when_requested(): void
    {
        Queue::fake();

        $response = $this->actingAs($this->admin)->postJson(route('whatsapp.broadcast.store'), [
            'target_type' => 'class',
            'class_ids' => [$this->classA->id],
            'message' => 'Pengumuman khusus kelas X-MIPA-1 untuk membawa modul praktikum.',
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['success', 'task_uuid', 'message']);
        $this->assertTrue($response->json('success'));

        Queue::assertPushed(SendWhatsAppBroadcastJob::class);
    }

    public function test_broadcast_job_deduplicates_guardians_and_dispatches_messages(): void
    {
        // Setup reporter
        $reporter = TaskProgressReporter::start(
            type: 'whatsapp_broadcast',
            label: 'Siaran Pengumuman Uji',
            total: 0,
            userId: $this->admin->id
        );

        // Execute job synchronously
        $job = new SendWhatsAppBroadcastJob(
            targetType: 'all',
            classIds: null,
            messageText: 'Pengumuman simulasi ujian nasional minggu depan.',
            progressUuid: $reporter->uuid(),
            actorId: $this->admin->id
        );

        $waManager = app(WhatsAppManager::class);
        $job->handle($waManager);

        // 3 students in total, but guardians share 2 unique phone numbers
        // NotificationLog should have exactly 2 entries (deduplicated by wa_number)
        $this->assertDatabaseCount('notification_logs', 2);

        $this->assertDatabaseHas('notification_logs', [
            'recipient_phone' => '6281200000001',
            'status' => 'sent',
        ]);

        $this->assertDatabaseHas('notification_logs', [
            'recipient_phone' => '6281200000002',
            'status' => 'sent',
        ]);

        // Verify task completed
        $task = TaskProgress::where('uuid', $reporter->uuid())->first();
        $this->assertNotNull($task);
        $this->assertEquals('completed', $task->status);
        $this->assertEquals(2, $task->total);
        $this->assertEquals(2, $task->processed);
    }

    public function test_broadcast_job_does_not_resend_when_run_twice(): void
    {
        $reporter = TaskProgressReporter::start(
            type: 'whatsapp_broadcast',
            label: 'Siaran Idempotensi',
            total: 0,
            userId: $this->admin->id
        );

        $job = new SendWhatsAppBroadcastJob(
            targetType: 'all',
            classIds: null,
            messageText: 'Pengumuman uji idempotensi broadcast.',
            progressUuid: $reporter->uuid(),
            actorId: $this->admin->id
        );

        $waManager = app(WhatsAppManager::class);

        // Eksekusi pertama mengirim pesan, eksekusi kedua mensimulasikan job yang
        // dijalankan ulang setelah restart — tidak boleh membuat baris / kirim baru.
        $job->handle($waManager);
        $job->handle($waManager);

        $this->assertDatabaseCount('notification_logs', 2);
        $this->assertSame(2, NotificationLog::where('event_type', 'broadcast')->where('status', 'sent')->count());
    }
}
