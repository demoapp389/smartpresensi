<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Retensi Log Audit
    |--------------------------------------------------------------------------
    |
    | Log audit dipangkas otomatis oleh `php artisan model:prune`. Aksi yang
    | dijalankan sistem (mis. Auto-Alpa, pemrosesan log gagal) disimpan lebih
    | singkat karena informasinya sudah melekat pada baris data terkait.
    | Aksi yang dipicu pengguna disimpan lebih lama untuk kebutuhan kepatuhan.
    |
    */

    'retention_days' => (int) env('AUDIT_RETENTION_DAYS', 365),

    'system_retention_days' => (int) env('AUDIT_SYSTEM_RETENTION_DAYS', 30),

];
