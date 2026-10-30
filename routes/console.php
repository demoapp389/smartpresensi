<?php

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
Schedule::command('fingerprint:reconcile')->everyMinute();

// Samakan jam mesin driver SOAP sekali sehari (connector C# mengatur jamnya sendiri).
Schedule::command('fingerprint:pull-soap --sync-time --time-only')->dailyAt('05:00');

// Validasi ulang berkala nomor WhatsApp wali (PRD BR-05 / Fonnte Optimization, default nonaktif di config)
Schedule::command('whatsapp:revalidate-guardians')->daily();

// Rotasi log connector/worker, pangkas offline buffer, dan bersihkan berkas state lama.
Schedule::command('fingerprint:prune-resources')->dailyAt('03:30');

// Batalkan notifikasi WhatsApp 'held' yang kedaluwarsa agar tidak menggantung permanen
// bila nomor wali tidak pernah divalidasi ulang.
Schedule::command('whatsapp:expire-held-notifications')->hourly();

// Materialisasi presensi tanpa scan menjadi perlu_tinjauan dan finalisasi Alpa H+1.
Schedule::command('attendance:sync-pending')->everyTenMinutes()->withoutOverlapping(5);

// Coba ulang raw log yang gagal sementara agar Auto-Alpa tidak tertahan selamanya.
Schedule::command('attendance:retry-logs')->everyFiveMinutes()->withoutOverlapping(10);
