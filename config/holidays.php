<?php

return [
    'api_url' => env('HOLIDAY_API_URL', 'https://indonesian-holidays.ahrulsyamil.com'),
    'connect_timeout' => 5,
    'timeout' => 15,
    'auto_sync' => env('HOLIDAY_AUTO_SYNC', true),
];
