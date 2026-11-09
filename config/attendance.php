<?php

return [
    'alpa_buffer_minutes' => max(1, (int) env('ATTENDANCE_ALPA_BUFFER_MINUTES', 5)),

    'scan_time_review_enabled' => (bool) env('ATTENDANCE_SCAN_TIME_REVIEW_ENABLED', true),

    'max_same_day_scan_delay_minutes' => max(0, (int) env('ATTENDANCE_MAX_SCAN_DELAY_MINUTES', 60)),

    'future_scan_tolerance_minutes' => max(0, (int) env('ATTENDANCE_FUTURE_SCAN_TOLERANCE_MINUTES', 5)),

    'log_processing_max_attempts' => max(1, (int) env('ATTENDANCE_LOG_MAX_ATTEMPTS', 4)),

    'log_processing_backoff_minutes' => [1, 5, 15, 60],

    // Batas usia jejak sukses terakhir connector sebelum mesin dianggap offline.
    'connector_status_stale_seconds' => max(1, (int) env('CONNECTOR_STATUS_STALE_SECONDS', 90)),

    // Peredaman riwayat sinkronisasi otomatis: keberhasilan dengan record sedikit
    // hanya dicatat sebagai heartbeat tiap N menit, sedangkan backlog besar dan
    // pemulihan setelah gagal selalu dicatat.
    'sync_log_success_interval_minutes' => max(1, (int) env('SYNC_LOG_SUCCESS_INTERVAL_MINUTES', 60)),
    'sync_log_significant_records' => max(1, (int) env('SYNC_LOG_SIGNIFICANT_RECORDS', 10)),
];
