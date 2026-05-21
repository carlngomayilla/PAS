<?php

return [
    'passwords' => [
        'min_length' => (int) env('SECURITY_PASSWORD_MIN_LENGTH', 12),
        'expire_days' => (int) env('SECURITY_PASSWORD_EXPIRE_DAYS', 90),
        'history_size' => (int) env('SECURITY_PASSWORD_HISTORY_SIZE', 5),
        'require_letters' => (bool) env('SECURITY_PASSWORD_REQUIRE_LETTERS', true),
        'require_mixed_case' => (bool) env('SECURITY_PASSWORD_REQUIRE_MIXED_CASE', true),
        'require_numbers' => (bool) env('SECURITY_PASSWORD_REQUIRE_NUMBERS', true),
        'require_symbols' => (bool) env('SECURITY_PASSWORD_REQUIRE_SYMBOLS', true),
        'check_pwned' => (bool) env('SECURITY_PASSWORD_CHECK_PWNED', true),
    ],

    'uploads' => [
        'encrypt_justificatifs' => (bool) env('SECURITY_ENCRYPT_JUSTIFICATIFS', true),
        'antivirus' => [
            // A12 — Antivirus actif par defaut hors env testing (les tests ne
            // disposent pas de clamscan et n ont pas a scanner les fixtures).
            // L admin peut toujours desactiver via ANTIVIRUS_SCAN_ENABLED=false.
            'enabled' => (bool) env('ANTIVIRUS_SCAN_ENABLED', env('APP_ENV', 'production') !== 'testing'),
            'binary' => env('ANTIVIRUS_BINARY', 'clamscan'),
            'arguments' => array_values(array_filter(array_map(
                static fn (string $value): string => trim($value),
                explode(',', (string) env('ANTIVIRUS_ARGUMENTS', '--no-summary'))
            ))),
            'timeout' => (int) env('ANTIVIRUS_TIMEOUT', 30),
            // A12 — fail_open=false en production (un scanner indisponible
            // bloque l upload), true ailleurs pour ne pas bloquer le DEV si
            // clamscan n est pas installe. La prod doit obligatoirement avoir
            // ClamAV installe avec le service clamd actif.
            'fail_open' => (bool) env('ANTIVIRUS_FAIL_OPEN', env('APP_ENV', 'production') !== 'production'),
        ],
    ],
];
