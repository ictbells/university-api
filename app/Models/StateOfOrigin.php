<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StateOfOrigin extends Model
{
    protected $table = 'stateoforigin';

    protected $primaryKey = 'state_id';

    public $timestamps = false;

    public $incrementing = true;

    protected $fillable = ['state_id', 'state_title'];

    public function lgas(): HasMany
    {
        return $this->hasMany(Lga::class, 'state_id', 'state_id');
    }
}
