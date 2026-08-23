<?php

namespace App\Models;

use App\Models\BaseModel;

use App\Models\Concerns\HasOfficeNavLinks;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OfficeSubunit extends BaseModel
{
    use HasOfficeNavLinks;
    protected $fillable = ['office_unit_id', 'name', 'code', 'description', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(OfficeUnit::class, 'office_unit_id');
    }
}
