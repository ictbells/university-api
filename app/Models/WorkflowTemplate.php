<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkflowTemplate extends BaseModel
{
    protected $fillable = ['code', 'name', 'description'];

    public function stages(): HasMany
    {
        return $this->hasMany(WorkflowTemplateStage::class)->orderBy('sort_order');
    }

    public function enabledStages(): HasMany
    {
        return $this->stages()->where('is_enabled', true);
    }
}
