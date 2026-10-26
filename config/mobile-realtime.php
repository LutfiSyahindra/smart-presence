<?php

return [
    'enabled' => env('MOBILE_REALTIME_ENABLED', env('BROADCAST_CONNECTION') === 'reverb'),
    // Empty public endpoint settings use the current page's origin (including HTTPS).
    'host' => env('MOBILE_REALTIME_HOST'),
    'port' => env('MOBILE_REALTIME_PORT'),
    'scheme' => env('MOBILE_REALTIME_SCHEME'),
    'path' => env('MOBILE_REALTIME_PATH', ''),
    // Operational writes must never trigger a refresh -> session/cache write -> refresh loop.
    // All other application tables, including future modules and bulk SQL writes, are tracked.
    'ignored_tables' => [
        'migrations', 'password_reset_tokens', 'sessions', 'cache', 'cache_locks',
        'jobs', 'job_batches', 'failed_jobs', 'personal_access_tokens',
        'web_push_subscriptions', 'mobile_push_devices',
        // Verification status is polled by its owner; final presensi writes still broadcast.
        'attendance_submissions',
    ],
];
