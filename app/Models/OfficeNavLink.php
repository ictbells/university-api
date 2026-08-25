<?php

namespace App\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class OfficeNavLink extends BaseModel
{
    public const CHAIN_UNIT_HEAD = 'unit_head';

    public const CHAIN_DEPARTMENT_HEAD = 'department_head';

    public const CHAIN_BOTH = 'both';

    public const CHAINS = [
        self::CHAIN_UNIT_HEAD,
        self::CHAIN_DEPARTMENT_HEAD,
        self::CHAIN_BOTH,
    ];

    protected $fillable = [
        'nav_key',
        'require_create',
        'require_update',
        'require_delete',
        'approval_chain',
    ];

    protected $casts = [
        'require_create' => 'boolean',
        'require_update' => 'boolean',
        'require_delete' => 'boolean',
    ];

    public function linkable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return array{key: string, require_create: bool, require_update: bool, require_delete: bool, approval_chain: string}
     */
    public function toConfig(): array
    {
        return [
            'key' => $this->nav_key,
            'require_create' => (bool) $this->require_create,
            'require_update' => (bool) $this->require_update,
            'require_delete' => (bool) $this->require_delete,
            'approval_chain' => in_array($this->approval_chain, self::CHAINS, true)
                ? $this->approval_chain
                : self::CHAIN_BOTH,
        ];
    }
}
