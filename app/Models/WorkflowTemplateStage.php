<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowTemplateStage extends BaseModel
{
    protected $fillable = [
        'workflow_template_id', 'key', 'label', 'sort_order', 'phase', 'subject',
        'permission_key', 'is_enabled', 'is_wired',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'is_wired' => 'boolean',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(WorkflowTemplate::class, 'workflow_template_id');
    }
}
