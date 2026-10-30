<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\SchoolClass;
use App\Models\SchoolHoliday;
use App\Models\Student;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class AttendanceReportsAndExportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private SchoolClass $class;

    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        $this->class = SchoolClass::create(['name' => 'X-RPL 1', 'academic_year' => '2026/2027']);
        $this->student = Student::create([
            'nis' => '10101',
            'name' => 'Budi Utomo',
            'class_id' => $this->class->id,
            'active' => true,
        ]);
    }

    public function test_admin_can_view_attendances_index(): void
    {
        $response = $this->actingAs($this->admin)->get(route('attendances.index'));
        $response->assertStatus(200);
        $response->assertSee('Riwayat &amp; Rekap Presensi Siswa', false);
        $response->assertSee('Telat (Menit)');
        $response->assertSee('PLG Cepat (Menit)');
        $response->assertDontSee('>Status Masuk</th>', false);
        $response->assertDontSee('>Status Pulang</th>', false);
    }

    public function test_attendances_index_renders_client_side_datatables_without_server_pagination(): void
    {
        $response = $this->actingAs($this->admin)->get(route('attendances.index'));

        $response->assertOk();

        // Data dikirim penuh ke DataTables sisi klien, bukan paginasi server.
        $response->assertViewHas('attendances', fn ($attendances) => $attendances instanceof Collection);

        $html = $response->getContent();
        $this->assertStringContainsString('data-datatable', $html);
        $this->assertStringNotContainsString('Halaman 1 dari', $html);
    }

    public function test_admin_can_export_attendances_as_xlsx(): void
    {
        $today = Carbon::today('Asia/Jakarta')->toDateString();

        Attendance::create([
            'student_id' => $this->student->id,
            'school_date' => $today,
            'check_in_at' => Carbon::parse("{$today} 06:55:00", 'Asia/Jakarta'),
            'check_out_at' => Carbon::parse("{$today} 14:05:00", 'Asia/Jakarta'),
            'status' => 'hadir',
            'policy_version' => 'v1.0',
        ]);

        $response = $this->actingAs($this->admin)->get(route('attendances.export', [
            'date' => $today,
            'format' => 'xlsx',
        ]));

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_admin_can_export_attendances_as_csv(): void
    {
        $today = Carbon::today('Asia/Jakarta')->toDateString();

        Attendance::create([
            'student_id' => $this->student->id,
            'school_date' => $today,
            'status' => 'hadir',
            'policy_version' => 'v1.0',
        ]);

        $response = $this->actingAs($this->admin)->get(route('attendances.export', [
            'date' => $today,
            'format' => 'csv',
        ]));

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    }

    public function test_attendance_export_honors_checkout_status_filter_and_includes_status_columns(): void
    {
        $otherStudent = Student::create([
            'nis' => '10102',
            'name' => 'Siti Aminah',
            'class_id' => $this->class->id,
            'active' => true,
        ]);

        Attendance::create([
            'student_id' => $this->student->id,
            'school_date' => '2026-10-14',
            'check_in_at' => '2026-10-14 07:16:00',
            'status' => 'terlambat',
            'check_in_status' => 'terlambat',
            'check_out_status' => 'belum_absen',
            'late_minutes' => 16,
            'policy_version' => 'v1.0',
        ]);

        Attendance::create([
            'student_id' => $otherStudent->id,
            'school_date' => '2026-10-14',
            'check_in_at' => '2026-10-14 06:55:00',
            'check_out_at' => '2026-10-14 16:05:00',
            'status' => 'hadir',
            'check_in_status' => 'tepat_waktu',
            'check_out_status' => 'tepat_waktu',
            'policy_version' => 'v1.0',
        ]);

        $response = $this->actingAs($this->admin)->get(route('attendances.export', [
            'date' => '2026-10-14',
            'out_status' => 'belum_absen',
            'format' => 'csv',
        ]));

        $response->assertOk();
        $content = $response->streamedContent();

        $this->assertStringContainsString('Budi Utomo', $content);
        $this->assertStringNotContainsString('Siti Aminah', $content);
        $this->assertStringContainsString('Status Masuk', $content);
        $this->assertStringContainsString('Status Pulang', $content);
        $this->assertStringContainsString('Terlambat', $content);
        $this->assertStringContainsString('Belum Absen', $content);
    }

    public function test_admin_can_view_student_monthly_pdf_report(): void
    {
        $month = '2026-10';

        SchoolHoliday::create([
            'name' => 'Hari Sumpah Pemuda',
            'start_date' => '2026-10-28',
            'end_date' => '2026-10-28',
            'is_active' => true,
        ]);

        Attendance::create([
            'student_id' => $this->student->id,
            'school_date' => '2026-10-05',
            'check_in_at' => '2026-10-05 06:50:00',
            'status' => 'hadir',
            'policy_version' => 'v1.0',
        ]);

        $response = $this->actingAs($this->admin)->get(route('attendances.report.student', [
            'student_id' => $this->student->id,
            'month' => $month,
        ]));

        $response->assertStatus(200);
        $response->assertSee('LAPORAN PRESENSI SISWA');
        $response->assertSee('Budi Utomo');
        $response->assertSee('X-RPL 1');
        $response->assertSee('Libur');
        $response->assertSee('TELAT (MENIT)');
        $response->assertSee('PLG CEPAT (MENIT)');
        $response->assertDontSee('>STATUS</th>', false);
        $response->assertSee('Cetak / Simpan PDF');
    }

    public function test_admin_can_view_class_monthly_pdf_report(): void
    {
        $month = '2026-10';

        Attendance::create([
            'student_id' => $this->student->id,
            'school_date' => '2026-10-05',
            'status' => 'hadir',
            'policy_version' => 'v1.0',
        ]);

        $response = $this->actingAs($this->admin)->get(route('attendances.report.class', [
            'class_id' => $this->class->id,
            'month' => $month,
        ]));

        $response->assertStatus(200);
        $response->assertSee('REKAPITULASI PRESENSI KELAS');
        $response->assertSee('X-RPL 1');
        $response->assertSee('Budi Utomo');
        $response->assertSee('Cetak / Simpan PDF');
    }

    public function test_admin_can_export_student_monthly_report_as_xlsx(): void
    {
        $response = $this->actingAs($this->admin)->get(route('attendances.report.student', [
            'student_id' => $this->student->id,
            'month' => '2026-10',
            'format' => 'xlsx',
        ]));

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $archive = new \ZipArchive;
        $this->assertTrue($archive->open($response->getFile()->getPathname()) === true);
        $sheetXml = $archive->getFromName('xl/worksheets/sheet1.xml');
        $archive->close();

        $this->assertIsString($sheetXml);
        $this->assertStringContainsString('Telat (Menit)', $sheetXml);
        $this->assertStringContainsString('PLG Cepat (Menit)', $sheetXml);
        $this->assertStringContainsString('Keterangan', $sheetXml);
        $this->assertStringNotContainsString('Status Kehadiran', $sheetXml);
        $this->assertStringContainsString('customWidth="1"', $sheetXml);
    }

    public function test_admin_can_export_student_monthly_report_from_report_hub_without_status_column(): void
    {
        $response = $this->actingAs($this->admin)->get(route('attendances.reports.export', [
            'type' => 'student',
            'student_id' => $this->student->id,
            'month' => '2026-10',
        ]));

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $archive = new \ZipArchive;
        $this->assertTrue($archive->open($response->getFile()->getPathname()) === true);
        $sheetXml = $archive->getFromName('xl/worksheets/sheet1.xml');
        $archive->close();

        $this->assertIsString($sheetXml);
        $this->assertStringContainsString('Telat (Menit)', $sheetXml);
        $this->assertStringContainsString('PLG Cepat (Menit)', $sheetXml);
        $this->assertStringContainsString('Keterangan', $sheetXml);
        $this->assertStringNotContainsString('Status Kehadiran', $sheetXml);
    }

    public function test_admin_can_export_class_monthly_report_as_xlsx(): void
    {
        $response = $this->actingAs($this->admin)->get(route('attendances.report.class', [
            'class_id' => $this->class->id,
            'month' => '2026-10',
            'format' => 'xlsx',
        ]));

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_admin_can_view_monthly_pdf_and_xlsx_report(): void
    {
        $pdfResponse = $this->actingAs($this->admin)->get(route('attendances.report.monthly', [
            'month' => '2026-10',
            'class_id' => $this->class->id,
        ]));

        $pdfResponse->assertStatus(200);
        $pdfResponse->assertSee('REKAPITULASI PRESENSI BULANAN');

        $xlsxResponse = $this->actingAs($this->admin)->get(route('attendances.report.monthly', [
            'month' => '2026-10',
            'class_id' => $this->class->id,
            'format' => 'xlsx',
        ]));

        $xlsxResponse->assertStatus(200);
        $xlsxResponse->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }
}
