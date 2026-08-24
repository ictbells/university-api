<?php

namespace App\Services;

use App\Models\Application;
use App\Models\PgRecord;
use App\Models\Program;
use App\Models\Student;
use App\Models\User;
use App\Models\WorkflowRun;
use App\Models\WorkflowTemplate;
use App\Models\WorkflowTemplateStage;
use App\Support\WorkflowCatalog;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class WorkflowEngine
{
    public function templateFor(Program|Application|null $source): ?WorkflowTemplate
    {
        if ($source instanceof Application && $source->entry_mode === 'transfer') {
            return WorkflowTemplate::query()->where('code', WorkflowCatalog::UG_TRANSFER)->with('stages')->first()
                ?: WorkflowTemplate::query()->where('code', WorkflowCatalog::UG_STANDARD)->with('stages')->first();
        }

        $program = $source instanceof Application
            ? ($source->program ?: Program::query()->find($source->program_id))
            : $source;
        if (! $program) {
            return WorkflowTemplate::query()->where('code', WorkflowCatalog::UG_STANDARD)->with('stages')->first();
        }
        $program->loadMissing('workflowTemplate.stages');
        if ($program->workflowTemplate) {
            return $program->workflowTemplate;
        }
        $code = WorkflowCatalog::defaultCodeFor($program);

        return WorkflowTemplate::query()->where('code', $code)->with('stages')->first();
    }

    /**
     * @return Collection<int, WorkflowTemplateStage>
     */
    public function admissionStages(Program|Application|null $source): Collection
    {
        $template = $this->templateFor($source);
        if (! $template) {
            return collect();
        }

        return $template->stages
            ->where('phase', 'admission')
            ->where('is_enabled', true)
            ->values();
    }

    public function nextAdmissionStage(Application $application): ?WorkflowTemplateStage
    {
        $stages = $this->admissionStages($application);
        $current = $application->stage;
        $currentIndex = $stages->search(fn (WorkflowTemplateStage $stage) => $stage->key === $current);
        if ($currentIndex === false) {
            return $stages->first(fn (WorkflowTemplateStage $stage) => $stage->is_wired && $stage->key !== 'submitted');
        }

        return $stages->slice($currentIndex + 1)->first(fn (WorkflowTemplateStage $stage) => $stage->is_wired);
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(Application $application): array
    {
        $template = $this->templateFor($application);
        $stages = $this->admissionStages($application);
        $next = $this->nextAdmissionStage($application);

        return [
            'template_id' => $template?->id,
            'template_code' => $template?->code,
            'template_name' => $template?->name,
            'current_stage' => $application->stage,
            'next_stage' => $next?->key,
            'next_label' => $next?->label,
            'next_permission' => $next?->permission_key,
            'stages' => $stages->map(fn (WorkflowTemplateStage $stage) => [
                'key' => $stage->key,
                'label' => $stage->label,
                'phase' => $stage->phase,
                'permission_key' => $stage->permission_key,
                'is_wired' => $stage->is_wired,
                'is_enabled' => $stage->is_enabled,
            ])->values()->all(),
        ];
    }

    public function ensureAdmissionRun(Application $application): WorkflowRun
    {
        $template = $this->templateFor($application);
        abort_unless($template, 422, 'No workflow template is assigned to this programme.');

        $run = WorkflowRun::query()
            ->where('subject_type', Application::class)
            ->where('subject_id', $application->id)
            ->where('phase', 'admission')
            ->first();

        if ($run) {
            return $run;
        }

        return WorkflowRun::query()->create([
            'workflow_template_id' => $template->id,
            'subject_type' => Application::class,
            'subject_id' => $application->id,
            'phase' => 'admission',
            'current_stage_key' => $application->stage ?: 'submitted',
        ]);
    }

    public function advanceApplication(Application $application, string $to, User $actor, ?string $decision = null, ?string $reason = null): Application
    {
        if ($decision === 'rejected') {
            $to = 'rejected';
        }

        $run = $this->ensureAdmissionRun($application);
        $before = $application->stage;

        if ($to !== 'rejected') {
            $next = $this->nextAdmissionStage($application);
            abort_unless($next, 422, 'This file cannot be advanced further in admissions.');
            if (! $next->is_wired) {
                throw ValidationException::withMessages([
                    'to_stage' => ['This stage is not wired yet.'],
                ]);
            }
            if ($to !== $next->key) {
                throw ValidationException::withMessages([
                    'to_stage' => ['Invalid stage transition.'],
                ]);
            }
            if ($application->entry_mode === 'transfer' && $to === 'shortlisting' && ! $application->transferAssessmentComplete()) {
                throw ValidationException::withMessages([
                    'to_stage' => ['Complete credit transfer assessment (accept or accept with conditions, and set the approved entry level) before shortlisting.'],
                ]);
            }
            $permission = $next->permission_key ?: 'admissions.view';
            if (! $actor->hasPermission($permission)) {
                abort(403, 'You cannot move the file to this stage.');
            }
        }

        $run->transitions()->create([
            'actor_id' => $actor->id,
            'from_stage' => $before,
            'to_stage' => $to,
            'decision' => $decision ?? 'advanced',
            'reason' => $reason,
        ]);
        $run->update(['current_stage_key' => $to]);

        $application->reviews()->create([
            'reviewer_id' => $actor->id,
            'from_stage' => $before,
            'to_stage' => $to,
            'decision' => $decision ?? 'advanced',
            'reason' => $reason,
        ]);
        $application->update(['stage' => $to]);

        return $application->fresh();
    }

    public function startEnrolment(Student $student, Application $application): WorkflowRun
    {
        $template = $this->templateFor($application);
        abort_unless($template, 422, 'No workflow template is assigned to this programme.');

        $existing = WorkflowRun::query()
            ->where('subject_type', Student::class)
            ->where('subject_id', $student->id)
            ->where('phase', 'enrolment')
            ->first();
        if ($existing) {
            return $existing;
        }

        $stage = $template->stages->first(fn (WorkflowTemplateStage $row) => $row->phase === 'enrolment' && $row->is_enabled);

        return WorkflowRun::query()->create([
            'workflow_template_id' => $template->id,
            'subject_type' => Student::class,
            'subject_id' => $student->id,
            'phase' => 'enrolment',
            'current_stage_key' => $stage?->key ?? 'registration',
        ]);
    }

    public function startResearch(PgRecord $record, Application $application): ?WorkflowRun
    {
        $template = $this->templateFor($application);
        if (! $template) {
            return null;
        }
        $stage = $template->stages->first(fn (WorkflowTemplateStage $row) => $row->phase === 'research' && $row->is_enabled);
        if (! $stage) {
            return null;
        }

        $existing = WorkflowRun::query()
            ->where('subject_type', PgRecord::class)
            ->where('subject_id', $record->id)
            ->where('phase', 'research')
            ->first();
        if ($existing) {
            return $existing;
        }

        return WorkflowRun::query()->create([
            'workflow_template_id' => $template->id,
            'subject_type' => PgRecord::class,
            'subject_id' => $record->id,
            'phase' => 'research',
            'current_stage_key' => $stage->key,
        ]);
    }

    public function completeEnrolmentIfRegistered(Student $student, string $rosterStatus): void
    {
        if ($rosterStatus !== 'registered') {
            return;
        }
        $run = WorkflowRun::query()
            ->where('subject_type', Student::class)
            ->where('subject_id', $student->id)
            ->where('phase', 'enrolment')
            ->whereNull('completed_at')
            ->first();
        if (! $run) {
            return;
        }
        $run->update([
            'current_stage_key' => 'registration',
            'completed_at' => now(),
        ]);
        $run->transitions()->create([
            'from_stage' => 'registration',
            'to_stage' => 'registration',
            'decision' => 'completed',
            'reason' => 'Course registration roster complete for the current term.',
        ]);
    }

    public function issuesOffer(string $stage): bool
    {
        return in_array($stage, ['offer_issued', 'admission'], true);
    }
}
