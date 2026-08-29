<?php

use App\Support\CorsOrigins;

$defaultOrigins = CorsOrigins::devOrigins();

$fromEnv = array_values(array_filter(array_map(
    static fn (string $origin): string => rtrim(trim($origin), '/'),
    explode(',', (string) env('CORS_ALLOWED_ORIGINS', '')),
)));

$allowedOrigins = array_values(array_unique(array_merge(
    $defaultOrigins,
    CorsOrigins::fromUrls(array_merge($fromEnv, [env('FRONTEND_URL'), env('STUDENT_URL')])),
)));

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie', 'api/sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    'allowed_origins' => $allowedOrigins,
    'allowed_origins_patterns' => CorsOrigins::localPatterns(),
    'allowed_headers' => ['*'],
    'exposed_headers' => ['X-Request-Id'],
    'max_age' => 0,
    'supports_credentials' => true,
];
