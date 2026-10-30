<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\AuditLog;
use App\Models\DeviceMachineUser;
use App\Models\DeviceSyncLog;
use App\Models\DeviceUser;
use App\Models\FingerprintDevice;
use App\Models\SchoolSchedule;
use App\Models\Student;
use App\Models\User;
use App\Services\FingerprintConnectorService;
use App\Services\SoapFingerprintService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceManagementPagesTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected FingerprintDevice $device;

    protected FingerprintDevice $otherDevice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();

        $this->device = FingerprintDevice::create([
            'name' => 'Solution X601 - Gerbang Depan',
            'ip' => '192.168.1.201',
            'port' => 4370,
            'comm_key' => '0',
            'timezone' => 'Asia/Jakarta',
            'status' => 'active',
            'last_seen_at' => now(),
            'secret_token' => 'x601_sec_supersecretvalue123456',
            'active' => true,
        ]);

        $this->otherDevice = FingerprintDevice::create([
            'name' => 'Solution X601 - Gerbang Belakang',
            'ip' => '192.168.1.202',
            'port' => 4370,
            'comm_key' => '0',
            'timezone' => 'Asia/Jakarta',
            'status' => 'offline',
            'secret_token' => 'x601_sec_lainsecrettoken1234567',
            'active' => true,
        ]);

        SchoolSchedule::create([
            'day_of_week' => 1,
            'day_name' => 'Senin',
            'check_in_start' => '06:00:00',
            'start_at' => '07:00:00',
            'late_after' => '07:15:00',
            'check_out_start' => '14:00:00',
            'end_at' => '16:00:00',
            'day_pattern' => 'regular',
            'is_active' => true,
        ]);
    }

    public function test_device_index_renders_table_with_only_two_row_actions(): void
    {
        $response = $this->actingAs($this->admin)->get(route('devices.index'));

        $response->assertStatus(200);

        // Tabel data dengan kolom yang diminta
        $headers = ['Mesin & Lokasi', 'IP Address', 'Port', 'Status', 'Siswa Terpetakan', 'Total Log', 'Sinkron Terakhir', 'Aksi'];
        $response->assertSee('<table', false);
        foreach ($headers as $header) {
            $response->assertSee($header, false);
        }

        // Data mesin: nama, lokasi dari nama, dan ip:port
        $response->assertSee('Solution X601 - Gerbang Depan', false);
        $response->assertSee('Gerbang Depan', false);
        $response->assertSee('192.168.1.201', false);

        // Hanya dua aksi per baris
        $response->assertSee('Tes Koneksi', false);
        $response->assertSee('Kelola Mesin', false);

        // Tombol teknis tidak lagi ada di daftar
        $response->assertDontSee('Samakan Jam', false);
        $response->assertDontSee('Restart Mesin', false);
        $response->assertDontSee('Hapus Log Absensi di Mesin', false);
        $response->assertDontSee('Lihat Secret Token', false);

        // Tambah Mesin tetap tersedia
        $response->assertSee('Tambah Mesin', false);
    }

    public function test_device_index_supports_search_and_pagination(): void
    {
        FingerprintDevice::create([
            'name' => 'Solution X601 - Ruang Guru',
            'ip' => '192.168.1.250',
            'port' => 4370,
            'comm_key' => '0',
            'timezone' => 'Asia/Jakarta',
            'status' => 'offline',
            'secret_token' => 'x601_sec_guru_token_1234567',
            'active' => true,
        ]);

        $response = $this->actingAs($this->admin)->get(route('devices.index', ['search' => 'Ruang Guru']));

        $response->assertStatus(200);
        $response->assertSee('Ruang Guru', false);
        $response->assertDontSee('Gerbang Belakang', false);

        // Pencarian berdasarkan IP juga worked
        $byIp = $this->actingAs($this->admin)->get(route('devices.index', ['search' => '192.168.1.250']));
        $byIp->assertStatus(200);
        $byIp->assertSee('Ruang Guru', false);
    }

    public function test_device_index_does_not_run_connection_test_on_render(): void
    {
        $connector = $this->createMock(FingerprintConnectorService::class);
        $connector->expects($this->never())->method('testConnection');
        $connector->expects($this->never())->method('syncStudents');
        $this->app->instance(FingerprintConnectorService::class, $connector);

        $soap = $this->createMock(SoapFingerprintService::class);
        $soap->expects($this->never())->method('testConnection');
        $this->app->instance(SoapFingerprintService::class, $soap);

        $this->actingAs($this->admin)->get(route('devices.index'))->assertStatus(200);
    }

    public function test_device_detail_renders_single_page_without_tabs(): void
    {
        $response = $this->actingAs($this->admin)->get(route('devices.show', $this->device));

        $response->assertStatus(200);

        // Satu halaman tanpa tab.
        $response->assertDontSee('role="tablist"', false);

        // Semua seksi tampil bersamaan. Kartu Ringkasan Mesin dihapus karena
        // informasinya sudah menyatu pada Konfigurasi Mesin di bagian atas.
        $response->assertDontSee('Ringkasan Mesin', false);
        $response->assertSee('Konfigurasi Mesin', false);
        $response->assertSee('Tes Koneksi', false);
        $response->assertSee('Pemeliharaan Mesin', false);
        $response->assertSee('Zona Berbahaya', false);
    }

    public function test_device_detail_disables_clone_action_without_another_device(): void
    {
        $this->otherDevice->delete();

        $response = $this->actingAs($this->admin)->get(route('devices.show', $this->device));
        $html = $response->getContent();

        $response->assertOk();
        $this->assertStringNotContainsString('$otherDevices.length', $html);
        $this->assertStringContainsString('hasOtherDevices: false', $html);
        $this->assertStringContainsString('x-bind:disabled="running || ! hasOtherDevices"', $html);
        $this->assertStringContainsString('Perlu minimal 2 mesin terdaftar', $html);
    }

    public function test_timezone_is_chosen_from_dropdown_not_free_text(): void
    {
        $detailHtml = $this->actingAs($this->admin)
            ->get(route('devices.show', $this->device))
            ->getContent();

        $this->assertMatchesRegularExpression('/<select[^>]+name="timezone"/', $detailHtml);
        $this->assertStringNotContainsString('name="timezone" value=', $detailHtml);
        $this->assertStringContainsString('WIB - Asia/Jakarta', $detailHtml);
        $this->assertStringContainsString('WITA - Asia/Makassar', $detailHtml);
        $this->assertStringContainsString('WIT - Asia/Jayapura', $detailHtml);

        // Modal tambah mesin juga memakai dropdown, bukan input bebas.
        $indexHtml = $this->actingAs($this->admin)
            ->get(route('devices.index'))
            ->getContent();

        $this->assertMatchesRegularExpression('/<select[^>]+name="timezone"/', $indexHtml);
    }

    public function test_connector_section_is_cross_os_and_rotate_lives_in_danger_zone(): void
    {
        $html = $this->actingAs($this->admin)
            ->get(route('devices.show', $this->device))
            ->getContent();

        // Connector kini lintas OS, bukan lagi khusus Windows.
        $this->assertStringContainsString('Windows', $html);
        $this->assertStringContainsString('Linux', $html);

        // Unduh konfigurasi tetap menjadi satu-satunya jalur mendapatkan token.
        $this->assertStringContainsString('Unduh connector_config.ini', $html);
        $this->assertStringNotContainsString('Tampilkan Token', $html);

        // Aksi berisiko "Buat Ulang Token" tidak lagi satu kartu dengan aksi rutin unduh,
        // melainkan ditempatkan di Zona Berbahaya.
        $dangerPos = strpos($html, 'Zona Berbahaya');
        $rotatePos = strpos($html, 'Buat Ulang Token');
        $this->assertNotFalse($dangerPos);
        $this->assertNotFalse($rotatePos);
        $this->assertGreaterThan($dangerPos, $rotatePos);
    }

    public function test_device_detail_never_exposes_secret_token(): void
    {
        $response = $this->actingAs($this->admin)->get(route('devices.show', $this->device));

        $response->assertStatus(200);

        // Token asli tidak boleh ada di HTML, dan UI reveal sudah dihapus.
        $response->assertDontSee($this->device->secret_token, false);
        $response->assertDontSee('Tampilkan Token', false);
        $response->assertSee('Unduh connector_config.ini', false);
    }

    public function test_connector_config_download_contains_token_and_is_audited(): void
    {
        config()->set('services.connector.local_api_token', 'ct_builder_test');

        $response = $this->actingAs($this->admin)
            ->get(route('devices.connector-config', $this->device));

        $response->assertOk();
        $response->assertDownload('connector_config.ini');

        $body = $response->streamedContent();
        $this->assertStringContainsString('[device:', $body);
        $this->assertStringContainsString('token='.$this->device->secret_token, $body);
        $this->assertStringContainsString('ip='.$this->device->ip, $body);
        $this->assertStringContainsString('port='.$this->device->port, $body);
        $this->assertStringContainsString('local_api_port=4371', $body);
        $this->assertStringContainsString('local_api_token=ct_builder_test', $body);

        // Semua mesin aktif ikut dalam satu file agar satu connector melayani seluruh jaringan.
        $this->assertStringContainsString('token='.$this->otherDevice->secret_token, $body);
        $this->assertStringContainsString('ip='.$this->otherDevice->ip, $body);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'download_connector_config',
            'entity_id' => $this->device->id,
        ]);
    }

    public function test_operator_cannot_download_connector_config(): void
    {
        $operator = User::factory()->operator()->create();

        $this->actingAs($operator)
            ->get(route('devices.connector-config', $this->device))
            ->assertStatus(403);

        $this->assertDatabaseMissing('audit_logs', ['action' => 'download_connector_config']);
    }

    public function test_device_detail_groups_actions_per_tab(): void
    {
        $html = $this->actingAs($this->admin)
            ->get(route('devices.show', $this->device))
            ->getContent();

        // Tindakan pemeliharaan (kini bagian dari halaman tunggal), memakai label
        // berbahasa awam tanpa istilah teknis yang ambigu.
        $this->assertStringContainsString('Kirim Nama Siswa', $html);
        $this->assertStringContainsString('Upload Sidik Jari', $html);
        $this->assertStringContainsString('Restart Mesin', $html);
        $this->assertStringContainsString('Hapus Log Absensi di Mesin', $html);

        // Sinkron jam kini berada di kartu Sinkronisasi, bukan di kartu pemeliharaan.
        $this->assertStringContainsString('Sinkronisasi Waktu', $html);
        $this->assertStringContainsString('Sinkronisasi Absensi', $html);

        // Salin data punya target mesin lain, bukan mesin ini
        $this->assertStringContainsString('Salin Data ke Mesin Lain', $html);
        $this->assertStringContainsString($this->otherDevice->name, $html);
    }

    public function test_risky_actions_use_alpine_confirmation_dialog_instead_of_native_confirm(): void
    {
        $html = $this->actingAs($this->admin)
            ->get(route('devices.show', $this->device))
            ->getContent();

        // Konfirmasi memakai modal Alpine + Tailwind, bukan confirm() bawaan browser.
        $this->assertStringNotContainsString('onsubmit="return confirm(', $html);
        $this->assertStringNotContainsString('alert(', $html);

        // Form POST biasa memakai atribut data-confirm (ditangani delegasi app.js).
        $this->assertStringContainsString('data-confirm=', $html);
        $this->assertStringContainsString('data-confirm-variant="danger"', $html);

        // Form proses panjang memakai askConfirm() yang mengembalikan Promise.
        $this->assertStringContainsString('askConfirm(', $html);

        // Modal konfirmasi global dirender sekali oleh layout.
        $this->assertStringContainsString('$store.confirmDialog', $html);
        $this->assertStringContainsString('id="confirm-dialog-title"', $html);
    }

    public function test_mappings_page_lists_pin_mappings_and_filters_by_device(): void
    {
        $student = Student::create([
            'nis' => '1001',
            'name' => 'Budi Santoso',
            'active' => true,
        ]);

        DeviceUser::create([
            'device_id' => $this->device->id,
            'student_id' => $student->id,
            'machine_user_id' => 'PIN-7788',
        ]);

        $response = $this->actingAs($this->admin)->get(route('devices.mappings', ['tab' => 'mapped', 'device_id' => $this->device->id]));
        $response->assertStatus(200);
        $response->assertSee('Budi Santoso', false);
        $response->assertSee('PIN-7788', false);

        // Filter mesin lain tidak menampilkan mapping tadi.
        // (Nama siswa tetap muncul di dropdown form, jadi yang dicek adalah PIN
        //  yang hanya ada pada tabel pemetaan.)
        $filtered = $this->actingAs($this->admin)
            ->get(route('devices.mappings', ['device_id' => $this->otherDevice->id, 'tab' => 'mapped']));
        $filtered->assertStatus(200);
        $filtered->assertDontSee('PIN-7788', false);
    }

    public function test_store_mapping_rejects_machine_id_already_used_by_another_student(): void
    {
        $first = Student::create([
            'nis' => '2001',
            'name' => 'Siswa Pertama',
            'active' => true,
        ]);
        $second = Student::create([
            'nis' => '2002',
            'name' => 'Siswa Kedua',
            'active' => true,
        ]);

        DeviceUser::create([
            'device_id' => $this->device->id,
            'student_id' => $first->id,
            'machine_user_id' => 'PIN-DUP',
        ]);

        $response = $this->actingAs($this->admin)->post(route('devices.mapping.store'), [
            'device_id' => $this->device->id,
            'student_id' => $second->id,
            'machine_user_id' => 'PIN-DUP',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error');

        $this->assertDatabaseMissing('device_users', [
            'device_id' => $this->device->id,
            'student_id' => $second->id,
        ]);
        $this->assertDatabaseHas('device_users', [
            'device_id' => $this->device->id,
            'student_id' => $first->id,
            'machine_user_id' => 'PIN-DUP',
        ]);
    }

    public function test_store_mapping_reprocesses_stale_unmapped_logs(): void
    {
        $student = Student::create([
            'nis' => '2003',
            'name' => 'Siswa Ketiga',
            'active' => true,
        ]);

        $occurredAt = now()->subDays(2)->setTime(7, 5);
        $log = AttendanceLog::create([
            'device_id' => $this->device->id,
            'machine_user_id' => 'PIN-LAMA',
            'occurred_at' => $occurredAt,
            'received_at' => $occurredAt->copy()->addMinute(),
            'source_key' => 'x601_PIN-LAMA_2000',
            'status' => 'unmapped',
        ]);

        $response = $this->actingAs($this->admin)->post(route('devices.mapping.store'), [
            'device_id' => $this->device->id,
            'student_id' => $student->id,
            'machine_user_id' => 'PIN-LAMA',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertSame('processed', $log->fresh()->status);
        $this->assertDatabaseHas('attendances', [
            'student_id' => $student->id,
            'school_date' => $occurredAt->toDateString().' 00:00:00',
            'status' => 'hadir',
        ]);

        $this->actingAs($this->admin)
            ->get(route('logs.unmapped'))
            ->assertDontSee('x601_PIN-LAMA_2000', false);
    }

    public function test_reassign_mapping_reprocesses_unmapped_logs_for_new_student(): void
    {
        $oldStudent = Student::create([
            'nis' => '2004',
            'name' => 'Siswa Lama',
            'active' => true,
        ]);
        $newStudent = Student::create([
            'nis' => '2005',
            'name' => 'Siswa Baru',
            'active' => true,
        ]);

        $mapping = DeviceUser::create([
            'device_id' => $this->device->id,
            'machine_user_id' => 'PIN-PINDAH',
            'student_id' => $oldStudent->id,
        ]);

        $occurredAt = now()->subDays(2)->setTime(7, 6);
        $log = AttendanceLog::create([
            'device_id' => $this->device->id,
            'machine_user_id' => 'PIN-PINDAH',
            'occurred_at' => $occurredAt,
            'received_at' => $occurredAt->copy()->addMinute(),
            'source_key' => 'x601_PIN-PINDAH_2001',
            'status' => 'unmapped',
        ]);

        $response = $this->actingAs($this->admin)->put(route('devices.mapping.update', $mapping), [
            'student_id' => $newStudent->id,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertSame('processed', $log->fresh()->status);
        $this->assertDatabaseHas('attendances', [
            'student_id' => $newStudent->id,
            'school_date' => $occurredAt->toDateString().' 00:00:00',
            'status' => 'hadir',
        ]);
    }

    public function test_mappings_page_renders_datatables_without_server_pagination(): void
    {
        DeviceMachineUser::create([
            'device_id' => $this->device->id,
            'fingerprint_user_id' => '1001',
            'machine_name' => 'Budi Santoso',
            'synced_at' => now(),
        ]);

        $html = $this->actingAs($this->admin)
            ->get(route('devices.mappings', ['tab' => 'unmapped', 'device_id' => $this->device->id]))
            ->assertStatus(200)
            ->getContent();

        // Tabel memakai DataTables sisi klien (data dikirim penuh, bukan paginate).
        $this->assertStringContainsString('data-datatable', $html);
        $this->assertStringNotContainsString('Halaman 1 dari', $html);

        // Pemilih siswa searchable tersedia untuk modal pemetaan.
        $this->assertStringContainsString(route('devices.mapping.store'), $html);
        $this->assertStringContainsString('name="student_id"', $html);
        $this->assertStringContainsString('x-data="studentPicker', $html);
    }

    public function test_student_search_options_returns_limited_active_matches(): void
    {
        Student::create(['nis' => '3001', 'name' => 'Budi Santoso', 'active' => true]);
        Student::create(['nis' => '3002', 'name' => 'Budi Hartono', 'active' => true]);
        Student::create(['nis' => '3003', 'name' => 'Bukan Budi', 'active' => false]);

        $response = $this->actingAs($this->admin)->getJson(route('students.search-options', ['q' => 'Budi']));

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('data.0.name', 'Budi Hartono');
        $response->assertJsonPath('data.0.nis', '3002');
        $response->assertJsonMissing(['name' => 'Bukan Budi']);

        $this->actingAs($this->admin)
            ->getJson(route('students.search-options', ['q' => 'B']))
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_mappings_page_renders_datatables_even_when_empty_without_colspan_row(): void
    {
        // Tanpa baris data, tabel tetap dirender agar antarmuka DataTables (dropdown
        // jumlah baris, pencarian, info) tampil. Baris <td colspan> tidak dipakai
        // karena memicu "Requested unknown parameter"; pesan kosong dikirim lewat
        // atribut data-empty-message.
        $html = $this->actingAs($this->admin)
            ->get(route('devices.mappings', ['tab' => 'unmapped']))
            ->assertStatus(200)
            ->getContent();

        $this->assertStringContainsString('data-datatable', $html);
        $this->assertStringNotContainsString('colspan', $html);
        $this->assertStringContainsString('Tidak ada machine users yang ditemukan', $html);
    }

    public function test_device_index_test_button_posts_to_test_connection_route(): void
    {
        $html = $this->actingAs($this->admin)
            ->get(route('devices.index'))
            ->assertStatus(200)
            ->getContent();

        // Tombol "Tes" harus form POST (rute test-connection hanya menerima POST),
        // bukan tautan GET, dan mengembalikan pengguna ke daftar mesin.
        $this->assertStringContainsString(
            '<form action="'.route('devices.test-connection', $this->device).'" method="POST"',
            $html
        );
        $this->assertStringContainsString('name="return_to"', $html);
    }

    public function test_sync_history_page_lists_logs_and_filters(): void
    {
        DeviceSyncLog::create([
            'device_id' => $this->device->id,
            'sync_type' => 'sync_students',
            'status' => 'success',
            'records_count' => 3,
            'message' => 'Sinkronisasi siswa berhasil.',
            'operator_id' => $this->admin->id,
        ]);

        DeviceSyncLog::create([
            'device_id' => $this->device->id,
            'sync_type' => 'sync_time',
            'status' => 'success',
            'records_count' => 0,
            'message' => 'Jam mesin berhasil disamakan.',
            'operator_id' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin)->get(route('devices.sync-history'));
        $response->assertStatus(200);
        $response->assertSee('Sinkronisasi siswa berhasil.', false);
        $response->assertSee('Sinkron Siswa', false);
        $response->assertSee('Jam mesin berhasil disamakan.', false);
        $response->assertSee('Sinkron Waktu', false);

        // Filter jenis lain tidak memunculkan log di atas
        $filtered = $this->actingAs($this->admin)
            ->get(route('devices.sync-history', ['sync_type' => 'test_connection']));
        $filtered->assertStatus(200);
        $filtered->assertDontSee('Sinkronisasi siswa berhasil.', false);
    }

    public function test_risky_device_actions_are_written_to_audit_log(): void
    {
        $this->actingAs($this->admin)
            ->post(route('devices.rotate-token', $this->device))
            ->assertRedirect(route('devices.show', $this->device));

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'rotate_device_token',
            'entity_id' => $this->device->id,
        ]);
    }

    public function test_operator_cannot_access_device_management_pages(): void
    {
        $operator = User::factory()->operator()->create();

        $this->actingAs($operator)->get(route('devices.index'))->assertStatus(403);
        $this->actingAs($operator)->get(route('devices.show', $this->device))->assertStatus(403);
        $this->actingAs($operator)->get(route('devices.mappings'))->assertStatus(403);
        $this->actingAs($operator)->get(route('devices.sync-history'))->assertStatus(403);
    }

    public function test_audit_log_records_bulk_device_operation_dispatch(): void
    {
        // Operasi massal hanya boleh dijalankan bila mesin sudah punya pemetaan.
        DeviceUser::create([
            'device_id' => $this->device->id,
            'student_id' => Student::create(['nis' => '2001', 'name' => 'Sari Wijaya'])->id,
            'machine_user_id' => '201',
        ]);

        $this->actingAs($this->admin)
            ->post(route('devices.bulk-push-names', $this->device))
            ->assertRedirect(route('devices.show', $this->device));

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'dispatch_push_names',
            'entity_id' => $this->device->id,
        ]);
    }

    public function test_deleting_device_returns_to_device_index(): void
    {
        $this->actingAs($this->admin)
            ->delete(route('devices.destroy', $this->device))
            ->assertRedirect(route('devices.index'));

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'delete_device',
            'entity_id' => $this->device->id,
        ]);
    }

    public function test_audit_action_is_recorded_with_actor_and_ip(): void
    {
        $this->actingAs($this->admin)->post(route('devices.rotate-token', $this->device));

        $log = AuditLog::where('action', 'rotate_device_token')->firstOrFail();

        $this->assertSame($this->admin->id, $log->actor_id);
        $this->assertSame('FingerprintDevice', $log->entity_type);
        $this->assertNotNull($log->created_at);
    }
}
