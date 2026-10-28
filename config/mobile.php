<?php

return [
    // Leave empty to use public/downloads/arsy-presensi.apk when available.
    'android_apk_url' => env('MOBILE_ANDROID_APK_URL'),

    'android_package' => 'id.rsarsy.presensi',
    // Public certificate fingerprint of the existing production APK, not a private key.
    'android_signing_sha256' => env('MOBILE_ANDROID_SIGNING_SHA256', 'bd9e79f2a94f0eb32d7d5c5a430e5ec8b5e849f55d3e15b6d26689586db1b87d'),
    'apk_max_kb' => 102400,
    'aapt_path' => env('MOBILE_AAPT_PATH', PHP_OS_FAMILY === 'Windows'
        ? base_path('apps/absensi-mobile/.tooling/android-sdk/build-tools/35.0.0/aapt.exe') : '/usr/bin/aapt'),
    'apksigner_path' => env('MOBILE_APKSIGNER_PATH', '/usr/bin/apksigner'),
    'apksigner_jar' => env('MOBILE_APKSIGNER_JAR', PHP_OS_FAMILY === 'Windows'
        ? base_path('apps/absensi-mobile/.tooling/android-sdk/build-tools/35.0.0/lib/apksigner.jar') : null),
    'java_path' => env('MOBILE_JAVA_PATH', PHP_OS_FAMILY === 'Windows'
        ? base_path('apps/absensi-mobile/.tooling/jdk/jbr-21.0.11/bin/java.exe') : '/usr/bin/java'),
];
