<?php

use App\Models\Program;
use App\Support\WorkflowCatalog;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        WorkflowCatalog::seed();

        Program::query()
            ->whereNull('workflow_template_id')
            ->get()
            ->each(function (Program $program) {
                $id = WorkflowCatalog::ensureDefaultId($program);
                if ($id) {
                    $program->update(['workflow_template_id' => $id]);
                }
            });
    }

    public function down(): void
    {
        // Default templates stay; programmes keep their assigned workflow.
    }
};
