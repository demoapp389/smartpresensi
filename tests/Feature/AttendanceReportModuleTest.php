<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\SchoolClass;
use App\Models\SchoolHoliday;
use App\Models\SchoolSchedule;
use App\Models\Student;
use App\Models\User;
use App\Services\AttendanceReportService;
use App\Services\SimpleXlsxExporter;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class AttendanceReportModuleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $operator;

    private User $waliKelas;

    private SchoolClass $class1;

    private SchoolClass $class2;

    private Student $student1;

    private Student $student2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create(['name' => 'Admin Test']);
        $this->operator = User::factory()->operator()->create(['name' => 'Operator Test']);

        $this->class1 = SchoolClass::create(['name' => 'X-RPL 1', 'academic_year' => '2026/2027']);
        $this->class2 = SchoolClass::create(['name' => 'X-TKJ 1', 'academic_year' => '2026/2027']);

        $this->waliKelas = User::factory()->waliKelas()->create([
            'name' => 'Wali Kelas X-RPL 1',
            'class_id' => $this->class1->id,
        ]);

        $this->student1 = Student::create([
            'nis' => '10101',
            'name' => 'Ahmad Dahlan',
            'class_id' => $this->class1->id,
            'active' => true,
        ]);

        $this->student2 = Student::create([
            'nis' => '10102',
            'name' => '=2+5*cmd', // Formula injection test payload
            'class_id' => $this->class2->id,
            'active' => true,
        ]);
    }

    public function test_admin_can_access_report_hub(): void
    {
        $response = $this->actingAs($this->admin)->get(route('attendances.reports.index'));

        $response->assertStatus(200);
        $response->assertSee('Laporan Presensi Siswa');
        $response->assertSee('Laporan Harian');
        $response->assertSee('Laporan Bulanan');
        $response->assertSee('Laporan Per Kelas');
        $response->assertSee('Laporan Per Siswa');
        $response->assertSee('Cetak / PDF (A4)');
        $response->assertSee('Ekspor Excel (.xlsx)');
    }

    public function test_report_hub_renders_client_side_datatables_without_server_pagination(): void
    {
        $today = Carbon::today('Asia/Jakarta')->toDateString();

        Attendance::create([
            'student_id' => $this->student1->id,
            'school_date' => $today,
            'check_in_at' => Carbon::parse("{$today} 06:45:00", 'Asia/Jakarta'),
            'status' => 'hadir',
            'policy_version' => 'v1.0',
        ]);

        $response = $this->actingAs($this->admin)->get(route('attendances.reports.index'));

        $response->assertOk();

        // Data dikirim penuh ke DataTables sisi klien, bukan paginasi server.
        $response->assertViewHas(
            'reportData',
            fn ($reportData) => $reportData['rows'] instanceof Collection
        );

        $html = $response->getContent();
        $this->assertStringContainsString('data-datatable', $html);
        $this->assertStringContainsString('data-toolbar-filter="#report-filter"', $html);
        $this->assertStringNotContainsString('Halaman 1 dari', $html);

        // Tanpa filter: tombol Reset tidak ditampilkan.
        $response->assertViewHas('hasActiveFilter', false);
    }

    public function test_report_hub_shows_reset_button_only_when_filter_is_applied(): void
    {
        $withFilter = $this->actingAs($this->admin)->get(route('attendances.reports.index', [
            'type' => 'daily',
            'class_id' => $this->class1->id,
        ]));

        $withFilter->assertOk();
        $withFilter->assertViewHas('hasActiveFilter', true);

        // Kembali dari halaman cetak membawa ?type & tanggal bawaan, jadi Reset
        // tetap tersembunyi karena tidak ada filter yang benar-benar diterapkan.
        $fromPrint = $this->actingAs($this->admin)->get(route('attendances.reports.index', [
            'type' => 'daily',
            'date' => Carbon::today('Asia/Jakarta')->toDateString(),
        ]));

        $fromPrint->assertOk();
        $fromPrint->assertViewHas('hasActiveFilter', false);
    }

    public function test_daily_report_preview_and_summary_calculation(): void
    {
        $today = Carbon::today('Asia/Jakarta')->toDateString();

        Attendance::create([
            'student_id' => $this->student1->id,
            'school_date' => $today,
            'check_in_at' => Carbon::parse("{$today} 06:45:00", 'Asia/Jakarta'),
            'check_out_at' => Carbon::parse("{$today} 14:00:00", 'Asia/Jakarta'),
            'status' => 'hadir',
            'policy_version' => 'v1.0',
        ]);

        $response = $this->actingAs($this->admin)->get(route('attendances.reports.index', [
            'type' => 'daily',
            'date' => $today,
            'class_id' => $this->class1->id,
        ]));

        $response->assertStatus(200);
        $response->assertSee('Ahmad Dahlan');
        $response->assertSee('X-RPL 1');
        $response->assertSee('Tepat Waktu');
        $response->assertSee('LAPORAN PRESENSI HARIAN SISWA');
    }

    public function test_monthly_report_preview_with_effective_days(): void
    {
        $month = '2026-10';

        SchoolHoliday::create([
            'name' => 'Hari Libur Khusus',
            'start_date' => '2026-10-14',
            'end_date' => '2026-10-14',
            'is_active' => true,
        ]);

        Attendance::create([
            'student_id' => $this->student1->id,
            'school_date' => '2026-10-05',
            'status' => 'hadir',
            'policy_version' => 'v1.0',
        ]);

        $response = $this->actingAs($this->admin)->get(route('attendances.reports.index', [
            'type' => 'monthly',
            'month' => $month,
        ]));

        $response->assertStatus(200);
        $response->assertSee('REKAPITULASI PRESENSI BULANAN');
        $response->assertSee('Ahmad Dahlan');
        $response->assertDontSee('<select name="class_id"', false);
    }

    public function test_class_monthly_report_preview(): void
    {
        $month = '2026-10';

        Attendance::create([
            'student_id' => $this->student1->id,
            'school_date' => '2026-10-06',
            'status' => 'hadir',
            'policy_version' => 'v1.0',
        ]);

        $response = $this->actingAs($this->admin)->get(route('attendances.reports.index', [
            'type' => 'class',
            'class_id' => $this->class1->id,
            'month' => $month,
        ]));

        $response->assertStatus(200);
        $response->assertSee('LAPORAN REKAPITULASI PRESENSI KELAS');
        $response->assertSee('X-RPL 1');
        $response->assertSee('Ahmad Dahlan');
    }

    public function test_student_monthly_report_preview(): void
    {
        $month = '2026-10';

        Attendance::create([
            'student_id' => $this->student1->id,
            'school_date' => '2026-10-07',
            'check_in_at' => '2026-10-07 06:40:00',
            'status' => 'hadir',
            'policy_version' => 'v1.0',
        ]);

        $response = $this->actingAs($this->admin)->get(route('attendances.reports.index', [
            'type' => 'student',
            'student_id' => $this->student1->id,
            'month' => $month,
        ]));

        $response->assertStatus(200);
        $response->assertSee('LAPORAN PRESENSI BULANAN');
        $response->assertSee('Ahmad Dahlan');
        $response->assertViewHas('studentsList');
    }

    public function test_student_monthly_report_uses_concise_attendance_descriptions(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-22 12:00:00', 'Asia/Jakarta'));

        try {
            foreach ([1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat'] as $day => $name) {
                SchoolSchedule::create([
                    'day_pattern' => 'regular',
                    'day_name' => $name,
                    'day_of_week' => $day,
                    'check_in_start' => '06:00',
                    'start_at' => '07:00',
                    'late_after' => '07:15',
                    'check_out_start' => '13:00',
                    'end_at' => '15:00',
                    'alpa_cutoff' => '16:00',
                    'is_active' => true,
                ]);
            }

            SchoolHoliday::create([
                'name' => 'Hari Libur Uji',
                'start_date' => '2026-10-15',
                'end_date' => '2026-10-15',
                'is_active' => true,
            ]);

            $records = [
                '2026-10-05' => [
                    'check_in_at' => '2026-10-05 06:55:00',
                    'check_out_at' => '2026-10-05 15:00:00',
                    'status' => 'hadir',
                    'check_in_status' => 'tepat_waktu',
                    'check_out_status' => 'tepat_waktu',
                ],
                '2026-10-06' => [
                    'check_in_at' => '2026-10-06 06:55:00',
                    'status' => 'hadir',
                    'check_in_status' => 'tepat_waktu',
                    'check_out_status' => 'belum_absen',
                ],
                '2026-10-07' => [
                    'check_in_at' => '2026-10-07 06:55:00',
                    'check_out_at' => '2026-10-07 14:05:00',
                    'status' => 'hadir',
                    'check_in_status' => 'tepat_waktu',
                    'check_out_status' => 'pulang_cepat',
                    'early_leave_minutes' => 55,
                ],
                '2026-10-08' => [
                    'check_in_at' => '2026-10-08 07:16:00',
                    'check_out_at' => '2026-10-08 14:05:00',
                    'status' => 'terlambat',
                    'check_in_status' => 'terlambat',
                    'check_out_status' => 'pulang_cepat',
                    'late_minutes' => 16,
                    'early_leave_minutes' => 55,
                ],
                '2026-10-09' => [
                    'status' => 'izin',
                ],
                '2026-10-12' => [
                    'status' => 'sakit',
                ],
                '2026-10-13' => [
                    'status' => 'alpa',
                ],
                '2026-10-14' => [
                    'check_out_at' => '2026-10-14 14:05:00',
                    'status' => 'perlu_tinjauan',
                    'check_out_status' => 'perlu_verifikasi',
                    'early_leave_minutes' => 55,
                ],
                '2026-10-16' => [
                    'check_in_at' => '2026-10-16 07:10:00',
                    'check_out_at' => '2026-10-16 15:00:00',
                    'status' => 'hadir',
                    'check_in_status' => 'tepat_waktu',
                    'check_out_status' => 'tepat_waktu',
                    'late_minutes' => 10,
                ],
            ];

            foreach ($records as $date => $attributes) {
                Attendance::create(array_merge([
                    'student_id' => $this->student1->id,
                    'school_date' => $date,
                    'policy_version' => 'v1.0',
                ], $attributes));
            }

            $report = app(AttendanceReportService::class)->getStudentMonthlyReport([
                'student_id' => $this->student1->id,
                'month' => '2026-10',
            ]);

            $rows = collect($report['dailyRows'])->keyBy('day_number');

            $this->assertSame('-', $rows[5]['note']);
            $this->assertSame('-', $rows[6]['note']);
            $this->assertSame('-', $rows[7]['note']);
            $this->assertSame('-', $rows[8]['note']);
            $this->assertSame('-', $rows[9]['note']);
            $this->assertSame('-', $rows[12]['note']);
            $this->assertSame('Alpa', $rows[13]['note']);
            $this->assertSame('-', $rows[14]['note']);
            $this->assertSame('Libur', $rows[15]['note']);
            $this->assertSame('-', $rows[16]['note']);
            $this->assertSame('Alpa', $rows[19]['note']);
            $this->assertSame('-', $rows[22]['note']);
            $this->assertSame('-', $rows[23]['note']);
            $this->assertSame('-', $rows[10]['note']);
            $this->assertSame('-', $rows[11]['note']);

            $this->assertSame(0, $rows[5]['late_minutes_display']);
            $this->assertSame(0, $rows[5]['early_leave_minutes_display']);
            $this->assertSame(0, $rows[6]['late_minutes_display']);
            $this->assertNull($rows[6]['early_leave_minutes_display']);
            $this->assertSame(55, $rows[7]['early_leave_minutes_display']);
            $this->assertSame(16, $rows[8]['late_minutes_display']);
            $this->assertSame(55, $rows[8]['early_leave_minutes_display']);
            $this->assertNull($rows[9]['late_minutes_display']);
            $this->assertNull($rows[9]['early_leave_minutes_display']);
            $this->assertNull($rows[13]['late_minutes_display']);
            $this->assertNull($rows[13]['early_leave_minutes_display']);
            $this->assertNull($rows[14]['late_minutes_display']);
            $this->assertSame(55, $rows[14]['early_leave_minutes_display']);
            $this->assertSame(10, $rows[16]['late_minutes_display']);
            $this->assertSame(0, $rows[16]['early_leave_minutes_display']);

            $this->assertSame(4, $report['summary']['hadir']);
            $this->assertSame(1, $report['summary']['terlambat']);
            $this->assertSame(1, $report['summary']['perlu_tinjauan']);
            $this->assertSame(5, $report['summary']['total_hadir']);

            $response = $this->actingAs($this->admin)->get(route('attendances.reports.index', [
                'type' => 'student',
                'student_id' => $this->student1->id,
                'month' => '2026-10',
            ]));

            $response->assertOk();
            $response->assertSee('Telat (Menit)');
            $response->assertSee('PLG Cepat (Menit)');
            $response->assertDontSee('>STATUS</th>', false);
            $response->assertSee('Alpa');
            $response->assertSee('Libur');
            $response->assertDontSee('Terlambat, Pulang Cepat');
            $response->assertDontSee('Belum Absen Pulang');
            $response->assertDontSee('Tanpa scan setelah batas Alpa');

            $standalone = $this->actingAs($this->admin)->get(route('attendances.report.student', [
                'student_id' => $this->student1->id,
                'month' => '2026-10',
            ]));

            $standalone->assertOk();
            $standalone->assertSee('TELAT (MENIT)');
            $standalone->assertSee('PLG CEPAT (MENIT)');
            $standalone->assertDontSee('>STATUS</th>', false);
            $standalone->assertSee('Alpa');
            $standalone->assertSee('Libur');
            $standalone->assertDontSee('Terlambat, Pulang Cepat');
            $standalone->assertDontSee('Belum Absen Pulang');
            $standalone->assertDontSee('Tanpa scan setelah batas Alpa');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_student_monthly_report_uses_alpa_description_for_virtual_alpa(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-06 12:00:00', 'Asia/Jakarta'));

        try {
            SchoolSchedule::create([
                'day_pattern' => 'regular',
                'day_name' => 'Senin',
                'day_of_week' => 1,
                'check_in_start' => '06:00',
                'start_at' => '07:00',
                'late_after' => '07:15',
                'check_out_start' => '13:00',
                'end_at' => '15:00',
                'alpa_cutoff' => '16:00',
                'is_active' => true,
            ]);

            $report = app(AttendanceReportService::class)->getStudentMonthlyReport([
                'student_id' => $this->student1->id,
                'month' => '2026-10',
            ]);

            $mondayRow = collect($report['dailyRows'])->firstWhere('day_number', 5);

            $this->assertSame('alpa', $mondayRow['status']);
            $this->assertTrue($mondayRow['is_virtual_alpa']);
            $this->assertSame('Alpa', $mondayRow['note']);
            $this->assertSame(1, $report['summary']['virtual_alpa']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_daily_report_exposes_minutes_after_scan_columns_and_dash_without_calculation(): void
    {
        $date = '2026-10-14';

        SchoolSchedule::create([
            'day_pattern' => 'regular',
            'day_name' => 'Rabu',
            'day_of_week' => 3,
            'check_in_start' => '06:00',
            'start_at' => '07:00',
            'late_after' => '07:15',
            'check_out_start' => '13:00',
            'end_at' => '16:00',
            'is_active' => true,
        ]);

        $cases = [
            'Tepat Waktu' => [
                'check_in_at' => "{$date} 06:55:00",
                'check_out_at' => "{$date} 16:00:00",
                'status' => 'hadir',
                'check_in_status' => 'tepat_waktu',
                'check_out_status' => 'tepat_waktu',
                'late_minutes' => 0,
                'early_leave_minutes' => 0,
            ],
            'Toleransi' => [
                'check_in_at' => "{$date} 07:10:00",
                'check_out_at' => "{$date} 16:00:00",
                'status' => 'hadir',
                'check_in_status' => 'tepat_waktu',
                'check_out_status' => 'tepat_waktu',
                'late_minutes' => 10,
                'early_leave_minutes' => 0,
            ],
            'Terlambat' => [
                'check_in_at' => "{$date} 07:16:00",
                'status' => 'terlambat',
                'check_in_status' => 'terlambat',
                'check_out_status' => 'belum_absen',
                'late_minutes' => 16,
            ],
            'Pulang Cepat' => [
                'check_in_at' => "{$date} 06:55:00",
                'check_out_at' => "{$date} 15:05:00",
                'status' => 'hadir',
                'check_in_status' => 'tepat_waktu',
                'check_out_status' => 'pulang_cepat',
                'late_minutes' => 0,
                'early_leave_minutes' => 55,
            ],
            'Alpa' => [
                'status' => 'alpa',
                'late_minutes' => 0,
                'early_leave_minutes' => 0,
            ],
            'Izin' => [
                'status' => 'izin',
            ],
            'Sakit' => [
                'status' => 'sakit',
            ],
            'Belum Absen Pulang' => [
                'check_in_at' => "{$date} 06:55:00",
                'status' => 'hadir',
                'check_in_status' => 'tepat_waktu',
                'check_out_status' => 'belum_absen',
                'late_minutes' => 0,
            ],
            'Perlu Verifikasi' => [
                'check_out_at' => "{$date} 14:05:00",
                'status' => 'perlu_tinjauan',
                'check_out_status' => 'perlu_verifikasi',
                'early_leave_minutes' => 115,
            ],
        ];

        $studentIds = [];
        $caseNumber = 1;

        foreach ($cases as $name => $attributes) {
            $student = Student::create([
                'nis' => 'MIN'.str_pad((string) $caseNumber++, 2, '0', STR_PAD_LEFT),
                'name' => $name,
                'class_id' => $this->class1->id,
                'active' => true,
            ]);

            $studentIds[$name] = $student->id;

            Attendance::create(array_merge([
                'student_id' => $student->id,
                'school_date' => $date,
                'policy_version' => 'v1.0',
            ], $attributes));
        }

        $report = app(AttendanceReportService::class)->getDailyReport([
            'date' => $date,
            'class_id' => $this->class1->id,
        ]);

        $rows = collect($report['rows'])->keyBy('student_name');

        $this->assertSame(0, $rows['Tepat Waktu']['late_minutes_display']);
        $this->assertSame(0, $rows['Tepat Waktu']['early_leave_minutes_display']);

        $this->assertSame('hadir', $rows['Toleransi']['status']);
        $this->assertSame(10, $rows['Toleransi']['late_minutes_display']);
        $this->assertSame(0, $rows['Toleransi']['early_leave_minutes_display']);

        $this->assertSame(16, $rows['Terlambat']['late_minutes_display']);
        $this->assertNull($rows['Terlambat']['early_leave_minutes_display']);

        $this->assertSame(0, $rows['Pulang Cepat']['late_minutes_display']);
        $this->assertSame(55, $rows['Pulang Cepat']['early_leave_minutes_display']);

        foreach (['Alpa', 'Izin', 'Sakit'] as $name) {
            $this->assertNull($rows[$name]['late_minutes_display']);
            $this->assertNull($rows[$name]['early_leave_minutes_display']);
        }

        $this->assertSame(0, $rows['Belum Absen Pulang']['late_minutes_display']);
        $this->assertNull($rows['Belum Absen Pulang']['early_leave_minutes_display']);

        $this->assertNull($rows['Perlu Verifikasi']['late_minutes_display']);
        $this->assertSame(115, $rows['Perlu Verifikasi']['early_leave_minutes_display']);

        $dailyPreview = $this->actingAs($this->admin)->get(route('attendances.reports.index', [
            'type' => 'daily',
            'date' => $date,
            'class_id' => $this->class1->id,
        ]));

        $dailyPreview->assertOk();
        $dailyPreview->assertSee('Telat (Menit)');
        $dailyPreview->assertSee('PLG Cepat (Menit)');

        $studentReport = $this->actingAs($this->admin)->get(route('attendances.report.student', [
            'student_id' => $studentIds['Terlambat'],
            'month' => '2026-10',
        ]));

        $studentReport->assertOk();
        $studentReport->assertSee('TELAT (MENIT)');
        $studentReport->assertSee('PLG CEPAT (MENIT)');
    }

    public function test_daily_report_does_not_filter_by_student_id(): void
    {
        $today = Carbon::today('Asia/Jakarta')->toDateString();

        $response = $this->actingAs($this->admin)->get(route('attendances.reports.index', [
            'type' => 'daily',
            'date' => $today,
            'student_id' => $this->student1->id,
        ]));

        $response->assertOk();
        // studentId harus di-reset ke null pada laporan harian
        $response->assertViewHas('studentId', null);
    }

    public function test_student_report_filters_students_by_class_id(): void
    {
        $response = $this->actingAs($this->admin)->get(route('attendances.reports.index', [
            'type' => 'student',
            'class_id' => $this->class1->id,
            'month' => '2026-10',
        ]));

        $response->assertOk();
        $response->assertViewHas('classId', (string) $this->class1->id);
        $response->assertViewHas('students', function ($students) {
            return $students->every(fn ($s) => (int) $s->class_id === (int) $this->class1->id);
        });
    }

    public function test_empty_state_when_no_records_found(): void
    {
        $response = $this->actingAs($this->admin)->get(route('attendances.reports.index', [
            'type' => 'daily',
            'date' => '2025-01-01',
        ]));

        $response->assertStatus(200);
        $response->assertSee('Tidak Ada Data Presensi');
    }

    public function test_homeroom_teacher_restricted_to_assigned_class(): void
    {
        $response = $this->actingAs($this->waliKelas)->get(route('attendances.reports.index', [
            'type' => 'class',
            'class_id' => $this->class2->id, // Mencoba akses kelas lain
        ]));

        $response->assertStatus(200);
        // Otomatis ter-override ke kelas sendiri (X-RPL 1)
        $response->assertSee('X-RPL 1');
    }

    public function test_homeroom_teacher_report_ui_is_clean_and_simplified(): void
    {
        // 1. Pada halaman Harian, wali kelas tidak melihat dropdown <select name="class_id"> melainkan badge statis
        $daily = $this->actingAs($this->waliKelas)->get(route('attendances.reports.index', ['type' => 'daily']));
        $daily->assertOk();
        $daily->assertDontSee('<select name="class_id"', false);
        $daily->assertSee('Kelas:');
        $daily->assertSee('X-RPL 1');

        // 2. Wali kelas tidak melihat tab redundant "Laporan Bulanan", tapi melihat "Rekap Bulanan Kelas"
        $daily->assertDontSee('>Laporan Bulanan<', false);
        $daily->assertSee('Rekap Bulanan Kelas');

        // 3. Jika wali kelas mengakses type=monthly langsung, otomatis diarahkan/di-treat sebagai type=class
        $monthly = $this->actingAs($this->waliKelas)->get(route('attendances.reports.index', ['type' => 'monthly']));
        $monthly->assertOk();
        $monthly->assertViewHas('type', 'class');
    }

    public function test_homeroom_teacher_cannot_print_or_export_another_class_student_report(): void
    {
        $this->actingAs($this->waliKelas)
            ->get(route('attendances.reports.print', [
                'type' => 'student',
                'student_id' => $this->student2->id,
                'month' => '2026-10',
            ]))
            ->assertForbidden();

        $this->actingAs($this->waliKelas)
            ->get(route('attendances.reports.export', [
                'type' => 'student',
                'student_id' => $this->student2->id,
                'month' => '2026-10',
            ]))
            ->assertForbidden();
    }

    public function test_homeroom_teacher_monthly_report_is_scoped_to_assigned_class(): void
    {
        $response = $this->actingAs($this->waliKelas)->get(route('attendances.report.monthly', [
            'class_id' => $this->class2->id,
            'month' => '2026-10',
        ]));

        $response->assertOk();
        $response->assertSee('X-RPL 1');
        $response->assertDontSee('X-TKJ 1');
    }

    public function test_print_view_renders_clean_layout_without_kop_and_signatures(): void
    {
        $today = Carbon::today('Asia/Jakarta')->toDateString();

        // 1. Uji Cetak Harian (Multi-Halaman)
        $responseDaily = $this->actingAs($this->admin)->get(route('attendances.reports.print', [
            'type' => 'daily',
            'date' => $today,
        ]));

        $responseDaily->assertStatus(200);
        $responseDaily->assertSee('LAPORAN PRESENSI HARIAN SISWA');
        $responseDaily->assertSee('multi-page-sheet');
        $responseDaily->assertDontSee('DINAS PENDIDIKAN DAN KEBUDAYAAN');
        $responseDaily->assertDontSee('Kepala Sekolah');

        // 2. Uji Cetak Per Siswa (Wajib 1 Halaman A4)
        $responseStudent = $this->actingAs($this->admin)->get(route('attendances.reports.print', [
            'type' => 'student',
            'student_id' => $this->student1->id,
            'month' => '2026-10',
        ]));

        $responseStudent->assertStatus(200);
        $responseStudent->assertSee('Ahmad Dahlan');
        $responseStudent->assertSee('single-page-sheet');
        $responseStudent->assertDontSee('DINAS PENDIDIKAN DAN KEBUDAYAAN');
        $responseStudent->assertDontSee('Kepala Sekolah');

        // 3. Uji Cetak Per Kelas (Wajib 1 Halaman A4)
        $responseClass = $this->actingAs($this->admin)->get(route('attendances.reports.print', [
            'type' => 'class',
            'class_id' => $this->class1->id,
            'month' => '2026-10',
        ]));

        $responseClass->assertStatus(200);
        $responseClass->assertSee('X-RPL 1');
        $responseClass->assertSee('single-page-sheet');
        $responseClass->assertDontSee('DINAS PENDIDIKAN DAN KEBUDAYAAN');
        $responseClass->assertDontSee('Kepala Sekolah');
    }

    public function test_print_reports_show_schedule_and_scan_columns_with_two_column_info(): void
    {
        // Senin, 5 Oktober 2026
        $today = '2026-10-05';

        SchoolSchedule::create([
            'day_pattern' => 'Senin',
            'day_name' => 'Senin',
            'day_of_week' => 1,
            'check_in_start' => '06:00',
            'start_at' => '07:00',
            'late_after' => '07:15',
            'check_out_start' => '13:00',
            'end_at' => '14:00',
            'is_active' => true,
        ]);

        Attendance::create([
            'student_id' => $this->student1->id,
            'school_date' => $today,
            'check_in_at' => Carbon::parse("{$today} 06:55:00", 'Asia/Jakarta'),
            'check_out_at' => Carbon::parse("{$today} 14:05:00", 'Asia/Jakarta'),
            'status' => 'hadir',
            'policy_version' => 'v1.0',
        ]);

        // Blok keterangan 2 kolom (Nama/NIS kiri, Kelas/Periode kanan)
        // + kolom jadwal & scan masuk/pulang dari pengaturan jadwal.
        $daily = $this->actingAs($this->admin)->get(route('attendances.reports.print', [
            'type' => 'daily',
            'date' => $today,
        ]));

        $daily->assertOk();
        // Blok identitas 2 kolom: label & titik dua dipisah ke sel berbeda demi perataan.
        $daily->assertSee('identity-table', false);
        $daily->assertSee('Hari, Tanggal');
        $daily->assertSee('Kelas');
        $daily->assertSee('Jadwal Masuk');
        $daily->assertSee('Jadwal Pulang');
        $daily->assertDontSee('w-[55px]">Nama</td>', false);
        $daily->assertDontSee('w-[55px]">NIS</td>', false);
        $daily->assertSee('SCAN MASUK');
        $daily->assertSee('SCAN PULANG');
        $daily->assertSee('TELAT (MENIT)');
        $daily->assertSee('PLG CEPAT (MENIT)');
        $daily->assertSee('07:00'); // jam masuk resmi sesuai jadwal
        $daily->assertSee('14:00'); // batas akhir pulang sesuai jadwal
        $daily->assertSee('06:55'); // hasil scan masuk aktual

        // Per siswa: kolom pertama No + Tanggal (tanpa nama hari), memuat identitas Nama & NIS
        $student = $this->actingAs($this->admin)->get(route('attendances.reports.print', [
            'type' => 'student',
            'student_id' => $this->student1->id,
            'month' => '2026-10',
        ]));

        $student->assertOk();
        $student->assertSee('w-[55px]">Nama</td>', false);
        $student->assertSee('w-[55px]">NIS</td>', false);
        $student->assertSee('>Kelas<', false);
        $student->assertSee('>Periode<', false);
        $student->assertSee('TANGGAL');
        $student->assertDontSee('HARI');
        $student->assertSee('JADWAL MASUK');
        $student->assertSee('SCAN MASUK');
        $student->assertSee('TELAT (MENIT)');
        $student->assertSee('JADWAL PULANG');
        $student->assertSee('SCAN PULANG');
        $student->assertSee('PLG CEPAT (MENIT)');
        $student->assertDontSee('>STATUS</th>', false);

        // Bulanan: tidak memuat Nama dan NIS individual di identitas dokumen, serta tidak memuat kolom jam masuk & pulang
        $monthly = $this->actingAs($this->admin)->get(route('attendances.reports.print', [
            'type' => 'monthly',
            'month' => '2026-10',
        ]));

        $monthly->assertOk();
        $monthly->assertSee('fonts.googleapis.com/css2?family=Inter', false);
        $monthly->assertDontSee('w-[55px]">Nama</td>', false);
        $monthly->assertDontSee('w-[55px]">NIS</td>', false);
        $monthly->assertSee('Semua Kelas');
        $monthly->assertDontSee('JAM MASUK');
        $monthly->assertDontSee('JAM PULANG');
        $monthly->assertSee('Waktu Cetak:');
        $monthly->assertSee('Dicetak oleh:');
        $monthly->assertSee($this->admin->name);
    }

    public function test_export_excel_creates_audit_log_and_sanitizes_injection(): void
    {
        $today = Carbon::today('Asia/Jakarta')->toDateString();

        Attendance::create([
            'student_id' => $this->student2->id,
            'school_date' => $today,
            'status' => 'hadir',
            'policy_version' => 'v1.0',
        ]);

        $response = $this->actingAs($this->admin)->get(route('attendances.reports.export', [
            'type' => 'daily',
            'date' => $today,
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
        $this->assertStringContainsString('customWidth="1"', $sheetXml);
        $this->assertStringContainsString('<t>-</t>', $sheetXml);

        // Audit Log Assertion
        $auditLog = AuditLog::where('action', 'export_attendance_report')->latest('created_at')->first();
        $this->assertNotNull($auditLog);
        $this->assertSame($this->admin->id, $auditLog->actor_id);
        $this->assertSame('daily', $auditLog->after['report_type']);
        $this->assertSame('xlsx', $auditLog->after['format']);

        // Formula Injection Sanitization Unit Assertion
        $sanitized = SimpleXlsxExporter::sanitizeCellValue('=cmd| /C calc!A0');
        $this->assertStringStartsWith("'", $sanitized);

        $sanitizedPlus = SimpleXlsxExporter::sanitizeCellValue('+12345');
        // Numeric string is kept clean
        $this->assertSame('+12345', $sanitizedPlus);

        $sanitizedFormula = SimpleXlsxExporter::sanitizeCellValue('+SUM(A1:A5)');
        $this->assertSame("'+SUM(A1:A5)", $sanitizedFormula);

        $this->assertSame('-', SimpleXlsxExporter::sanitizeCellValue('-'));
    }
}
