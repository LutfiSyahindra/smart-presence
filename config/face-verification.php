<?php

return [
    'driver' => env('FACE_VERIFICATION_DRIVER', 'fastapi'),

    // Initial value for the database setting; used as fallback before migration.
    'minimum_similarity' => max(1, min(100, (float) env('FACE_SERVICE_MIN_SIMILARITY', 40))),

    'fastapi' => [
        'url' => env('FACE_SERVICE_URL', 'http://127.0.0.1:8001'),
        'token' => env('FACE_SERVICE_TOKEN'),
        'connect_timeout' => (int) env('FACE_SERVICE_CONNECT_TIMEOUT', 3),
        'timeout' => (int) env('FACE_SERVICE_TIMEOUT', 45),
    ],
];
