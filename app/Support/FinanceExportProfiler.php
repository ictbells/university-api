<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Controlled timing for student financial-status exports.
 * Logs counts and milliseconds only — never student names, matrics, or amounts.
 */
class FinanceExportProfiler
{
    private bool $enabled;

    private float $startedAt;

    /** @var list<array{stage: string, ms: float, metrics: array<string, int|float|string|bool|null>}> */
    private array $marks = [];

    private float $lastMarkAt;

    /** @var array<string, true> */
    private array $explained = [];

    public function __construct(?bool $enabled = null)
    {
        $this->enabled = $enabled ?? (bool) config('sis.finance_export_profile', false);
        $this->startedAt = microtime(true);
        $this->lastMarkAt = $this->startedAt;
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    /**
     * @param  array<string, int|float|string|bool|null>  $metrics
     */
    public function mark(string $stage, array $metrics = []): void
    {
        if (! $this->enabled) {
            return;
        }

        $now = microtime(true);
        $this->marks[] = [
            'stage' => $stage,
            'ms' => round(($now - $this->lastMarkAt) * 1000, 1),
            'metrics' => $metrics,
        ];
        $this->lastMarkAt = $now;
    }

    /**
     * One-shot EXPLAIN for a builder (MySQL/MariaDB only). Safe metrics only.
     *
     * @param  \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder  $builder
     */
    public function explainOnce(string $label, $builder): void
    {
        if (! $this->enabled || isset($this->explained[$label])) {
            return;
        }
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        $this->explained[$label] = true;
        try {
            $sql = $builder->toSql();
            $bindings = $builder->getBindings();
            $rows = DB::select('EXPLAIN '.$sql, $bindings);
            $this->mark('explain.'.$label, [
                'plan_rows' => count($rows),
                'plan' => collect($rows)->map(fn ($row) => [
                    'id' => $row->id ?? null,
                    'select_type' => $row->select_type ?? null,
                    'table' => $row->table ?? null,
                    'type' => $row->type ?? null,
                    'key' => $row->key ?? null,
                    'rows' => $row->rows ?? null,
                    'Extra' => $row->Extra ?? null,
                ])->all(),
            ]);
        } catch (\Throwable $e) {
            $this->mark('explain.'.$label, [
                'error' => class_basename($e),
            ]);
        }
    }

    public function flush(string $format, int $totalStudents): void
    {
        if (! $this->enabled) {
            return;
        }

        Log::info('finance_export.profile', [
            'format' => $format,
            'student_count' => $totalStudents,
            'csv_chunk' => (int) config('sis.finance_export_csv_chunk', 250),
            'total_ms' => round((microtime(true) - $this->startedAt) * 1000, 1),
            'stages' => $this->marks,
        ]);
    }
}
