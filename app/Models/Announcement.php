<?php

namespace App\Models;

use App\Models\BaseModel;


class Announcement extends BaseModel
{
    protected $fillable = ['title', 'body', 'audience', 'created_by', 'published_at'];

    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }
}
