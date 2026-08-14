<?php

return [
    'database' => env('E2E_DB_DATABASE', 'pas_anbg_e2e'),
    'password' => env('E2E_PASSWORD', 'Password-Test-123!'),
    'storage_root' => env('E2E_STORAGE_ROOT', storage_path('app/e2e-private')),
];
