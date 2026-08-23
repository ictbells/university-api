<?php

namespace App\Models;

use App\Models\BaseModel;


class WebhookLog extends BaseModel
{
    protected $fillable = ['provider', 'event', 'payload', 'status'];

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }
}
