<?php

return [
    'driver' => env('FACE_VERIFICATION_DRIVER', 'fastapi'),

    // DeepFace's `verified` flag can be true at a relatively low confidence.
    // Require both the model decision and this application-level minimum.
    'minimum_similarity' => (float) env('FACE_SERVICE_MIN_SIMILARITY', 70),

    'fastapi' => [
        'url' => env('FACE_SERVICE_URL', 'http://127.0.0.1:8001'),
        'token' => env('FACE_SERVICE_TOKEN'),
        'connect_timeout' => (int) env('FACE_SERVICE_CONNECT_TIMEOUT', 3),
        'timeout' => (int) env('FACE_SERVICE_TIMEOUT', 45),
    ],
];
