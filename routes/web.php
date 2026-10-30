<?php

use App\Http\Controllers\AttendanceLogController;
use App\Http\Controllers\AttendanceRecordController;
use App\Http\Controllers\AttendanceReportController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DatabaseBackupController;
use App\Http\Controllers\DocumentationController;
use App\Http\Controllers\FingerprintDeviceController;
use App\Http\Controllers\GuardianValidationController;
use App\Http\Controllers\NotificationLogController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SchoolClassController;
use App\Http\Controllers\SchoolScheduleController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\SystemControlController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WhatsAppBroadcastController;
use App\Http\Controllers\WhatsAppTemplateController;
use Illuminate\Support\Facades\Route;

// Autentikasi Admin & Operator
Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login'])
    ->middleware('throttle:5,1')
    ->name('login.process');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

// Rute Internal Aplikasi (Membutuhkan Login)
Route::middleware('auth')->group(function () {
    // 1. Dashboard
    Route::get('/', [DashboardController::class, 'index'])->name('home');
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Profil Pengguna
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');

    // 2. Presensi, Laporan & Koreksi Operator
    Route::get('/attendances', [AttendanceRecordController::class, 'index'])->name('attendances.index');
    Route::get('/attendances/export', [AttendanceRecordController::class, 'export'])->name('attendances.export');
    Route::get('/attendances/reports', [AttendanceReportController::class, 'index'])->name('attendances.reports.index');
    Route::get('/attendances/reports/print', [AttendanceReportController::class, 'print'])->name('attendances.reports.print');
    Route::get('/attendances/reports/export', [AttendanceReportController::class, 'export'])->name('attendances.reports.export');
    Route::get('/attendances/report/monthly', [AttendanceRecordController::class, 'monthlyReport'])->name('attendances.report.monthly');
    Route::get('/attendances/report/student', [AttendanceRecordController::class, 'studentMonthlyReport'])->name('attendances.report.student');
    Route::get('/attendances/report/class', [AttendanceRecordController::class, 'classMonthlyReport'])->name('attendances.report.class');
    Route::get('/attendances/correction', [AttendanceRecordController::class, 'correctionIndex'])->name('attendances.correction');
    Route::post('/attendances/bulk-correct', [AttendanceRecordController::class, 'bulkCorrect'])
        ->middleware('role:admin,operator')
        ->name('attendances.bulk-correct');
    Route::post('/attendances/{attendance}/correct', [AttendanceRecordController::class, 'correct'])->name('attendances.correct');
    Route::post('/attendances/{attendance}/admin-scan-exception', [AttendanceRecordController::class, 'adminScanException'])
        ->middleware('role:admin')
        ->name('attendances.admin-scan-exception');
    Route::post('/attendances/{attendance}/scan-reason', [AttendanceRecordController::class, 'recordScanReason'])
        ->middleware('role:admin,operator')
        ->name('attendances.scan-reason');
    Route::post('/attendances/manual', [AttendanceRecordController::class, 'storeManual'])->name('attendances.manual');

    // 3. Modul Operasional Khusus Admin & Operator (Dikecualikan untuk Wali Kelas)
    Route::middleware('role:admin,operator')->group(function () {
        // Log Scan Mentah (Raw Logs) & Peninjauan Unmapped
        Route::get('/logs/raw', [AttendanceLogController::class, 'rawIndex'])->name('logs.raw');
        Route::post('/logs/raw/{log}/retry', [AttendanceLogController::class, 'retry'])->name('logs.raw.retry');
        Route::get('/logs/unmapped', [AttendanceLogController::class, 'unmappedIndex'])->name('logs.unmapped');
        Route::post('/logs/unmapped/map', [AttendanceLogController::class, 'mapLog'])->name('logs.unmapped.map');

        // Master Data Siswa & Wali Murid
        // Pencarian siswa untuk pemilih pada modal pemetaan PIN (JSON, hanya-baca)
        Route::get('/students/search', [StudentController::class, 'searchOptions'])->name('students.search-options');

        Route::get('/students/template', [StudentController::class, 'downloadTemplate'])->name('students.template');
        Route::post('/students/import', [StudentController::class, 'import'])->name('students.import');
        Route::delete('/students/bulk', [StudentController::class, 'bulkDestroy'])->name('students.bulk-destroy');
        Route::post('/students/bulk-assign-class', [StudentController::class, 'bulkAssignClass'])->name('students.bulk-assign-class');
        Route::post('/students/bulk-restore', [StudentController::class, 'bulkRestore'])->name('students.bulk-restore');
        Route::delete('/students/bulk-force', [StudentController::class, 'bulkForceDelete'])->name('students.bulk-force-delete');
        Route::post('/students/{id}/restore', [StudentController::class, 'restore'])->name('students.restore');
        Route::delete('/students/{id}/force', [StudentController::class, 'forceDelete'])->name('students.force-delete');
        Route::resource('students', StudentController::class)->except(['create', 'show', 'edit']);

        // Validasi Nomor WhatsApp Wali (Fonnte API /validate)
        Route::get('/guardians/validation', [GuardianValidationController::class, 'index'])->name('guardians.validation');
        Route::post('/guardians/{guardian}/validate', [GuardianValidationController::class, 'validateSingle'])->name('guardians.validate');
        Route::post('/guardians/bulk-validate', [GuardianValidationController::class, 'bulkValidate'])->name('guardians.bulk-validate');
        Route::get('/guardians/validation-stats', [GuardianValidationController::class, 'stats'])->name('guardians.validation-stats');

        // Master Data Kelas
        Route::resource('classes', SchoolClassController::class)->except(['create', 'show', 'edit']);

        // Outbox WhatsApp & Riwayat
        Route::get('/notifications', [NotificationLogController::class, 'index'])->name('notifications.index');
        Route::post('/notifications/retry-all-failed', [NotificationLogController::class, 'retryAllFailed'])->name('notifications.retry-all-failed');
        Route::post('/notifications/bulk-retry', [NotificationLogController::class, 'bulkRetry'])->name('notifications.bulk-retry');
        Route::delete('/notifications/bulk-destroy', [NotificationLogController::class, 'bulkDestroy'])->name('notifications.bulk-destroy');
        Route::post('/notifications/{notification}/retry', [NotificationLogController::class, 'retry'])->name('notifications.retry');
        Route::post('/notifications/test', [NotificationLogController::class, 'sendTest'])->name('notifications.test');

        // Siaran Pengumuman Massal WhatsApp ke Wali Murid
        Route::get('/whatsapp/broadcast', [WhatsAppBroadcastController::class, 'create'])->name('whatsapp.broadcast.create');
        Route::post('/whatsapp/broadcast', [WhatsAppBroadcastController::class, 'store'])->name('whatsapp.broadcast.store');
        Route::match(['GET', 'POST'], '/whatsapp/broadcast/recipient-count', [WhatsAppBroadcastController::class, 'recipientCount'])->name('whatsapp.broadcast.recipient-count');
    });

    // ============================================================
    // KHUSUS ADMINISTRATOR SEKOLAH (RBAC - PRD Bagian 4)
    // ============================================================
    Route::middleware('admin')->group(function () {
        // Manajemen Pengguna Sistem (User Management)
        Route::resource('users', UserController::class)->except(['show']);

        // 6. Perangkat Fingerprint & Pemetaan PIN
        Route::get('/devices', [FingerprintDeviceController::class, 'index'])->name('devices.index');
        Route::post('/devices', [FingerprintDeviceController::class, 'store'])->name('devices.store');
        Route::put('/devices/{device}', [FingerprintDeviceController::class, 'update'])->name('devices.update');
        Route::delete('/devices/{device}', [FingerprintDeviceController::class, 'destroy'])->name('devices.destroy');
        Route::post('/devices/{device}/rotate-token', [FingerprintDeviceController::class, 'rotateToken'])->name('devices.rotate-token');
        Route::get('/devices/{device}/connector-config', [FingerprintDeviceController::class, 'connectorConfig'])->name('devices.connector-config');
        Route::post('/devices/{device}/test-connection', [FingerprintDeviceController::class, 'testConnection'])->name('devices.test-connection');
        Route::post('/devices/{device}/sync-students', [FingerprintDeviceController::class, 'syncStudents'])->name('devices.sync-students');
        Route::post('/devices/{device}/sync-attendances', [FingerprintDeviceController::class, 'syncAttendances'])->name('devices.sync-attendances');
        Route::post('/devices/{device}/sync-time', [FingerprintDeviceController::class, 'syncTime'])->name('devices.sync-time');
        Route::post('/devices/{device}/pull-soap', [FingerprintDeviceController::class, 'pullSoapAttendances'])->name('devices.pull-soap');
        Route::post('/devices/{device}/restart', [FingerprintDeviceController::class, 'restartMachine'])->name('devices.restart');
        Route::post('/devices/{device}/clear-logs', [FingerprintDeviceController::class, 'clearMachineLogs'])->name('devices.clear-logs');
        Route::post('/devices/{device}/delete-user', [FingerprintDeviceController::class, 'deleteMachineUser'])->name('devices.delete-user');
        Route::post('/devices/{device}/bulk-push-names', [FingerprintDeviceController::class, 'bulkPushNames'])->name('devices.bulk-push-names');
        Route::post('/devices/{device}/bulk-backup-fingerprints', [FingerprintDeviceController::class, 'bulkBackupFingerprints'])->name('devices.bulk-backup-fingerprints');
        Route::post('/devices/{device}/bulk-clone', [FingerprintDeviceController::class, 'bulkCloneDevice'])->name('devices.bulk-clone');
        Route::post('/devices/mapping', [FingerprintDeviceController::class, 'storeMapping'])->name('devices.mapping.store');
        Route::delete('/devices/mapping/{mapping}', [FingerprintDeviceController::class, 'destroyMapping'])->name('devices.mapping.destroy');
        Route::post('/devices/mapping/{mapping}/push', [FingerprintDeviceController::class, 'pushMappingToMachine'])->name('devices.mapping.push');
        Route::put('/devices/mapping/{mapping}', [FingerprintDeviceController::class, 'updateMapping'])->name('devices.mapping.update');
        Route::post('/devices/mapping/{mapping}/backup-fingerprint', [FingerprintDeviceController::class, 'backupFingerprint'])->name('devices.mapping.backup-fingerprint');
        Route::post('/devices/mapping/{mapping}/distribute-fingerprint', [FingerprintDeviceController::class, 'distributeFingerprint'])->name('devices.mapping.distribute-fingerprint');

        // Halaman khusus: rute statis WAJIB dideklarasikan sebelum /devices/{device}
        // agar tidak tertangkap oleh route model binding.
        Route::get('/devices/mappings', [FingerprintDeviceController::class, 'mappings'])->name('devices.mappings');
        Route::get('/devices/sync-history', [FingerprintDeviceController::class, 'syncHistory'])->name('devices.sync-history');
        Route::get('/devices/status/{device}', [FingerprintDeviceController::class, 'deviceStatus'])->name('devices.status');
        Route::get('/devices/{device}', [FingerprintDeviceController::class, 'show'])->name('devices.show');

        // 7. Pengaturan Jadwal Sekolah & Kalender Libur
        Route::get('/schedules', [SchoolScheduleController::class, 'index'])->name('schedules.index');
        Route::put('/schedules/{schedule}', [SchoolScheduleController::class, 'update'])->name('schedules.update');
        Route::post('/schedules/holidays', [SchoolScheduleController::class, 'storeHoliday'])->name('schedules.holidays.store');
        Route::put('/schedules/holidays/{holiday}', [SchoolScheduleController::class, 'updateHoliday'])->name('schedules.holidays.update');
        Route::delete('/schedules/holidays/{holiday}', [SchoolScheduleController::class, 'destroyHoliday'])->name('schedules.holidays.destroy');

        // Tinjauan scan dengan waktu mesin yang tidak wajar (khusus admin).
        Route::post('/logs/raw/{log}/time-review/approve', [AttendanceLogController::class, 'approveTimeReview'])
            ->name('logs.raw.time-review.approve');
        Route::post('/logs/raw/{log}/time-review/reject', [AttendanceLogController::class, 'rejectTimeReview'])
            ->name('logs.raw.time-review.reject');

        // 9. Audit Log Sistem
        Route::get('/audit', [AuditLogController::class, 'index'])->name('audit.index');

        // Pengaturan Gateway WhatsApp (hanya admin, read-only + uji kirim)
        Route::get('/notifications/gateway', [NotificationLogController::class, 'gateway'])->name('notifications.gateway');

        // Editor Kustomisasi Template Pesan WhatsApp
        Route::get('/whatsapp/templates', [WhatsAppTemplateController::class, 'index'])->name('whatsapp.templates.index');
        Route::put('/whatsapp/templates/{template}', [WhatsAppTemplateController::class, 'update'])->name('whatsapp.templates.update');
        Route::post('/whatsapp/templates/preview', [WhatsAppTemplateController::class, 'preview'])->name('whatsapp.templates.preview');

        // 10. Pusat Kontrol Sistem (Windows Services, Heartbeat, Restart Manager)
        Route::get('/system/control', [SystemControlController::class, 'index'])->name('system.control');
        Route::post('/system/control/restart-connector', [SystemControlController::class, 'restartConnector'])->name('system.restart-connector');
        Route::post('/system/control/restart-worker', [SystemControlController::class, 'restartWorker'])->name('system.restart-worker');
        Route::post('/system/control/restart-supervisor', [SystemControlController::class, 'restartSupervisor'])->name('system.restart-supervisor');
        Route::post('/system/control/restart-all', [SystemControlController::class, 'restartAll'])->name('system.restart-all');
        Route::post('/system/control/sync-all-devices', [SystemControlController::class, 'syncAllDevices'])->name('system.sync-all-devices');
        Route::get('/system/control/logs', [SystemControlController::class, 'fetchLogs'])->name('system.logs');

        // 11. Backup Database (.sql dump)
        Route::get('/system/backup/database', [DatabaseBackupController::class, 'download'])->name('system.backup.database');

        // 12. Dokumentasi: Panduan Penggunaan aplikasi
        Route::get('/documentation', [DocumentationController::class, 'index'])->name('documentation.index');
        Route::get('/documentation/deployment', [DocumentationController::class, 'deployment'])->name('documentation.deployment');
        Route::get('/documentation/deployment/windows', [DocumentationController::class, 'deploymentWindows'])->name('documentation.deployment.windows');
        Route::get('/documentation/deployment/linux', [DocumentationController::class, 'deploymentLinux'])->name('documentation.deployment.linux');
    });
});
