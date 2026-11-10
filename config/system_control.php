<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Driver Pusat Kontrol Sistem
    |--------------------------------------------------------------------------
    |
    | `auto` memilih supervisor Windows saat aplikasi berjalan di Windows dan
    | systemd saat berjalan di Linux. Nilai `windows` atau `linux` dapat dipakai
    | untuk memaksa driver tertentu, misalnya pada pengujian.
    |
    */

    'driver' => env('SYSTEM_CONTROL_DRIVER', 'auto'),

    /*
    |--------------------------------------------------------------------------
    | Nama Unit systemd
    |--------------------------------------------------------------------------
    */

    'units' => [
        'worker' => env('SYSTEM_CONTROL_WORKER_UNIT', 'smartpresensi-worker'),
        // Worker terpisah untuk job panjang (sync/import/bulk) agar antrean
        // notifikasi WhatsApp tidak tertahan di belakangnya.
        'worker_long' => env('SYSTEM_CONTROL_WORKER_LONG_UNIT', 'smartpresensi-worker-long'),
        'connector' => env('SYSTEM_CONTROL_CONNECTOR_UNIT', 'solutionx601-connector'),
        'scheduler' => env('SYSTEM_CONTROL_SCHEDULER_UNIT', 'smartpresensi-scheduler'),
    ],

    'systemctl_binary' => env('SYSTEM_CONTROL_SYSTEMCTL_BIN', 'systemctl'),
    'sudo_binary' => env('SYSTEM_CONTROL_SUDO_BIN', '/usr/bin/sudo'),
    'restart_helper' => env('SYSTEM_CONTROL_RESTART_HELPER', '/usr/local/sbin/smartpresensi-service'),
    'scheduler_cron_file' => env('SYSTEM_CONTROL_SCHEDULER_CRON_FILE', '/etc/cron.d/smartpresensi-scheduler'),

    /*
    |--------------------------------------------------------------------------
    | Berkas Log Layanan
    |--------------------------------------------------------------------------
    |
    | Unit systemd menulis output ke berkas ini agar panel log dapat membacanya
    | tanpa memerlukan akses journald.
    |
    */

    'logs' => [
        'connector' => env('SYSTEM_CONTROL_CONNECTOR_LOG', storage_path('logs/connector_service.log')),
        'worker' => env('SYSTEM_CONTROL_WORKER_LOG', storage_path('logs/queue_worker_service.log')),
        'worker_long' => env('SYSTEM_CONTROL_WORKER_LONG_LOG', storage_path('logs/queue_worker_long_service.log')),
        'scheduler' => env('SYSTEM_CONTROL_SCHEDULER_LOG', storage_path('logs/scheduler.log')),
        'system' => env('SYSTEM_CONTROL_SYSTEM_LOG', storage_path('logs/laravel.log')),
    ],

];
