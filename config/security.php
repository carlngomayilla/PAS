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
            'enabled' => (bool) env('ANTIVIRUS_SCAN_ENABLED', false),
            'binary' => env('ANTIVIRUS_BINARY', 'clamscan'),
            'arguments' => array_values(array_filter(array_map(
                static fn (string $value): string => trim($value),
                explode(',', (string) env('ANTIVIRUS_ARGUMENTS', '--no-summary'))
            ))),
            'timeout' => (int) env('ANTIVIRUS_TIMEOUT', 30),
            'fail_open' => (bool) env('ANTIVIRUS_FAIL_OPEN', false),
        ],
    ],
];
