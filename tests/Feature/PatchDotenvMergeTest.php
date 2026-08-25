<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class PatchDotenvMergeTest extends TestCase
{
    public function test_merge_keeps_app_key_and_db_password(): void
    {
        $dir = sys_get_temp_dir().'/bells-env-'.uniqid();
        mkdir($dir, 0700, true);
        $dest = $dir.'/app.env';
        $overlay = $dir.'/overlay.env';

        file_put_contents($dest, implode("\n", [
            'APP_KEY=base64:from-secrets',
            'DB_PASSWORD=rds-secret',
            'PREMBLY_API_KEY=',
            'PREMBLY_APP_ID=',
        ])."\n");
        file_put_contents($overlay, implode("\n", [
            'APP_KEY=should-not-win',
            'DB_PASSWORD=should-not-win',
            'PREMBLY_API_KEY=live_from_s3',
            'PREMBLY_APP_ID=app_from_s3',
            'PREMBLY_ALLOW_DEMO=false',
        ])."\n");

        $script = base_path('scripts/patch-dotenv.py');
        $python = collect(['python3', 'python'])->first(fn ($bin) => Process::run([$bin, '--version'])->successful()) ?: 'python';
        $result = Process::run([$python, $script, $dest, '--merge-from', $overlay, '--keep-infra']);
        $this->assertTrue($result->successful(), $result->errorOutput().$result->output());

        $merged = str_replace("\r", '', (string) file_get_contents($dest));
        $this->assertStringContainsString('APP_KEY=base64:from-secrets', $merged);
        $this->assertStringContainsString('DB_PASSWORD=rds-secret', $merged);
        $this->assertStringContainsString('PREMBLY_API_KEY="live_from_s3"', $merged);
        $this->assertStringContainsString('PREMBLY_APP_ID="app_from_s3"', $merged);
        $this->assertStringContainsString('PREMBLY_ALLOW_DEMO="false"', $merged);
        $this->assertStringNotContainsString('should-not-win', $merged);

        array_map('unlink', glob($dir.'/*') ?: []);
        rmdir($dir);
    }
}
