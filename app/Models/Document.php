<?php

namespace App\Models;

use App\Models\BaseModel;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Document extends BaseModel
{
    protected $fillable = ['student_id', 'user_id', 'type', 'title', 'path', 'html_body', 'status'];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
