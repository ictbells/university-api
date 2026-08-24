<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class PullEnvFromS3CommandTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('LOAD_ENV_FROM_S3');
        putenv('ENV_S3_BUCKET');
        putenv('ENV_S3_KEY');
        putenv('AWS_DEFAULT_REGION');
        unset($_ENV['LOAD_ENV_FROM_S3'], $_ENV['ENV_S3_BUCKET'], $_ENV['ENV_S3_KEY'], $_ENV['AWS_DEFAULT_REGION']);
        parent::tearDown();
    }

    public function test_it_skips_when_the_s3_flag_is_disabled(): void
    {
        $this->artisan('env:pull-s3', ['--skip-if-disabled' => true])
            ->expectsOutputToContain('LOAD_ENV_FROM_S3 is false')
            ->assertSuccessful();
    }

    public function test_it_copies_env_from_s3_when_forced(): void
    {
        Process::fake();

        putenv('ENV_S3_BUCKET=bells-secrets');
        putenv('ENV_S3_KEY=production/api.env');
        putenv('AWS_DEFAULT_REGION=eu-west-1');
        $_ENV['ENV_S3_BUCKET'] = 'bells-secrets';
        $_ENV['ENV_S3_KEY'] = 'production/api.env';

        $this->artisan('env:pull-s3', ['--force' => true])
            ->expectsOutputToContain('s3://bells-secrets/production/api.env')
            ->assertSuccessful();
    }
}
