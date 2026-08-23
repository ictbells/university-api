<?php

namespace App\Models;

use App\Models\BaseModel;


class MedicalProfile extends BaseModel
{
    protected $fillable = ['student_id', 'blood_type', 'allergies', 'conditions'];
}
