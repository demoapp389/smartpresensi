<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default WhatsApp Provider
    |--------------------------------------------------------------------------
    |
    | Supported providers: 'log', 'meta', 'fonnte', 'mock'
    | Default is 'log' for local testing without requiring external credentials.
    |
    */
    'default' => env('WA_GATEWAY_PROVIDER', 'log'),

    'school_name' => env('SCHOOL_NAME', 'SMA Negeri 1'),

    'retry_limit' => (int) env('WA_RETRY_LIMIT', 3),

    'meta' => [
        'version' => env('WA_META_VERSION', 'v20.0'),
        'phone_number_id' => env('WA_META_PHONE_NUMBER_ID', ''),
        'access_token' => env('WA_META_ACCESS_TOKEN', ''),
        // Token verifikasi GET webhook. Kosong = verifikasi ditolak (fail-closed);
        // isi WA_WEBHOOK_VERIFY_TOKEN di produksi.
        'webhook_verify_token' => env('WA_WEBHOOK_VERIFY_TOKEN', ''),
        // App Secret Meta untuk memverifikasi tanda tangan X-Hub-Signature-256 pada POST webhook.
        'app_secret' => env('WA_META_APP_SECRET', ''),
    ],

    // Rahasia bersama untuk webhook Fonnte: header X-Webhook-Token/X-Webhook-Secret,
    // query ?token=, atau field payload secret/secret_key/webhook_secret/token.
    // Kosong = semua request webhook ditolak (fail-closed), termasuk saat Meta app secret tidak diisi.
    'webhook_secret' => env('WA_WEBHOOK_SECRET', ''),

    'webhook_base_url' => env('WA_WEBHOOK_BASE_URL', ''),

    'webhook_public_only' => (bool) env('WA_WEBHOOK_PUBLIC_ONLY', false),

    'fonnte' => [
        'token' => env('WA_FONNTE_TOKEN', ''),
        'url' => env('WA_FONNTE_URL', 'https://api.fonnte.com/send'),
        'validate_url' => env('WA_FONNTE_VALIDATE_URL', 'https://api.fonnte.com/validate'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Validasi Nomor WhatsApp Fonnte
    |--------------------------------------------------------------------------
    |
    | Pengaturan validasi nomor orang tua menggunakan Fonnte API /validate.
    |
    */
    'validation' => [
        'auto_validate_on_save' => (bool) env('WA_AUTO_VALIDATE_ON_SAVE', true),
        'batch_delay_ms' => (int) env('WA_VALIDATE_BATCH_DELAY_MS', 500),
        'chunk_size' => (int) env('WA_VALIDATE_CHUNK_SIZE', 50),
        'expiration_hours' => (int) env('WA_NOTIFICATION_EXPIRATION_HOURS', 24),
    ],

    /*
    |--------------------------------------------------------------------------
    | Validasi Ulang Berkala (Default Nonaktif)
    |--------------------------------------------------------------------------
    */
    'revalidation' => [
        'enabled' => (bool) env('WA_REVALIDATION_ENABLED', false),
        'interval_days' => (int) env('WA_REVALIDATION_DAYS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Pesan Template Standar (PRD FR-12)
    |--------------------------------------------------------------------------
    |
    | Digunakan jika provider mengirim teks langsung atau parameter utility template.
    |
    */
    'templates' => [
        'check_in' => "Assalamu'alaikum / Selamat Pagi Bapak/Ibu Wali dari :nama_siswa (Kelas :kelas).\n\nMenginformasikan bahwa siswa telah HADIR dan melakukan scan absensi masuk di :nama_sekolah pada tanggal :tanggal pukul :waktu.\n\nTerima kasih.",
        'late' => "Assalamu'alaikum / Selamat Pagi Bapak/Ibu Wali dari :nama_siswa (Kelas :kelas).\n\nMenginformasikan bahwa siswa hadir TERLAMBAT di :nama_sekolah pada tanggal :tanggal pukul :waktu.\n\nTerima kasih atas perhatiannya.",
        'check_out' => "Assalamu'alaikum / Selamat Siang Bapak/Ibu Wali dari :nama_siswa (Kelas :kelas).\n\nMenginformasikan bahwa siswa telah SELESAI kegiatan sekolah dan melakukan scan absensi pulang di :nama_sekolah pada tanggal :tanggal pukul :waktu.\n\nTerima kasih.",
        'default' => 'Pemberitahuan Absensi Siswa :nama_siswa (Kelas :kelas) di :nama_sekolah pada tanggal :tanggal pukul :waktu dengan status :status.',
    ],
];
