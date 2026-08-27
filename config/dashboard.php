<?php

return [
    'next_pilot' => [
        'enabled' => (bool) env('DASHBOARD_NEXT_PILOT_ENABLED', false),
        'url' => env('DASHBOARD_NEXT_PILOT_URL', '/dashboard-pilot'),
        'health_url' => env(
            'DASHBOARD_NEXT_PILOT_HEALTH_URL',
            'http://127.0.0.1:3000/dashboard-pilot/health',
        ),
    ],

    'charts' => [
        'cache_minutes' => env('DASHBOARD_CHART_CACHE_MINUTES', 10),
    ],
];
