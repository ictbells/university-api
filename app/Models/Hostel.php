<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class Hostel extends BaseModel
{
    public const CATEGORY_UNDERGRADUATE = 'undergraduate';

    public const CATEGORY_JUPEB = 'jupeb';

    public const CATEGORY_POSTGRADUATE = 'postgraduate';

    /**
     * @return list<string>
     */
    public static function categoryKeys(): array
    {
        return [
            self::CATEGORY_UNDERGRADUATE,
            self::CATEGORY_JUPEB,
            self::CATEGORY_POSTGRADUATE,
        ];
    }

    protected $fillable = ['campus_id', 'name', 'gender', 'category', 'is_active', 'due_required', 'due_amount'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'due_required' => 'boolean',
            'due_amount' => 'decimal:2',
        ];
    }

    public function chargesDue(): bool
    {
        return (bool) $this->due_required && (float) $this->due_amount > 0;
    }

    public function blocks(): HasMany
    {
        return $this->hasMany(HostelBlock::class);
    }
}
