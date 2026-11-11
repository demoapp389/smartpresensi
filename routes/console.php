<?php

use App\Models\AuditLog;
use App\Models\NotificationLog;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Heartbeat status online/offline mesin fingerprint (verifikasi konektivitas riil tiap 1 menit)
Schedule::command('fingerprint:health-check')->everyMinute()->withoutOverlapping(2);

// Rekonsiliasi absensi adaptif: rapat (5 menit) sepanjang jam sekolah, jarang (30 menit) di luar jam sekolah/hari libur.
// Menyalurkan tiap mesin ke jalur ingest utamanya (connector/SOAP) sesuai kolom ingest_driver.
Schedule::command('fingerprint:reconcile')->everyMinute()->withoutOverlapping(5);

// Samakan jam mesin driver SOAP sekali sehari (connector C# mengatur jamnya sendiri).
Schedule::command('fingerprint:pull-soap --sync-time --time-only')->dailyAt('05:00');

// Validasi ulang berkala nomor WhatsApp wali (PRD BR-05 / Fonnte Optimization, default nonaktif di config)
Schedule::command('whatsapp:revalidate-guardians')->daily();

// Rotasi log connector/worker, pangkas offline buffer (dengan arsip), dan bersihkan berkas state lama.
Schedule::command('fingerprint:prune-resources')->dailyAt('03:30');

// Backup database harian beserta checksum SHA-256. Retensi default 14 hari
// (storage/app/backups). Backup manual sebelum migrate tetap wajib.
Schedule::command('db:backup')->dailyAt('02:30')->withoutOverlapping(60);

// Pangkas log audit dan outbox WhatsApp sesuai kebijakan retensi
// (config/audit.php dan config/whatsapp.php).
Schedule::command('model:prune', ['--model' => [AuditLog::class, NotificationLog::class]])
    ->dailyAt('03:45');

// Pangkas riwayat job gagal agar tabel failed_jobs tidak tumbuh tanpa batas
// (retensi 7 hari; job yang masih perlu ditelusuri bisa di-retry lebih dulu).
Schedule::command('queue:prune-failed', ['--hours' => 168])->dailyAt('04:00');

// Batalkan notifikasi WhatsApp 'held' yang kedaluwarsa agar tidak menggantung permanen
// bila nomor wali tidak pernah divalidasi ulang.
Schedule::command('whatsapp:expire-held-notifications')->hourly();

// Kirim ulang notifikasi 'queued' yang tertahan (mis. lock job gagal) agar tidak
// berhenti permanen tanpa pernah dicoba kembali.
Schedule::command('whatsapp:sweep-queued')->everyFiveMinutes()->withoutOverlapping(10);

// Materialisasi presensi tanpa scan menjadi perlu_tinjauan dan finalisasi Alpa H+1.
Schedule::command('attendance:sync-pending')->everyTenMinutes()->withoutOverlapping(5);

// Coba ulang raw log yang gagal sementara agar Auto-Alpa tidak tertahan selamanya.
Schedule::command('attendance:retry-logs')->everyFiveMinutes()->withoutOverlapping(10);
