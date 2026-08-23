<?php

namespace App\Models;

use App\Models\BaseModel;


class Immunization extends BaseModel
{
    protected $fillable = ['student_id', 'vaccine', 'given_on'];
}
