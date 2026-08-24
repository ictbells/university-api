<?php

$defaultOrigins = [
    'http://localhost:5173',
    'http://127.0.0.1:5173',
    'http://localhost:5174',
    'http://127.0.0.1:5174',
    'http://localhost',
    'http://127.0.0.1',
];

$fromEnv = array_values(array_filter(array_map(
    static fn (string $origin): string => rtrim(trim($origin), '/'),
    explode(',', (string) env('CORS_ALLOWED_ORIGINS', '')),
)));

foreach ([env('FRONTEND_URL'), env('STUDENT_URL')] as $url) {
    if (is_string($url) && trim($url) !== '') {
        $fromEnv[] = rtrim(trim($url), '/');
    }
}

$allowedOrigins = array_values(array_unique(array_merge($defaultOrigins, $fromEnv)));

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    'allowed_origins' => $allowedOrigins,
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => ['X-Request-Id'],
    'max_age' => 0,
    'supports_credentials' => true,
];
