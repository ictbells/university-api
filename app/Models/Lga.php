<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Lga extends Model
{
    protected $table = 'lga';

    protected $primaryKey = 'lga_id';

    public $timestamps = false;

    public $incrementing = true;

    protected $fillable = ['lga_id', 'lga_title', 'state_id'];

    public function state(): BelongsTo
    {
        return $this->belongsTo(StateOfOrigin::class, 'state_id', 'state_id');
    }
}
