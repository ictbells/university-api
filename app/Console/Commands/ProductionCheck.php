<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

class ProductionCheck extends Command
{
    protected $signature = 'production:check {--force : Run even when APP_ENV is not production}';

    protected $description = 'Fail if production runtime config is unsafe (debug, demo payments, missing keys)';

    public function handle(): int
    {
        if (! $this->option('force') && ! $this->laravel->environment('production')) {
            $this->info('Skipping production checks (APP_ENV='.$this->laravel->environment().').');

            return self::SUCCESS;
        }

        $failures = [];
        $warnings = [];

        if (filter_var(config('app.debug'), FILTER_VALIDATE_BOOLEAN)) {
            $failures[] = 'APP_DEBUG must be false.';
        }

        if (! config('app.key')) {
            $failures[] = 'APP_KEY is missing.';
        }

        $appUrl = (string) config('app.url');
        if ($appUrl === '' || ! Str::startsWith($appUrl, 'https://')) {
            $failures[] = 'APP_URL must be an https:// URL.';
        }

        foreach (['frontend_url' => 'FRONTEND_URL', 'student_url' => 'STUDENT_URL'] as $key => $label) {
            $url = (string) config('app.'.$key);
            if ($url === '' || ! preg_match('#^https?://#i', $url)) {
                $failures[] = $label.' must be an absolute URL.';
            } elseif (! Str::startsWith($url, 'https://')) {
                $failures[] = $label.' must use https://.';
            }
        }

        if (! filter_var(config('session.secure'), FILTER_VALIDATE_BOOLEAN)) {
            $failures[] = 'SESSION_SECURE_COOKIE must be true.';
        }

        if (filter_var(config('services.paystack.allow_demo_fulfill'), FILTER_VALIDATE_BOOLEAN)) {
            $failures[] = 'PAYSTACK_ALLOW_DEMO_FULFILL must be false.';
        }

        if (filter_var(config('services.prembly.allow_demo'), FILTER_VALIDATE_BOOLEAN)) {
            $failures[] = 'PREMBLY_ALLOW_DEMO must be false.';
        }

        if (! config('services.paystack.secret') || ! config('services.paystack.public')) {
            $failures[] = 'PAYSTACK_SECRET_KEY and PAYSTACK_PUBLIC_KEY must be set.';
        }

        $paystackSecret = (string) config('services.paystack.secret');
        if (str_starts_with($paystackSecret, 'sk_test_')) {
            $warnings[] = 'PAYSTACK_SECRET_KEY is a test key. Use live keys for the public university site.';
        }

        if (! config('services.prembly.key') || ! config('services.prembly.app_id')) {
            $failures[] = 'PREMBLY_API_KEY and PREMBLY_APP_ID must be set.';
        }

        if (! config('services.paystack.webhook_secret')) {
            $warnings[] = 'PAYSTACK_WEBHOOK_SECRET is empty — webhook authenticity cannot be verified.';
        }

        $stateful = collect(config('sanctum.stateful', []))
            ->map(fn ($host) => strtolower(trim((string) $host)))
            ->filter()
            ->all();

        foreach ($stateful as $host) {
            $name = strtolower(explode(':', $host)[0]);
            if (in_array($name, ['localhost', '127.0.0.1', '::1'], true)) {
                $failures[] = 'SANCTUM_STATEFUL_DOMAINS must not include localhost in production.';
                break;
            }
        }

        $frontHost = parse_url((string) config('app.frontend_url'), PHP_URL_HOST);
        if (is_string($frontHost) && $frontHost !== '') {
            $frontHost = strtolower($frontHost);
            $found = false;
            foreach ($stateful as $host) {
                if (strtolower(explode(':', $host)[0]) === $frontHost) {
                    $found = true;
                    break;
                }
            }
            if (! $found) {
                $failures[] = 'SANCTUM_STATEFUL_DOMAINS must include the FRONTEND_URL host ('.$frontHost.').';
            }
        }

        if (config('mail.default') === 'log') {
            $warnings[] = 'MAIL_MAILER=log — emails will not be delivered.';
        }

        if (config('filesystems.default') === 'local') {
            $warnings[] = 'FILESYSTEM_DISK=local — uploads will not survive instance replacement. Use s3.';
        }

        $logLevel = strtolower((string) config('logging.channels.single.level', 'debug'));
        if ($logLevel === 'debug') {
            $warnings[] = 'LOG_LEVEL=debug — use info or warning in production.';
        }

        foreach ($warnings as $warning) {
            $this->warn($warning);
        }

        foreach ($failures as $failure) {
            $this->error($failure);
        }

        if ($failures !== []) {
            $this->error('Production check failed.');

            return self::FAILURE;
        }

        $this->info('Production check passed.');

        return self::SUCCESS;
    }
}
