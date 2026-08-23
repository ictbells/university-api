<?php

namespace App\Models;

use App\Models\BaseModel;


class IntegrationEndpoint extends BaseModel
{
    protected $fillable = ['name', 'type', 'enabled'];
}
