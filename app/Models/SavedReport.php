<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SavedReport extends BaseModel
{
    protected $fillable = [
        'name',
        'description',
        'dataset_key',
        'definition',
        'visibility',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'definition' => 'array',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isPrivate(): bool
    {
        return $this->visibility !== 'shared';
    }

    public function visibleTo(User $user): bool
    {
        if ($this->created_by === $user->id) {
            return true;
        }

        return $this->visibility === 'shared';
    }

    public function writableBy(User $user): bool
    {
        if (! $user->hasPermission('reports.manage')) {
            return false;
        }

        return $this->created_by === $user->id || $user->hasPermission('reports.manage');
    }
}
