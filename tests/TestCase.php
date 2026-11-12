<?php

namespace Tests;

use App\Models\Program;
use App\Services\InvoiceService;
use App\Services\WorkflowEngine;
use App\Support\ProgrammeFeeResolver;
use App\Support\ReceiptPayer;
use App\Support\StudentAcademicLevel;
use App\Support\TuitionProgress;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ProgrammeFeeResolver::flushCache();
        StudentAcademicLevel::flushCache();
        ReceiptPayer::flushCache();
        InvoiceService::flushCache();
        WorkflowEngine::flushCache();
        Program::flushCache();
        TuitionProgress::flushCache();
        $this->isolateMatricSequence();
    }

    protected function isolateMatricSequence(): void
    {
        config([
            'sis.matric_last' => '',
            'sis.matric_year' => '',
            'sis.pg_matric_last' => '',
            'sis.pg_matric_year' => '',
            'sis.pg_matric_digits' => 0,
        ]);
        foreach (['MATRIC_LAST', 'MATRIC_YEAR', 'PG_MATRIC_LAST', 'PG_MATRIC_YEAR', 'PG_MATRIC_DIGITS'] as $key) {
            putenv($key.'=');
            $_ENV[$key] = '';
            $_SERVER[$key] = '';
        }
    }
}
