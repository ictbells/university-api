<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentProgrammeChange extends Model
{
    protected $fillable = [
        'student_id',
        'from_program_id',
        'to_program_id',
        'from_level',
        'to_level',
        'same_college',
        'application_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'from_level' => 'integer',
            'to_level' => 'integer',
            'same_college' => 'boolean',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function fromProgram(): BelongsTo
    {
        return $this->belongsTo(Program::class, 'from_program_id');
    }

    public function toProgram(): BelongsTo
    {
        return $this->belongsTo(Program::class, 'to_program_id');
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
