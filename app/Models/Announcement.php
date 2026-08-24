<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Announcement extends BaseModel
{
    protected $fillable = ['title', 'body', 'audience', 'created_by', 'published_at'];

    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null;
    }
}
