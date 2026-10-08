<?php

return [
    'paths' => ['api/*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => array_values(array_filter(explode(',', env(
        'MOBILE_CORS_ALLOWED_ORIGINS',
        'capacitor://localhost,http://localhost,https://localhost,http://localhost:5174',
    )))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => false,
];
