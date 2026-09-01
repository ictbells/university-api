<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->isolateMatricSequence();
    }

    protected function isolateMatricSequence(): void
    {
        config(['sis.matric_last' => '', 'sis.matric_year' => '']);
        foreach (['MATRIC_LAST', 'MATRIC_YEAR'] as $key) {
            putenv($key.'=');
            $_ENV[$key] = '';
            $_SERVER[$key] = '';
        }
    }
}
