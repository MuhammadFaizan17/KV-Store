<?php

/**
 * Application Configuration
 * 
 * This file holds configuration values for the Secretlab KVStore application.
 */

return [
    'name' => env('APP_NAME', 'SecretlabKVStore'),
    'env'  => env('APP_ENV', 'production'),
    'debug' => env('APP_DEBUG', false),
    'url'  => env('APP_URL', 'http://localhost:8000'),
    'timezone' => env('APP_TIMEZONE', 'UTC'),
    'locale' => 'en',
    'fallback_locale' => 'en',
];
