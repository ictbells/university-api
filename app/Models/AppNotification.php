<?php

namespace App\Models;

use App\Models\BaseModel;


class AppNotification extends BaseModel
{
    protected $table = 'notifications';

    protected $fillable = ['user_id', 'type', 'title', 'body', 'module', 'related_id', 'read_at'];

    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }
}
