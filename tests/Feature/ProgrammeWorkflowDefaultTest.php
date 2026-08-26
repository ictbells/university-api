<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\Program;
use App\Support\WorkflowCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProgrammeWorkflowDefaultTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_programme_without_workflow_assigns_screening_to_offer_path(): void
    {
        $program = Program::query()->create($this->programmeAttrs([
            'study_level' => 'undergraduate',
            'entry_modes' => ['utme'],
        ]));

        $program->load('workflowTemplate.stages');
        $this->assertSame(WorkflowCatalog::UG_STANDARD, $program->workflowTemplate?->code);
        $this->assertSame(
            ['submitted', 'screening', 'verification', 'shortlisting', 'recommended', 'approved', 'offer_issued', 'registration'],
            $program->workflowTemplate->stages->sortBy('sort_order')->pluck('key')->values()->all(),
        );
    }

    public function test_creating_postgraduate_programme_without_workflow_assigns_taught_pg(): void
    {
        $program = Program::query()->create($this->programmeAttrs([
            'study_level' => 'postgraduate',
            'entry_modes' => ['pg'],
            'duration_years' => 2,
            'award_type' => 'M.Sc',
        ]));

        $this->assertSame(
            WorkflowCatalog::PG_TAUGHT,
            $program->fresh('workflowTemplate')->workflowTemplate?->code,
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function programmeAttrs(array $overrides = []): array
    {
        $campus = Campus::query()->create(['name' => 'Main', 'is_active' => true]);
        $faculty = Faculty::query()->create(['campus_id' => $campus->id, 'name' => 'Science']);
        $department = Department::query()->create(['faculty_id' => $faculty->id, 'name' => 'CS']);

        return array_merge([
            'department_id' => $department->id,
            'name' => 'B.Sc CS',
            'code' => 'BSC-CS',
            'award_type' => 'B.Sc',
            'study_level' => 'undergraduate',
            'entry_modes' => ['utme'],
            'duration_years' => 4,
            'is_active' => true,
        ], $overrides);
    }
}
