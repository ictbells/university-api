<?php

namespace App\Services;

use App\Models\User;
use App\Support\Reports\ReportColumn;
use App\Support\Reports\ReportDataset;
use App\Support\Reports\ReportDatasetCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ReportQueryService
{
    public const MAX_ROWS = 5000;

    public const AGGREGATIONS = ['count', 'sum', 'avg', 'min', 'max'];

    /**
     * @param  array<string, mixed>  $definition
     * @return array{dataset: ReportDataset, definition: array<string, mixed>, headers: list<array{key: string, label: string}>, query: Builder, grouped: bool}
     */
    public function prepare(User $user, array $definition): array
    {
        $datasetKey = (string) ($definition['dataset'] ?? '');
        $dataset = ReportDatasetCatalog::get($datasetKey);
        if (! $dataset) {
            throw new InvalidArgumentException('Unknown report dataset.');
        }
        if (! $dataset->userCanAccess($user)) {
            abort(response()->json([
                'message' => 'You do not have permission for this report dataset.',
                'access_reason' => 'missing_permission',
            ], 403));
        }

        $validated = $this->validate($dataset, $definition);
        $grouped = $validated['group_by'] !== [];
        $query = $this->build($dataset, $validated, $grouped);

        return [
            'dataset' => $dataset,
            'definition' => $validated,
            'headers' => $this->headers($dataset, $validated, $grouped),
            'query' => $query,
            'grouped' => $grouped,
        ];
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    public function run(User $user, array $definition, int $page = 1, int $perPage = 25): array
    {
        $prepared = $this->prepare($user, $definition);
        $page = max(1, $page);
        $perPage = min(100, max(10, $perPage));

        $total = $this->count($prepared['query'], $prepared['grouped']);
        $rows = (clone $prepared['query'])
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        $keys = array_column($prepared['headers'], 'key');

        return [
            'dataset' => $prepared['dataset']->key,
            'columns' => $prepared['headers'],
            'rows' => $rows->map(fn ($row) => $this->presentRow($row, $keys))->values()->all(),
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
                'from' => $total === 0 ? null : (($page - 1) * $perPage) + 1,
                'to' => $total === 0 ? null : min($total, $page * $perPage),
            ],
            'filter_summary' => $this->filterSummary($prepared['dataset'], $prepared['definition']),
        ];
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array{headers: list<array{key: string, label: string}>, rows: list<array<string, string>>, filter_summary: list<string>, title: string, truncated: bool, total: int}
     */
    public function exportRows(User $user, array $definition, string $title = ''): array
    {
        $prepared = $this->prepare($user, $definition);
        $total = $this->count($prepared['query'], $prepared['grouped']);
        $rows = (clone $prepared['query'])->limit(self::MAX_ROWS)->get();
        $keys = array_column($prepared['headers'], 'key');

        return [
            'headers' => $prepared['headers'],
            'rows' => $rows->map(fn ($row) => $this->presentRow($row, $keys))->values()->all(),
            'filter_summary' => $this->filterSummary($prepared['dataset'], $prepared['definition']),
            'title' => $title !== '' ? $title : $prepared['dataset']->label,
            'truncated' => $total > self::MAX_ROWS,
            'total' => min($total, self::MAX_ROWS),
        ];
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    public function validate(ReportDataset $dataset, array $definition): array
    {
        $groupBy = array_values(array_filter(array_map('strval', $definition['group_by'] ?? [])));
        $columns = array_values(array_filter(array_map('strval', $definition['columns'] ?? [])));
        $aggregations = is_array($definition['aggregations'] ?? null) ? $definition['aggregations'] : [];
        $filters = is_array($definition['filters'] ?? null) ? $definition['filters'] : [];
        $sorts = is_array($definition['sorts'] ?? null) ? $definition['sorts'] : [];

        foreach ($groupBy as $key) {
            if (! $dataset->column($key)) {
                throw new InvalidArgumentException('Unknown group field: '.$key);
            }
        }

        $normalizedAggs = [];
        foreach ($aggregations as $aggregation) {
            if (! is_array($aggregation)) {
                continue;
            }
            $fn = strtolower((string) ($aggregation['fn'] ?? ''));
            $field = (string) ($aggregation['field'] ?? '');
            $as = (string) ($aggregation['as'] ?? '');
            if (! in_array($fn, self::AGGREGATIONS, true)) {
                throw new InvalidArgumentException('Unsupported aggregation.');
            }
            if (! preg_match('/^[a-z][a-z0-9_]*$/', $as)) {
                throw new InvalidArgumentException('Invalid aggregation alias.');
            }
            if ($fn !== 'count') {
                $column = $dataset->column($field);
                if (! $column || ! $column->aggregatable) {
                    throw new InvalidArgumentException('Field cannot be aggregated: '.$field);
                }
            } elseif ($field !== '*' && $field !== '' && ! $dataset->column($field) && $field !== 'id') {
                throw new InvalidArgumentException('Unknown aggregation field: '.$field);
            }
            $normalizedAggs[] = ['fn' => $fn, 'field' => $field !== '' ? $field : '*', 'as' => $as];
        }

        if ($groupBy === []) {
            if ($columns === []) {
                $columns = $dataset->defaultColumns;
            }
            foreach ($columns as $key) {
                if (! $dataset->column($key)) {
                    throw new InvalidArgumentException('Unknown column: '.$key);
                }
            }
            if ($columns === []) {
                throw new InvalidArgumentException('Select at least one column.');
            }
        } elseif ($normalizedAggs === []) {
            $normalizedAggs[] = ['fn' => 'count', 'field' => '*', 'as' => 'total'];
        }

        $normalizedFilters = [];
        foreach ($filters as $filter) {
            if (! is_array($filter)) {
                continue;
            }
            $field = (string) ($filter['field'] ?? '');
            $op = (string) ($filter['op'] ?? '');
            $column = $dataset->column($field);
            if (! $column) {
                throw new InvalidArgumentException('Unknown filter field: '.$field);
            }
            if (! in_array($op, $column->operators(), true)) {
                throw new InvalidArgumentException('Unsupported filter operator for '.$field);
            }
            $normalizedFilters[] = [
                'field' => $field,
                'op' => $op,
                'value' => $filter['value'] ?? null,
            ];
        }

        $normalizedSorts = [];
        $sortSource = $sorts !== [] ? $sorts : $dataset->defaultSort;
        $outputKeys = $groupBy === []
            ? $columns
            : [...$groupBy, ...array_column($normalizedAggs, 'as')];
        foreach ($sortSource as $sort) {
            if (! is_array($sort)) {
                continue;
            }
            $field = (string) ($sort['field'] ?? '');
            $dir = strtolower((string) ($sort['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
            if ($groupBy === []) {
                if (! $dataset->column($field)?->sortable) {
                    continue;
                }
            } elseif (! in_array($field, $outputKeys, true)) {
                continue;
            }
            $normalizedSorts[] = ['field' => $field, 'dir' => $dir];
        }

        return [
            'dataset' => $dataset->key,
            'columns' => $columns,
            'filters' => $normalizedFilters,
            'group_by' => $groupBy,
            'aggregations' => $normalizedAggs,
            'sorts' => $normalizedSorts,
        ];
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function build(ReportDataset $dataset, array $definition, bool $grouped): Builder
    {
        $query = $dataset->query();

        foreach ($definition['filters'] as $filter) {
            $this->applyFilter($query, $dataset->column($filter['field']), $filter['op'], $filter['value']);
        }

        if ($grouped) {
            $selects = [];
            $groups = [];
            foreach ($definition['group_by'] as $key) {
                $column = $dataset->column($key);
                $selects[] = $column->sql.' as `'.$key.'`';
                $groups[] = $column->sql;
            }
            foreach ($definition['aggregations'] as $aggregation) {
                $selects[] = $this->aggregationSql($dataset, $aggregation).' as `'.$aggregation['as'].'`';
            }
            $query->select(array_map(fn (string $sql) => DB::raw($sql), $selects));
            $query->groupByRaw(implode(', ', $groups));
        } else {
            $selects = [];
            foreach ($definition['columns'] as $key) {
                $column = $dataset->column($key);
                $selects[] = $column->sql.' as `'.$key.'`';
            }
            $query->select(array_map(fn (string $sql) => DB::raw($sql), $selects));
        }

        foreach ($definition['sorts'] as $sort) {
            if ($grouped && in_array($sort['field'], array_column($definition['aggregations'], 'as'), true)) {
                $query->orderBy($sort['field'], $sort['dir']);

                continue;
            }
            $column = $dataset->column($sort['field']);
            if ($column) {
                $query->orderByRaw($column->sql.' '.$sort['dir']);
            }
        }

        return $query;
    }

    private function aggregationSql(ReportDataset $dataset, array $aggregation): string
    {
        $fn = strtoupper($aggregation['fn']);
        if ($aggregation['fn'] === 'count' && in_array($aggregation['field'], ['*', 'id', ''], true)) {
            return 'COUNT(*)';
        }
        $column = $dataset->column($aggregation['field']);
        if ($aggregation['fn'] === 'count') {
            return 'COUNT('.$column->sql.')';
        }

        return $fn.'('.$column->sql.')';
    }

    private function applyFilter(Builder $query, ReportColumn $column, string $op, mixed $value): void
    {
        $sql = $column->sql;

        match ($op) {
            'eq' => $query->whereRaw($sql.' = ?', [$this->scalar($value)]),
            'neq' => $query->whereRaw($sql.' <> ?', [$this->scalar($value)]),
            'gt' => $query->whereRaw($sql.' > ?', [$this->scalar($value)]),
            'gte' => $query->whereRaw($sql.' >= ?', [$this->scalar($value)]),
            'lt' => $query->whereRaw($sql.' < ?', [$this->scalar($value)]),
            'lte' => $query->whereRaw($sql.' <= ?', [$this->scalar($value)]),
            'contains' => $query->whereRaw($sql.' like ?', ['%'.$this->like($value).'%']),
            'in' => $query->whereRaw($sql.' in ('.$this->placeholders($value).')', $this->list($value)),
            'between' => $this->applyBetween($query, $sql, $value),
            'is_null' => $query->whereRaw($sql.' is null'),
            'is_not_null' => $query->whereRaw($sql.' is not null'),
            default => throw new InvalidArgumentException('Unsupported operator.'),
        };
    }

    private function applyBetween(Builder $query, string $sql, mixed $value): void
    {
        $parts = $this->list($value);
        if (count($parts) < 2) {
            throw new InvalidArgumentException('Between filters need two values.');
        }
        $query->whereRaw($sql.' between ? and ?', [$parts[0], $parts[1]]);
    }

    private function scalar(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_values($value)[0] ?? null;
        }

        return $value;
    }

    /**
     * @return list<mixed>
     */
    private function list(mixed $value): array
    {
        if (is_string($value)) {
            return array_values(array_filter(array_map('trim', explode(',', $value)), fn ($item) => $item !== ''));
        }
        if (! is_array($value)) {
            return [$value];
        }

        return array_values($value);
    }

    private function placeholders(mixed $value): string
    {
        $count = max(1, count($this->list($value)));

        return implode(', ', array_fill(0, $count, '?'));
    }

    private function like(mixed $value): string
    {
        return str_replace(['%', '_'], ['\\%', '\\_'], (string) $this->scalar($value));
    }

    private function count(Builder $query, bool $grouped): int
    {
        $base = (clone $query)->toBase();
        $base->orders = null;
        $base->limit = null;
        $base->offset = null;

        if ($grouped) {
            return (int) DB::query()->fromSub($base, 'report_sub')->count();
        }

        return (int) DB::query()->fromSub($base, 'report_sub')->count();
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return list<array{key: string, label: string}>
     */
    private function headers(ReportDataset $dataset, array $definition, bool $grouped): array
    {
        if (! $grouped) {
            return array_map(function (string $key) use ($dataset) {
                $column = $dataset->column($key);

                return ['key' => $key, 'label' => $column?->label ?? $key];
            }, $definition['columns']);
        }

        $headers = [];
        foreach ($definition['group_by'] as $key) {
            $headers[] = ['key' => $key, 'label' => $dataset->column($key)?->label ?? $key];
        }
        foreach ($definition['aggregations'] as $aggregation) {
            $fieldLabel = $aggregation['field'] === '*'
                ? 'Records'
                : ($dataset->column($aggregation['field'])?->label ?? $aggregation['field']);
            $headers[] = [
                'key' => $aggregation['as'],
                'label' => ucfirst($aggregation['fn']).' of '.$fieldLabel,
            ];
        }

        return $headers;
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, string>
     */
    private function presentRow(mixed $row, array $keys): array
    {
        $array = $row instanceof Model
            ? $row->getAttributes()
            : (array) $row;
        $out = [];
        foreach ($keys as $key) {
            $value = $array[$key] ?? null;
            if ($value instanceof \DateTimeInterface) {
                $out[$key] = $value->format('Y-m-d H:i:s');
            } elseif (is_bool($value)) {
                $out[$key] = $value ? 'Yes' : 'No';
            } elseif ($value === null || $value === '') {
                $out[$key] = '—';
            } else {
                $out[$key] = (string) $value;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return list<string>
     */
    public function filterSummary(ReportDataset $dataset, array $definition): array
    {
        $parts = [];
        foreach ($definition['filters'] as $filter) {
            $label = $dataset->column($filter['field'])?->label ?? $filter['field'];
            $value = is_array($filter['value']) ? implode(', ', $filter['value']) : (string) ($filter['value'] ?? '');
            $parts[] = $label.' '.$filter['op'].($value !== '' ? ' '.$value : '');
        }
        if ($definition['group_by'] !== []) {
            $labels = array_map(fn ($key) => $dataset->column($key)?->label ?? $key, $definition['group_by']);
            $parts[] = 'Grouped by '.implode(', ', $labels);
        }

        return $parts;
    }
}
