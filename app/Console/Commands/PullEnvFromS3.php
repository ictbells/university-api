<?php

namespace App\Console\Commands;

use App\Support\EnvFromS3;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

class PullEnvFromS3 extends Command
{
    protected $signature = 'env:pull-s3
                            {--bucket= : S3 bucket (defaults to ENV_S3_BUCKET or AWS_BUCKET)}
                            {--key= : Object key (defaults to ENV_S3_KEY)}
                            {--force : Overwrite an existing .env file}
                            {--skip-if-disabled : No-op unless LOAD_ENV_FROM_S3 is true}';

    protected $description = 'Download the API .env file from S3 when LOAD_ENV_FROM_S3 is enabled';

    public function handle(): int
    {
        if ($this->option('skip-if-disabled') && ! EnvFromS3::enabled()) {
            $this->info('LOAD_ENV_FROM_S3 is false; leaving .env unchanged.');

            return self::SUCCESS;
        }

        $bucket = (string) ($this->option('bucket') ?: EnvFromS3::bucket());
        $keyOption = $this->option('key');
        $uri = EnvFromS3::uri(
            $bucket,
            is_string($keyOption) && $keyOption !== '' ? $keyOption : null,
        );
        $destination = base_path('.env');

        if ($bucket === '') {
            $this->error('ENV_S3_BUCKET (or AWS_BUCKET) and ENV_S3_KEY are required to pull env from S3.');

            return self::FAILURE;
        }

        if (is_file($destination) && ! $this->option('force')) {
            $this->error('.env already exists. Re-run with --force to overwrite.');

            return self::FAILURE;
        }

        $this->info('Pulling env from '.$uri);

        $result = Process::timeout(120)->run([
            'aws', 's3', 'cp', $uri, $destination,
            '--region', EnvFromS3::region(),
            '--only-show-errors',
        ]);

        if ($result->failed()) {
            $this->error($result->errorOutput() !== '' ? $result->errorOutput() : 'aws s3 cp failed.');

            return self::FAILURE;
        }

        $this->info('Wrote '.$destination);

        return self::SUCCESS;
    }
}
