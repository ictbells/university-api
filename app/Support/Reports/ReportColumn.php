<?php

namespace App\Support\Reports;

class ReportColumn
{
    /**
     * @param  list<string>|null  $options
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $type,
        public string $sql,
        public bool $sortable = true,
        public bool $aggregatable = false,
        public ?array $options = null,
    ) {}

    /**
     * @param  list<string>|null  $options
     */
    public static function make(
        string $key,
        string $label,
        string $type,
        string $sql,
        bool $aggregatable = false,
        ?array $options = null,
        bool $sortable = true,
    ): self {
        return new self($key, $label, $type, $sql, $sortable, $aggregatable, $options);
    }

    /**
     * @return array<string, mixed>
     */
    public function toSchema(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'type' => $this->type,
            'sortable' => $this->sortable,
            'aggregatable' => $this->aggregatable,
            'operators' => $this->operators(),
            'options' => $this->options,
        ];
    }

    /**
     * @return list<string>
     */
    public function operators(): array
    {
        return match ($this->type) {
            'number' => ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'between', 'is_null', 'is_not_null'],
            'date', 'datetime' => ['eq', 'gte', 'lte', 'between', 'is_null', 'is_not_null'],
            'boolean' => ['eq'],
            'enum' => ['eq', 'neq', 'in'],
            default => ['eq', 'neq', 'contains', 'in', 'is_null', 'is_not_null'],
        };
    }
}
