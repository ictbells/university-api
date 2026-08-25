<?php

namespace Tests\Unit;

use App\Support\StudentPortalAuth;
use Tests\TestCase;

class StudentPortalAuthTest extends TestCase
{
    public function test_normalize_login_strips_unicode_spaces(): void
    {
        $this->assertSame(
            'APP/2026/00001',
            StudentPortalAuth::normalizeLogin("app/\u{00A0}2026/00001"),
        );
    }
}
