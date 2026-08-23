<?php

namespace App\Models;

class AcademicLevel extends BaseModel
{
    protected $fillable = ['name', 'code', 'study_level', 'sort_order', 'is_active'];
}
