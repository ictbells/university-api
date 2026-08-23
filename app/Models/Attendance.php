<?php

namespace App\Models;

use App\Models\BaseModel;


class Attendance extends BaseModel
{
    protected $table = 'attendance';

    protected $fillable = ['course_offering_id', 'student_id', 'attended_on', 'status'];
}
