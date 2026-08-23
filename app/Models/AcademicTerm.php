<?php

namespace App\Models;

use App\Models\BaseModel;


class AcademicTerm extends BaseModel
{
    protected $fillable = ['name', 'session_label', 'starts_on', 'ends_on', 'is_current'];

    protected function casts(): array
    {
        return ['is_current' => 'boolean', 'starts_on' => 'date', 'ends_on' => 'date'];
    }
}
