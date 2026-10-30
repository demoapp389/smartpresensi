<?php

return [
    'alpa_buffer_minutes' => max(1, (int) env('ATTENDANCE_ALPA_BUFFER_MINUTES', 5)),

    'scan_time_review_enabled' => (bool) env('ATTENDANCE_SCAN_TIME_REVIEW_ENABLED', true),

    'max_same_day_scan_delay_minutes' => max(0, (int) env('ATTENDANCE_MAX_SCAN_DELAY_MINUTES', 60)),

    'future_scan_tolerance_minutes' => max(0, (int) env('ATTENDANCE_FUTURE_SCAN_TOLERANCE_MINUTES', 5)),

    'log_processing_max_attempts' => max(1, (int) env('ATTENDANCE_LOG_MAX_ATTEMPTS', 4)),

    'log_processing_backoff_minutes' => [1, 5, 15, 60],
];
