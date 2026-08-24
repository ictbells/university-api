<?php

namespace App\Support\Reports;

use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Builder;

class ReportDataset
{
    /**
     * @param  list<string>  $permissions
     * @param  list<ReportColumn>  $columns
     * @param  list<string>  $defaultColumns
     * @param  list<array{field: string, dir: string}>  $defaultSort
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $category,
        public string $description,
        public array $permissions,
        public array $columns,
        public Closure $query,
        public string $countColumn,
        public array $defaultColumns = [],
        public array $defaultSort = [],
    ) {}

    public function column(string $key): ?ReportColumn
    {
        foreach ($this->columns as $column) {
            if ($column->key === $key) {
                return $column;
            }
        }

        return null;
    }

    public function userCanAccess(User $user): bool
    {
        foreach ($this->permissions as $permission) {
            if ($user->hasPermission($permission)) {
                return true;
            }
        }

        return false;
    }

    public function query(): Builder
    {
        return ($this->query)();
    }

    /**
     * @return array<string, mixed>
     */
    public function toSchema(): array
    {
        $columns = array_map(fn (ReportColumn $column) => $column->toSchema(), $this->columns);

        return [
            'key' => $this->key,
            'label' => $this->label,
            'category' => $this->category,
            'description' => $this->description,
            'permissions' => $this->permissions,
            'columns' => $columns,
            'default_columns' => $this->defaultColumns !== []
                ? $this->defaultColumns
                : array_slice(array_column($columns, 'key'), 0, 8),
            'default_sort' => $this->defaultSort,
        ];
    }
}
