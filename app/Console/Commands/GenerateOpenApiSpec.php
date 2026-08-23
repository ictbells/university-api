<?php

namespace App\Console\Commands;

use App\Support\OpenApiGenerator;
use Illuminate\Console\Command;

class GenerateOpenApiSpec extends Command
{
    protected $signature = 'openapi:generate {--output=storage/api-docs/openapi.json : Output file path relative to base path}';

    protected $description = 'Generate OpenAPI JSON from registered API routes';

    public function handle(OpenApiGenerator $generator): int
    {
        $relative = (string) $this->option('output');
        $path = base_path($relative);
        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents(
            $path,
            json_encode($generator->generate(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL,
        );

        $this->info('OpenAPI spec written to '.$relative);

        return self::SUCCESS;
    }
}
