<?php

return [
    'fcm' => [
        'credentials' => env('FCM_CREDENTIALS'),
    ],
    'apns' => [
        'key_id' => env('APNS_KEY_ID'),
        'team_id' => env('APNS_TEAM_ID'),
        'private_key' => env('APNS_PRIVATE_KEY_PATH'),
        'topic' => env('APNS_TOPIC', 'id.rsarsy.presensi'),
        // Use a separate backend configuration for sandbox and production builds.
        'sandbox' => env('APNS_SANDBOX', false),
    ],
];
