<?php

/**
 * Generate APP_KEY only when .env has none. An existing key is left unchanged.
 */
$path = dirname(__DIR__).DIRECTORY_SEPARATOR.'.env';
if (! is_file($path)) {
    fwrite(STDERR, ".env is missing. Copy .env.example to .env first.\n");
    exit(1);
}

$contents = file_get_contents($path);
$value = '';
if (is_string($contents) && preg_match('/^APP_KEY=\s*(.*)$/m', $contents, $match)) {
    $value = trim($match[1], " \t\"'");
}

if ($value !== '') {
    fwrite(STDOUT, "APP_KEY already set; leaving it unchanged.\n");
    exit(0);
}

$command = escapeshellarg(PHP_BINARY)
    .' '.escapeshellarg(dirname(__DIR__).DIRECTORY_SEPARATOR.'artisan')
    .' key:generate --ansi';

passthru($command, $code);
exit($code);
