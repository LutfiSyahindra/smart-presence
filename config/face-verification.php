<?php

return [
    'driver' => env('FACE_VERIFICATION_DRIVER', 'fastapi'),

    // Keep this minimum in sync with the face service's similarity policy.
    'minimum_similarity' => max(40, (float) env('FACE_SERVICE_MIN_SIMILARITY', 40)),

    'fastapi' => [
        'url' => env('FACE_SERVICE_URL', 'http://127.0.0.1:8001'),
        'token' => env('FACE_SERVICE_TOKEN'),
        'connect_timeout' => (int) env('FACE_SERVICE_CONNECT_TIMEOUT', 3),
        'timeout' => (int) env('FACE_SERVICE_TIMEOUT', 45),
    ],
];
