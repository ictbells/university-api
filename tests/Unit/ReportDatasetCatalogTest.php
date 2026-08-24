<?php

namespace Tests\Unit;

use App\Support\Reports\ReportDatasetCatalog;
use PHPUnit\Framework\TestCase;

class ReportDatasetCatalogTest extends TestCase
{
    public function test_sensitive_fields_are_never_catalogued(): void
    {
        $forbidden = [
            'nin', 'password', 'two_factor_secret', 'notes_internal',
            'payload', 'before_state', 'after_state',
        ];

        foreach (ReportDatasetCatalog::all() as $dataset) {
            $keys = array_map(fn ($column) => $column->key, $dataset->columns);
            $sql = array_map(fn ($column) => strtolower($column->sql), $dataset->columns);
            foreach ($forbidden as $field) {
                $this->assertNotContains($field, $keys, $dataset->key.' exposed '.$field);
                foreach ($sql as $expression) {
                    $this->assertStringNotContainsString('.'.$field, $expression, $dataset->key.' sql exposed '.$field);
                }
            }
        }
    }

    public function test_every_dataset_declares_a_domain_permission(): void
    {
        foreach (ReportDatasetCatalog::all() as $dataset) {
            $this->assertNotEmpty($dataset->permissions, $dataset->key.' missing permission');
            $this->assertNotEmpty($dataset->columns, $dataset->key.' missing columns');
        }
    }
}
