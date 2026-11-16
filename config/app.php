<?php
/**
 * General application configuration. Values come from .env (see .env.example).
 */
return [
    'name'     => env('APP_NAME', 'MediStock Intelligence'),
    'env'      => env('APP_ENV', 'production'),
    'debug'    => (bool) env('APP_DEBUG', false),
    'url'      => rtrim((string) env('APP_URL', ''), '/'),
    'timezone' => env('APP_TIMEZONE', 'UTC'),
    'key'      => env('APP_KEY', ''),
    'version'  => '1.0.0',
];
