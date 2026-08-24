<?php

namespace Tests\Unit;

use App\Support\EnvFromS3;
use PHPUnit\Framework\TestCase;

class EnvFromS3Test extends TestCase
{
    public function test_enabled_parses_truthy_and_falsy_values(): void
    {
        $this->assertTrue(EnvFromS3::enabled('true'));
        $this->assertTrue(EnvFromS3::enabled('1'));
        $this->assertTrue(EnvFromS3::enabled('yes'));
        $this->assertFalse(EnvFromS3::enabled('false'));
        $this->assertFalse(EnvFromS3::enabled('0'));
        $this->assertFalse(EnvFromS3::enabled(''));
    }

    public function test_uri_builds_an_s3_object_path(): void
    {
        $this->assertSame('s3://bells-secrets/production/api.env', EnvFromS3::uri('bells-secrets', 'production/api.env'));
        $this->assertSame('s3://bells-secrets/api/.env', EnvFromS3::uri('bells-secrets', '/api/.env'));
    }
}
