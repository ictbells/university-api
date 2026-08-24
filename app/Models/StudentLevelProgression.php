<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentLevelProgression extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'student_id',
        'academic_session_id',
        'program_id',
        'from_level',
        'to_level',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'from_level' => 'integer',
            'to_level' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class, 'academic_session_id');
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }
}
