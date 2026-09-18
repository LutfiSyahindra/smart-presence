<?php

$trustedNetworks = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('ATTENDANCE_TRUSTED_NETWORKS', '')),
)));

return [
    'location' => [
        // A short-lived, session-bound challenge prevents replaying an old GPS payload.
        'proof_ttl_seconds' => (int) env('ATTENDANCE_LOCATION_PROOF_TTL', 600),
        'sample_freshness_seconds' => (int) env('ATTENDANCE_LOCATION_SAMPLE_FRESHNESS', 300),
        'min_samples' => (int) env('ATTENDANCE_LOCATION_MIN_SAMPLES', 3),
        'collection_seconds' => (int) env('ATTENDANCE_LOCATION_COLLECTION_SECONDS', 3),
        'max_accuracy_meters' => (float) env('ATTENDANCE_LOCATION_MAX_ACCURACY', 100),
        'max_speed_meters_per_second' => (float) env('ATTENDANCE_LOCATION_MAX_SPEED', 80),

        // Turn this on in production and list the hospital IP/CIDR ranges as seen by Laravel.
        // Example: ATTENDANCE_TRUSTED_NETWORKS=203.0.113.10/32,2001:db8:1234::/48
        'require_trusted_network' => (bool) env('ATTENDANCE_REQUIRE_TRUSTED_NETWORK', false),
        'trusted_networks' => $trustedNetworks,
    ],
];
