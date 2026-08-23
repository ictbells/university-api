<?php

namespace App\Models;

use App\Models\BaseModel;

class CandidateData extends BaseModel
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'rg_aggr' => 'decimal:2',
            'rg_sub1scor' => 'decimal:2',
            'rg_sub2scor' => 'decimal:2',
            'rg_sub3scor' => 'decimal:2',
            'eng_score' => 'decimal:2',
        ];
    }
}
