<?php
/**
 * Security-related settings. Business thresholds (expiry days, etc.) live in the
 * system_settings table so administrators can change them from the UI.
 */
return [
    'session' => [
        'name'           => env('SESSION_NAME', 'medistock_sid'),
        'idle_minutes'   => (int) env('SESSION_IDLE_MINUTES', 30),
        'absolute_hours' => (int) env('SESSION_ABSOLUTE_HOURS', 10),
        'secure_cookie'  => env('SESSION_SECURE_COOKIE', 'auto'),
    ],
    'login' => [
        'max_attempts_per_user' => 5,    // within the window below
        'max_attempts_per_ip'   => 20,
        'window_minutes'        => 15,
        'lock_after_failures'   => 10,   // consecutive failures -> account locked (admin unlock)
    ],
    'password' => [
        'min_length' => 8,
    ],
    'uploads' => [
        'max_bytes'     => 2 * 1024 * 1024,
        'image_mimes'   => ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'],
    ],
];
