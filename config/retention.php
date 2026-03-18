<?php

return [
    'pas_years_after_end' => (int) env('RETENTION_PAS_ARCHIVE_AFTER_YEARS', 5),
    'justificatifs_days' => (int) env('RETENTION_JUSTIFICATIFS_ARCHIVE_AFTER_DAYS', 1825),
    'action_logs_days' => (int) env('RETENTION_ACTION_LOGS_ARCHIVE_AFTER_DAYS', 1095),
    'notifications_days' => (int) env('RETENTION_NOTIFICATIONS_ARCHIVE_AFTER_DAYS', 365),
];
