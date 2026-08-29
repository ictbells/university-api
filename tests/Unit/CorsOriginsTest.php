<?php

namespace Tests\Unit;

use App\Support\CorsOrigins;
use Tests\TestCase;

class CorsOriginsTest extends TestCase
{
    public function test_it_adds_www_and_strips_path(): void
    {
        $this->assertSame(
            [
                'https://student.cycbankease.com',
                'https://www.student.cycbankease.com',
            ],
            CorsOrigins::fromUrls(['https://student.cycbankease.com/student']),
        );
    }

    public function test_it_does_not_www_localhost_or_ip(): void
    {
        $this->assertSame(
            ['http://localhost:5174'],
            CorsOrigins::originsFor('http://localhost:5174/student'),
        );
        $this->assertSame(
            ['http://192.168.1.20:5174'],
            CorsOrigins::originsFor('http://192.168.1.20:5174'),
        );
    }

    public function test_dev_origins_are_empty_in_production(): void
    {
        $this->assertSame([], CorsOrigins::devOrigins('production'));
        $this->assertSame([], CorsOrigins::localPatterns('production'));
        $this->assertNotEmpty(CorsOrigins::devOrigins('local'));
        $this->assertNotEmpty(CorsOrigins::localPatterns('testing'));
    }
}
