<?php

namespace App\Support;

use App\Models\Program;
use App\Models\WorkflowTemplate;
use App\Models\WorkflowTemplateStage;

class WorkflowCatalog
{
    public const UG_STANDARD = 'ug-standard';

    public const UG_TRANSFER = 'ug-transfer';

    public const PG_TAUGHT = 'pg-taught';

    public const PG_RESEARCH = 'pg-research';

    /**
     * @return list<array{code: string, name: string, description: string, stages: list<array<string, mixed>>}>
     */
    public static function definitions(): array
    {
        return [
            [
                'code' => self::UG_STANDARD,
                'name' => 'Undergraduate / JUPEB',
                'description' => 'Screening through offer, then course registration.',
                'stages' => self::ugStages(),
            ],
            [
                'code' => self::UG_TRANSFER,
                'name' => 'Undergraduate transfer',
                'description' => 'Undergraduate path with credit assessment before shortlisting.',
                'stages' => self::ugTransferStages(),
            ],
            [
                'code' => self::PG_TAUGHT,
                'name' => 'Taught postgraduate',
                'description' => 'Shorter PG admissions path without proposal or panel.',
                'stages' => self::pgTaughtStages(),
            ],
            [
                'code' => self::PG_RESEARCH,
                'name' => 'Research postgraduate',
                'description' => 'Full MPhil/PhD-style path including research stages.',
                'stages' => self::pgResearchStages(),
            ],
        ];
    }

    public static function defaultCodeFor(Program $program): string
    {
        $modes = $program->entry_modes ?? [];
        if (in_array('pg', $modes, true) || $program->study_level === 'postgraduate') {
            return $program->is_research_degree ? self::PG_RESEARCH : self::PG_TAUGHT;
        }

        return self::UG_STANDARD;
    }

    public static function seed(): void
    {
        foreach (self::definitions() as $definition) {
            $template = WorkflowTemplate::query()->updateOrCreate(
                ['code' => $definition['code']],
                ['name' => $definition['name'], 'description' => $definition['description']],
            );
            $keep = [];
            foreach ($definition['stages'] as $index => $stage) {
                $row = WorkflowTemplateStage::query()->updateOrCreate(
                    [
                        'workflow_template_id' => $template->id,
                        'key' => $stage['key'],
                    ],
                    [
                        'label' => $stage['label'],
                        'sort_order' => $index,
                        'phase' => $stage['phase'],
                        'subject' => $stage['subject'],
                        'permission_key' => $stage['permission_key'],
                        'is_enabled' => $stage['is_enabled'],
                        'is_wired' => $stage['is_wired'],
                    ],
                );
                $keep[] = $row->id;
            }
            WorkflowTemplateStage::query()
                ->where('workflow_template_id', $template->id)
                ->whereNotIn('id', $keep)
                ->delete();
        }
    }

    public static function idByCode(string $code): ?int
    {
        return WorkflowTemplate::query()->where('code', $code)->value('id');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function ugStages(): array
    {
        return [
            self::stage('submitted', 'Application', 'admission', 'application', 'admissions.view', true, true),
            self::stage('screening', 'Screening', 'admission', 'application', 'admissions.screen', true, true),
            self::stage('verification', 'Verification', 'admission', 'application', 'admissions.verify', true, true),
            self::stage('shortlisting', 'Shortlisting', 'admission', 'application', 'admissions.shortlist', true, true),
            self::stage('recommended', 'Recommendation', 'admission', 'application', 'admissions.recommend', true, true),
            self::stage('approved', 'Approval', 'admission', 'application', 'admissions.approve', true, true),
            self::stage('offer_issued', 'Admission', 'admission', 'application', 'admissions.offer', true, true),
            self::stage('registration', 'Registration', 'enrolment', 'student', 'academic.enrollments.manage', true, true),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function ugTransferStages(): array
    {
        return [
            self::stage('submitted', 'Application', 'admission', 'application', 'admissions.view', true, true),
            self::stage('screening', 'Screening', 'admission', 'application', 'admissions.screen', true, true),
            self::stage('verification', 'Verification', 'admission', 'application', 'admissions.verify', true, true),
            self::stage('credit_assessment', 'Credit assessment', 'admission', 'application', 'admissions.credit_assess', true, true),
            self::stage('shortlisting', 'Shortlisting', 'admission', 'application', 'admissions.shortlist', true, true),
            self::stage('recommended', 'Recommendation', 'admission', 'application', 'admissions.recommend', true, true),
            self::stage('approved', 'Approval', 'admission', 'application', 'admissions.approve', true, true),
            self::stage('offer_issued', 'Admission', 'admission', 'application', 'admissions.offer', true, true),
            self::stage('registration', 'Registration', 'enrolment', 'student', 'academic.enrollments.manage', true, true),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function pgTaughtStages(): array
    {
        return [
            self::stage('submitted', 'Application', 'admission', 'application', 'admissions.view', true, true),
            self::stage('screening', 'Screening', 'admission', 'application', 'admissions.pg.screen', true, true),
            self::stage('recommendation', 'Recommendation', 'admission', 'application', 'admissions.recommend', true, true),
            self::stage('approval', 'Approval', 'admission', 'application', 'admissions.approve', true, true),
            self::stage('admission', 'Admission', 'admission', 'application', 'admissions.offer', true, true),
            self::stage('registration', 'Registration', 'enrolment', 'student', 'academic.enrollments.manage', true, true),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function pgResearchStages(): array
    {
        return [
            self::stage('submitted', 'Application', 'admission', 'application', 'admissions.view', true, true),
            self::stage('screening', 'Screening', 'admission', 'application', 'admissions.pg.screen', true, true),
            self::stage('proposal_review', 'Proposal Review', 'admission', 'application', 'admissions.pg.proposal', true, true),
            self::stage('supervisor', 'Supervisor', 'admission', 'application', 'admissions.pg.supervisor', true, true),
            self::stage('panel', 'Panel', 'admission', 'application', 'admissions.pg.panel', true, true),
            self::stage('recommendation', 'Recommendation', 'admission', 'application', 'admissions.recommend', true, true),
            self::stage('approval', 'Approval', 'admission', 'application', 'admissions.approve', true, true),
            self::stage('admission', 'Admission', 'admission', 'application', 'admissions.offer', true, true),
            self::stage('registration', 'Registration', 'enrolment', 'student', 'academic.enrollments.manage', true, true),
            self::stage('research_progress', 'Research Progress', 'research', 'pg_record', 'pg.manage', true, false),
            self::stage('thesis', 'Thesis/Dissertation', 'research', 'pg_record', 'pg.manage', true, false),
            self::stage('viva', 'Viva', 'research', 'pg_record', 'pg.manage', true, false),
            self::stage('corrections', 'Corrections', 'research', 'pg_record', 'pg.manage', true, false),
            self::stage('final_approval', 'Final Approval', 'research', 'pg_record', 'pg.manage', true, false),
            self::stage('graduation', 'Graduation', 'completion', 'pg_record', 'pg.manage', true, false),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function stage(
        string $key,
        string $label,
        string $phase,
        string $subject,
        string $permission,
        bool $enabled,
        bool $wired,
    ): array {
        return [
            'key' => $key,
            'label' => $label,
            'phase' => $phase,
            'subject' => $subject,
            'permission_key' => $permission,
            'is_enabled' => $enabled,
            'is_wired' => $wired,
        ];
    }
}
