<?php

namespace Tests\Feature;

use App\Mail\RefereeInviteMail;
use App\Models\AcademicSession;
use App\Models\AcademicTerm;
use App\Models\Application;
use App\Models\Campus;
use App\Models\Course;
use App\Models\CourseOffering;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\Intake;
use App\Models\Invoice;
use App\Models\OfficeDepartment;
use App\Models\OlevelSubject;
use App\Models\Permission;
use App\Models\Program;
use App\Models\Role;
use App\Models\Staff;
use App\Models\Student;
use App\Models\User;
use App\Models\WorkflowRun;
use App\Models\WorkflowTemplateStage;
use App\Services\WorkflowEngine;
use App\Support\PermissionCatalog;
use App\Support\WorkflowCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PgFormWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Program $taught;

    private Program $research;

    private Program $ug;

    private Intake $pgIntake;

    private Intake $ugIntake;

    private AcademicTerm $term;

    private OlevelSubject $subject;

    private Staff $supervisor;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (PermissionCatalog::all() as $perm) {
            Permission::query()->updateOrCreate(['key' => $perm['key']], $perm);
        }
        WorkflowCatalog::seed();

        $campus = Campus::query()->create(['name' => 'Main', 'is_active' => true]);
        $faculty = Faculty::query()->create(['campus_id' => $campus->id, 'name' => 'Science']);
        $department = Department::query()->create(['faculty_id' => $faculty->id, 'name' => 'Computer Science']);

        $session = AcademicSession::query()->create(['label' => '2025/2026']);
        $this->term = AcademicTerm::query()->create([
            'academic_session_id' => $session->id,
            'name' => 'First',
            'session_label' => '2025/2026',
            'is_current' => true,
            'normal_registration_closes_at' => now()->addDays(10),
            'late_registration_closes_at' => now()->addDays(20),
        ]);

        $this->taught = Program::query()->create([
            'department_id' => $department->id,
            'name' => 'M.Sc Computer Science',
            'code' => 'MSC-CS',
            'award_type' => 'M.Sc',
            'study_level' => 'postgraduate',
            'entry_modes' => ['pg'],
            'duration_years' => 2,
            'is_active' => true,
            'is_research_degree' => false,
            'eligibility' => ['min_classification' => 'second_lower', 'min_referees' => 2],
            'workflow_template_id' => WorkflowCatalog::idByCode(WorkflowCatalog::PG_TAUGHT),
        ]);
        $this->research = Program::query()->create([
            'department_id' => $department->id,
            'name' => 'MPhil Computer Science',
            'code' => 'MPHIL-CS',
            'award_type' => 'MPhil',
            'study_level' => 'postgraduate',
            'entry_modes' => ['pg'],
            'duration_years' => 3,
            'is_active' => true,
            'is_research_degree' => true,
            'eligibility' => ['min_classification' => 'second_upper'],
            'workflow_template_id' => WorkflowCatalog::idByCode(WorkflowCatalog::PG_RESEARCH),
        ]);
        $this->ug = Program::query()->create([
            'department_id' => $department->id,
            'name' => 'B.Sc Computer Science',
            'code' => 'BSC-CS',
            'award_type' => 'B.Sc',
            'study_level' => 'undergraduate',
            'entry_modes' => ['utme'],
            'duration_years' => 4,
            'is_active' => true,
            'workflow_template_id' => WorkflowCatalog::idByCode(WorkflowCatalog::UG_STANDARD),
        ]);

        $this->pgIntake = Intake::query()->create([
            'academic_term_id' => $this->term->id,
            'name' => 'PG 2025',
            'entry_mode' => 'pg',
            'is_open' => true,
            'application_fee_amount' => 10000,
        ]);
        $this->ugIntake = Intake::query()->create([
            'academic_term_id' => $this->term->id,
            'name' => 'UTME 2025',
            'entry_mode' => 'utme',
            'is_open' => true,
            'application_fee_amount' => 5000,
        ]);

        $this->subject = OlevelSubject::query()->create(['name' => 'English Language', 'code' => 'ENG', 'is_active' => true]);

        $supervisorUser = User::factory()->create(['name' => 'Dr Supervisor']);
        $this->supervisor = Staff::query()->create([
            'user_id' => $supervisorUser->id,
            'department_id' => $department->id,
            'staff_number' => 'SUP-1',
            'title' => 'Dr',
        ]);
    }

    public function test_pg_olevel_is_still_required_and_background_needs_a_degree(): void
    {
        $application = $this->pgApplication();
        Sanctum::actingAs($application->user);

        $this->postJson("/api/applications/{$application->id}/steps", [
            'step_key' => 'academic_qualifications',
            'payload' => ['other_qualifications' => 'none'],
        ])->assertStatus(422);

        $this->postJson("/api/applications/{$application->id}/steps", [
            'step_key' => 'academic_qualifications',
            'payload' => $this->olevelPayload(),
        ])->assertOk();

        $this->postJson("/api/applications/{$application->id}/steps", [
            'step_key' => 'pg_background',
            'payload' => ['prior_degrees' => [], 'nysc_status' => 'completed'],
        ])->assertStatus(422);

        $this->postJson("/api/applications/{$application->id}/steps", [
            'step_key' => 'pg_background',
            'payload' => $this->backgroundPayload('third'),
        ])->assertOk();
    }

    public function test_nysc_certificate_number_cannot_exceed_twelve_characters(): void
    {
        $application = $this->pgApplication();
        Sanctum::actingAs($application->user);

        $this->postJson("/api/applications/{$application->id}/steps", [
            'step_key' => 'pg_background',
            'payload' => array_merge($this->backgroundPayload('second_lower'), [
                'nysc_number' => '1234567890123',
                'nysc_year' => '2021',
            ]),
        ])->assertStatus(422)->assertJsonValidationErrors('payload.nysc_number');

        $this->postJson("/api/applications/{$application->id}/steps", [
            'step_key' => 'pg_background',
            'payload' => array_merge($this->backgroundPayload('second_lower'), [
                'nysc_number' => '123456789012',
                'nysc_year' => '2021',
            ]),
        ])->assertOk();
    }

    public function test_research_programme_requires_supervisor_taught_does_not(): void
    {
        $application = $this->pgApplication($this->taught);
        Sanctum::actingAs($application->user);
        $this->saveProgramme($application, $this->taught);

        $this->postJson("/api/applications/{$application->id}/steps", [
            'step_key' => 'pg_research',
            'payload' => [
                'statement_of_purpose' => 'I want to study computing at postgraduate level.',
            ],
        ])->assertOk();

        $researchApp = $this->pgApplication($this->research);
        Sanctum::actingAs($researchApp->user);
        $this->saveProgramme($researchApp, $this->research);
        $this->postJson("/api/applications/{$researchApp->id}/steps", [
            'step_key' => 'pg_research',
            'payload' => [
                'research_interest' => 'AI',
                'proposed_area' => 'Machine learning',
                'statement_of_purpose' => 'I want to research machine learning.',
            ],
        ])->assertStatus(422);

        $this->postJson("/api/applications/{$researchApp->id}/steps", [
            'step_key' => 'pg_research',
            'payload' => [
                'research_interest' => 'AI',
                'proposed_area' => 'Machine learning',
                'statement_of_purpose' => 'I want to research machine learning.',
                'supervisor_preferences' => [$this->supervisor->id],
            ],
        ])->assertOk();
    }

    public function test_research_interest_over_default_word_limit_is_rejected(): void
    {
        $application = $this->pgApplication($this->taught);
        Sanctum::actingAs($application->user);
        $this->saveProgramme($application, $this->taught);

        $this->getJson("/api/applications/{$application->id}")
            ->assertOk()
            ->assertJsonPath('pg_word_limits.pg_research_interest_max_words', 150)
            ->assertJsonPath('pg_word_limits.pg_statement_of_purpose_max_words', 500);

        $this->postJson("/api/applications/{$application->id}/steps", [
            'step_key' => 'pg_research',
            'payload' => [
                'research_interest' => implode(' ', array_fill(0, 151, 'word')),
                'statement_of_purpose' => 'I want to study computing at postgraduate level.',
            ],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['payload.research_interest']);

        $this->postJson("/api/applications/{$application->id}/steps", [
            'step_key' => 'pg_research',
            'payload' => [
                'research_interest' => implode(' ', array_fill(0, 150, 'word')),
                'statement_of_purpose' => 'I want to study computing at postgraduate level.',
            ],
        ])->assertOk();
    }

    public function test_pg_cannot_submit_without_olevel_document(): void
    {
        $application = $this->readyPgApplication($this->taught, 'second_lower', 'not_applicable');
        $application->documents()->where('doc_type', 'olevel_first_sitting')->delete();
        Sanctum::actingAs($application->user);

        $this->postJson("/api/applications/{$application->id}/submit", [
            'submission_notice_accepted' => true,
        ])
            ->assertStatus(422)
            ->assertJsonPath('missing_documents.0', "O'Level Result (1st sitting)");
        $this->assertSame('form_in_progress', $application->fresh()->stage);
    }

    public function test_nysc_not_applicable_can_submit_without_certificate_and_third_class_still_submits(): void
    {
        $application = $this->readyPgApplication($this->taught, 'third', 'not_applicable');
        Sanctum::actingAs($application->user);

        $response = $this->postJson("/api/applications/{$application->id}/submit", [
            'submission_notice_accepted' => true,
        ]);
        $response->assertOk()
            ->assertJsonPath('eligibility.meets', false);
        $this->assertTrue(collect($response->json('eligibility.failed'))->contains(
            fn ($row) => str_contains((string) ($row['message'] ?? ''), 'Second Class Lower'),
        ));
        $this->assertSame('submitted', $application->fresh()->stage);
    }

    public function test_submit_requires_submission_notice_when_the_form_is_otherwise_complete(): void
    {
        $application = $this->readyPgApplication($this->taught, 'second_lower', 'not_applicable');
        Sanctum::actingAs($application->user);

        $this->postJson("/api/applications/{$application->id}/submit")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['submission_notice_accepted']);
        $this->assertSame('form_in_progress', $application->fresh()->stage);

        $this->postJson("/api/applications/{$application->id}/submit", [
            'submission_notice_accepted' => false,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['submission_notice_accepted']);
        $this->assertSame('form_in_progress', $application->fresh()->stage);
    }

    public function test_cannot_submit_when_the_category_application_window_is_closed(): void
    {
        $application = $this->readyPgApplication($this->taught, 'second_lower', 'not_applicable');
        Sanctum::actingAs($application->user);

        $this->getJson("/api/applications/{$application->id}")
            ->assertOk()
            ->assertJsonPath('application_window_open', true);

        $this->pgIntake->update(['is_open' => false]);

        $this->getJson("/api/applications/{$application->id}")
            ->assertOk()
            ->assertJsonPath('application_window_open', false);

        $this->postJson("/api/applications/{$application->id}/submit", [
            'submission_notice_accepted' => true,
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', Intake::INTAKE_NOT_ACCEPTING_CODE)
            ->assertJsonPath('message', Intake::CLOSED_SUBMIT_MESSAGE);
        $this->assertSame('form_in_progress', $application->fresh()->stage);

        $this->postJson("/api/applications/{$application->id}/steps", [
            'step_key' => 'pg_research',
            'payload' => [
                'research_interest' => 'Computing',
                'proposed_area' => 'Software systems',
                'statement_of_purpose' => 'I want to study this programme.',
                'supervisor_preferences' => [],
            ],
        ])->assertOk();
    }

    public function test_cannot_submit_after_the_category_close_date(): void
    {
        $application = $this->readyPgApplication($this->taught, 'second_lower', 'not_applicable');
        Sanctum::actingAs($application->user);

        $this->pgIntake->update(['closes_on' => now()->subDay()->toDateString()]);

        $this->postJson("/api/applications/{$application->id}/submit", [
            'submission_notice_accepted' => true,
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', Intake::INTAKE_NOT_ACCEPTING_CODE);
        $this->assertSame('form_in_progress', $application->fresh()->stage);
    }

    public function test_submit_still_works_when_another_category_window_is_closed(): void
    {
        $application = $this->readyPgApplication($this->taught, 'second_lower', 'not_applicable');
        Sanctum::actingAs($application->user);

        $this->ugIntake->update(['is_open' => false]);

        $this->postJson("/api/applications/{$application->id}/submit", [
            'submission_notice_accepted' => true,
        ])->assertOk()
            ->assertJsonPath('stage', 'submitted');
    }

    public function test_ug_and_pg_engine_transitions_and_unwired_stage_is_rejected(): void
    {
        $ug = $this->ugApplication();
        $ug->update(['stage' => 'submitted']);
        $staff = $this->staffUser([
            'admissions.view', 'admissions.screen', 'admissions.verify', 'admissions.shortlist',
            'admissions.recommend', 'admissions.approve', 'admissions.offer',
            'admissions.pg.screen', 'admissions.pg.proposal', 'admissions.pg.supervisor', 'admissions.pg.panel',
        ], ['home', 'admissions-undergraduate', 'admissions-postgraduate']);
        Sanctum::actingAs($staff);

        $this->postJson("/api/applications/{$ug->id}/transition", ['to_stage' => 'screening'])->assertOk();
        $this->postJson("/api/applications/{$ug->id}/transition", ['to_stage' => 'verification'])->assertOk();
        $this->assertSame('verification', $ug->fresh()->stage);

        $taught = $this->readyPgApplication($this->taught, 'second_lower', 'not_applicable');
        Sanctum::actingAs($taught->user);
        $this->postJson("/api/applications/{$taught->id}/submit", ['submission_notice_accepted' => true])->assertOk();
        Sanctum::actingAs($staff);
        $this->postJson("/api/applications/{$taught->id}/transition", ['to_stage' => 'screening'])->assertOk();
        $this->postJson("/api/applications/{$taught->id}/transition", ['to_stage' => 'recommendation'])->assertOk();
        $this->assertSame('recommendation', $taught->fresh()->stage);

        $research = $this->readyPgApplication($this->research, 'first', 'completed');
        Sanctum::actingAs($research->user);
        $this->saveResearch($research);
        $this->postJson("/api/applications/{$research->id}/submit", ['submission_notice_accepted' => true])->assertOk();
        Sanctum::actingAs($staff);
        $this->postJson("/api/applications/{$research->id}/transition", ['to_stage' => 'screening'])->assertOk();
        $this->postJson("/api/applications/{$research->id}/transition", ['to_stage' => 'proposal_review'])->assertOk();
        $this->postJson("/api/applications/{$research->id}/transition", ['to_stage' => 'viva'])
            ->assertStatus(422);

        WorkflowTemplateStage::query()->where('key', 'viva')->update(['is_wired' => false]);
        $this->postJson("/api/applications/{$research->id}/transition", ['to_stage' => 'viva'])
            ->assertStatus(422);
    }

    public function test_staff_can_revert_the_last_application_decision(): void
    {
        $ug = $this->ugApplication();
        $staff = $this->staffUser([
            'admissions.view', 'admissions.screen', 'admissions.verify',
        ], ['home', 'admissions-undergraduate']);
        Sanctum::actingAs($staff);

        $this->postJson("/api/applications/{$ug->id}/revert")->assertStatus(422);

        $this->postJson("/api/applications/{$ug->id}/transition", ['to_stage' => 'screening'])
            ->assertOk()
            ->assertJsonPath('workflow.can_revert', true)
            ->assertJsonPath('workflow.revert.restore_stage', 'submitted');
        $this->assertSame('screening', $ug->fresh()->stage);

        $this->postJson("/api/applications/{$ug->id}/revert", ['reason' => 'Moved in error'])
            ->assertOk()
            ->assertJsonPath('stage', 'submitted')
            ->assertJsonPath('workflow.can_revert', true);

        $this->assertSame('submitted', $ug->fresh()->stage);
        $this->assertSame('reverted', $ug->reviews()->latest('id')->value('decision'));

        $this->postJson("/api/applications/{$ug->id}/transition", [
            'to_stage' => 'rejected',
            'decision' => 'rejected',
            'reason' => 'Incomplete file',
        ])->assertOk();
        $this->assertSame('rejected', $ug->fresh()->stage);

        $this->postJson("/api/applications/{$ug->id}/revert")
            ->assertOk()
            ->assertJsonPath('stage', 'submitted');
    }

    public function test_referee_invite_token_upload_and_expired_link(): void
    {
        Mail::fake();
        Storage::fake(config('filesystems.default', 'local'));
        $application = $this->pgApplication($this->taught);
        Sanctum::actingAs($application->user);
        $this->postJson("/api/applications/{$application->id}/steps", [
            'step_key' => 'pg_referees',
            'payload' => [
                'referees' => [
                    ['name' => 'Prof One', 'email' => 'one@example.com', 'institution' => 'UI', 'position' => 'Professor'],
                    ['name' => 'Prof Two', 'email' => 'two@example.com', 'institution' => 'OAU', 'position' => 'Reader'],
                ],
            ],
        ])->assertOk();

        $this->assertGreaterThan(0, $application->refereeInvites()->count());
        Mail::assertSent(RefereeInviteMail::class);
        $token = null;
        Mail::assertSent(RefereeInviteMail::class, function (RefereeInviteMail $mail) use (&$token) {
            if ($mail->invite->email !== 'one@example.com') {
                return false;
            }
            $token = $mail->plainToken;
            $html = $mail->render();

            return $token
                && str_contains($html, '/referee/'.$token)
                && preg_match('#https?://[^\s"\']+/referee/'.preg_quote($token, '#').'#', $html) === 1;
        });
        $this->assertNotEmpty($token);

        $this->post("/api/referee/{$token}", [
            'file' => UploadedFile::fake()->create('letter.pdf', 80, 'application/pdf'),
            'comments' => 'Strongly recommended',
        ])->assertOk();
        $this->assertTrue($application->documents()->where('doc_type', 'recommendation_1')->exists());

        $expired = $application->refereeInvites()->where('email', 'two@example.com')->first();
        $expiredToken = null;
        Mail::assertSent(RefereeInviteMail::class, function (RefereeInviteMail $mail) use ($expired, &$expiredToken) {
            if ($mail->invite->id !== $expired->id) {
                return false;
            }
            $expiredToken = $mail->plainToken;

            return true;
        });
        $expired->update(['expires_at' => now()->subDay()]);
        $this->getJson("/api/referee/{$expiredToken}")->assertStatus(403);
    }

    public function test_staff_can_change_referee_email_and_resend_invite(): void
    {
        Mail::fake();
        $application = $this->pgApplication($this->taught);
        Sanctum::actingAs($application->user);
        $this->postJson("/api/applications/{$application->id}/steps", [
            'step_key' => 'pg_referees',
            'payload' => [
                'referees' => [
                    ['name' => 'Prof One', 'email' => 'one@example.com', 'institution' => 'UI', 'position' => 'Professor'],
                    ['name' => 'Prof Two', 'email' => 'two@example.com', 'institution' => 'OAU', 'position' => 'Reader'],
                ],
            ],
        ])->assertOk();

        $invite = $application->refereeInvites()->where('email', 'one@example.com')->first();
        $this->assertNotNull($invite);
        $oldToken = null;
        Mail::assertSent(RefereeInviteMail::class, function (RefereeInviteMail $mail) use ($invite, &$oldToken) {
            if ($mail->invite->id !== $invite->id) {
                return false;
            }
            $oldToken = $mail->plainToken;

            return true;
        });

        $this->postJson("/api/applications/{$application->id}/referees/{$invite->id}/resend", [
            'email' => 'new.ref@example.com',
        ])->assertOk()
            ->assertJsonPath('referees.0.email', 'new.ref@example.com');

        $this->assertSame('new.ref@example.com', $invite->fresh()->email);

        $staff = $this->staffUser(['admissions.view'], ['home', 'admissions-postgraduate']);
        Sanctum::actingAs($staff);
        Mail::fake();

        $this->postJson("/api/applications/{$application->id}/referees/{$invite->id}/resend", [
            'email' => 'two@example.com',
        ])->assertStatus(422);

        $this->postJson("/api/applications/{$application->id}/referees/{$invite->id}/resend", [
            'email' => 'staff.ref@example.com',
        ])->assertOk()
            ->assertJsonPath('referees.0.email', 'staff.ref@example.com');

        $this->assertSame('staff.ref@example.com', $invite->fresh()->email);
        $step = $application->steps()->where('step_key', 'pg_referees')->first();
        $emails = collect($step?->payload['referees'] ?? [])->pluck('email')->map(fn ($email) => strtolower((string) $email));
        $this->assertTrue($emails->contains('staff.ref@example.com'));
        $this->assertFalse($emails->contains('one@example.com'));

        Mail::assertSent(RefereeInviteMail::class, fn (RefereeInviteMail $mail) => $mail->hasTo('staff.ref@example.com'));
        $this->getJson("/api/referee/{$oldToken}")->assertStatus(403);

        $this->postJson("/api/applications/{$application->id}/referees/{$invite->id}/resend")
            ->assertOk();
        Mail::assertSent(RefereeInviteMail::class, 2);
    }

    public function test_applicant_can_change_referee_details_after_submit_and_mail_goes_to_new_email(): void
    {
        Mail::fake();
        $application = $this->pgApplication($this->taught);
        Sanctum::actingAs($application->user);
        $this->postJson("/api/applications/{$application->id}/steps", [
            'step_key' => 'pg_referees',
            'payload' => [
                'referees' => [
                    ['name' => 'Prof One', 'email' => 'one@example.com', 'institution' => 'UI', 'position' => 'Professor'],
                    ['name' => 'Prof Two', 'email' => 'two@example.com', 'institution' => 'OAU', 'position' => 'Reader'],
                ],
            ],
        ])->assertOk();

        $application->update(['stage' => 'submitted', 'submitted_at' => now()]);
        Mail::fake();

        $this->postJson("/api/applications/{$application->id}/steps", [
            'step_key' => 'academic_qualifications',
            'payload' => ['first_sitting' => ['exam_type' => 'WAEC']],
        ])->assertStatus(422);

        $this->postJson("/api/applications/{$application->id}/steps", [
            'step_key' => 'pg_referees',
            'payload' => [
                'referees' => [
                    ['name' => 'Prof Ada', 'email' => 'ada.ref@example.com', 'institution' => 'Unilag', 'position' => 'Dean'],
                    ['name' => 'Prof Two', 'email' => 'two@example.com', 'institution' => 'OAU', 'position' => 'Reader'],
                ],
            ],
        ])->assertOk();

        $this->assertSame('submitted', $application->fresh()->stage);
        $invite = $application->refereeInvites()->where('position', 1)->first();
        $this->assertSame('ada.ref@example.com', $invite?->email);
        $this->assertSame('Prof Ada', $invite?->name);
        Mail::assertSent(RefereeInviteMail::class, fn (RefereeInviteMail $mail) => $mail->hasTo('ada.ref@example.com'));
        Mail::assertNotSent(RefereeInviteMail::class, fn (RefereeInviteMail $mail) => $mail->hasTo('one@example.com'));
    }

    public function test_applicant_cannot_replace_a_submitted_referee_after_submit(): void
    {
        Mail::fake();
        Storage::fake(config('filesystems.default', 'local'));
        $application = $this->pgApplication($this->taught);
        Sanctum::actingAs($application->user);
        $this->postJson("/api/applications/{$application->id}/steps", [
            'step_key' => 'pg_referees',
            'payload' => [
                'referees' => [
                    ['name' => 'Prof One', 'email' => 'one@example.com', 'institution' => 'UI', 'position' => 'Professor'],
                    ['name' => 'Prof Two', 'email' => 'two@example.com', 'institution' => 'OAU', 'position' => 'Reader'],
                ],
            ],
        ])->assertOk();

        $invite = $application->refereeInvites()->where('email', 'one@example.com')->first();
        $token = null;
        Mail::assertSent(RefereeInviteMail::class, function (RefereeInviteMail $mail) use ($invite, &$token) {
            if ($mail->invite->id !== $invite->id) {
                return false;
            }
            $token = $mail->plainToken;

            return true;
        });
        $this->post("/api/referee/{$token}", [
            'file' => UploadedFile::fake()->create('letter.pdf', 80, 'application/pdf'),
        ])->assertOk();

        $application->update(['stage' => 'submitted', 'submitted_at' => now()]);
        Mail::fake();

        $this->postJson("/api/applications/{$application->id}/steps", [
            'step_key' => 'pg_referees',
            'payload' => [
                'referees' => [
                    ['name' => 'Someone Else', 'email' => 'other.ref@example.com', 'institution' => 'Unilag', 'position' => 'Dean'],
                    ['name' => 'Prof Two', 'email' => 'two.updated@example.com', 'institution' => 'OAU', 'position' => 'Reader'],
                ],
            ],
        ])->assertOk();

        $this->assertSame('one@example.com', $invite->fresh()->email);
        $this->assertSame('submitted', $invite->fresh()->status);
        $this->assertSame(1, $application->refereeInvites()->where('position', 1)->count());
        $this->assertSame('two.updated@example.com', $application->refereeInvites()->where('position', 2)->value('email'));
        Mail::assertSent(RefereeInviteMail::class, fn (RefereeInviteMail $mail) => $mail->hasTo('two.updated@example.com'));
        Mail::assertNotSent(RefereeInviteMail::class, fn (RefereeInviteMail $mail) => $mail->hasTo('other.ref@example.com'));
    }

    public function test_staff_cannot_change_email_after_referee_submits(): void
    {
        Mail::fake();
        Storage::fake(config('filesystems.default', 'local'));
        $application = $this->pgApplication($this->taught);
        Sanctum::actingAs($application->user);
        $this->postJson("/api/applications/{$application->id}/steps", [
            'step_key' => 'pg_referees',
            'payload' => [
                'referees' => [
                    ['name' => 'Prof One', 'email' => 'one@example.com', 'institution' => 'UI', 'position' => 'Professor'],
                    ['name' => 'Prof Two', 'email' => 'two@example.com', 'institution' => 'OAU', 'position' => 'Reader'],
                ],
            ],
        ])->assertOk();

        $invite = $application->refereeInvites()->where('email', 'one@example.com')->first();
        $token = null;
        Mail::assertSent(RefereeInviteMail::class, function (RefereeInviteMail $mail) use ($invite, &$token) {
            if ($mail->invite->id !== $invite->id) {
                return false;
            }
            $token = $mail->plainToken;

            return true;
        });
        $this->post("/api/referee/{$token}", [
            'file' => UploadedFile::fake()->create('letter.pdf', 80, 'application/pdf'),
        ])->assertOk();

        $staff = $this->staffUser(['admissions.view'], ['home', 'admissions-postgraduate']);
        Sanctum::actingAs($staff);
        $this->postJson("/api/applications/{$application->id}/referees/{$invite->id}/resend", [
            'email' => 'new.ref@example.com',
        ])->assertStatus(422);
        $this->assertSame('one@example.com', $invite->fresh()->email);
    }

    public function test_course_registration_open_vs_closed_and_enrolment_completes(): void
    {
        $studentUser = User::factory()->create();
        $application = $this->ugApplication($studentUser);
        $application->update(['stage' => 'matriculated', 'program_id' => $this->ug->id]);
        $student = Student::query()->create([
            'user_id' => $studentUser->id,
            'application_id' => $application->id,
            'program_id' => $this->ug->id,
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'current_level' => 100,
            'study_level' => 'undergraduate',
            'status' => 'active',
        ]);
        Invoice::query()->create([
            'number' => 'INV-TUIT-1',
            'user_id' => $studentUser->id,
            'student_id' => $student->id,
            'category' => 'tuition',
            'amount' => 100000,
            'full_amount' => 100000,
            'balance' => 0,
            'status' => 'paid',
            'installment_percent' => 100,
        ]);
        $course = Course::query()->create([
            'department_id' => $this->ug->department_id,
            'code' => 'CSC101',
            'title' => 'Intro to Computing',
            'units' => 3,
            'course_type' => 'departmental',
        ]);
        $this->ug->courses()->attach($course->id);
        $offering = CourseOffering::query()->create([
            'course_id' => $course->id,
            'academic_term_id' => $this->term->id,
            'section' => 'A',
            'capacity' => 40,
        ]);
        app(WorkflowEngine::class)->startEnrolment($student, $application);

        Sanctum::actingAs($studentUser);
        $this->postJson('/api/academic/my-registration', ['course_offering_id' => $offering->id])->assertSuccessful();
        $this->assertNotNull(
            WorkflowRun::query()->where('subject_type', Student::class)->where('subject_id', $student->id)->whereNotNull('completed_at')->first()
        );

        $this->term->update([
            'normal_registration_closes_at' => now()->subDays(2),
            'late_registration_closes_at' => now()->subDay(),
        ]);
        $second = Course::query()->create([
            'department_id' => $this->ug->department_id,
            'code' => 'CSC102',
            'title' => 'Programming',
            'units' => 3,
            'course_type' => 'departmental',
        ]);
        $this->ug->courses()->attach($second->id);
        $closedOffering = CourseOffering::query()->create([
            'course_id' => $second->id,
            'academic_term_id' => $this->term->id,
            'section' => 'A',
            'capacity' => 40,
        ]);
        $this->postJson('/api/academic/my-registration', ['course_offering_id' => $closedOffering->id])
            ->assertStatus(422);
    }

    public function test_student_context_lists_available_offerings_when_tuition_blocks_add_drop(): void
    {
        $studentUser = User::factory()->create();
        $application = $this->ugApplication($studentUser);
        $application->update(['stage' => 'matriculated', 'program_id' => $this->ug->id]);
        Student::query()->create([
            'user_id' => $studentUser->id,
            'application_id' => $application->id,
            'program_id' => $this->ug->id,
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'current_level' => 100,
            'study_level' => 'undergraduate',
            'status' => 'active',
        ]);
        $course = Course::query()->create([
            'department_id' => $this->ug->department_id,
            'code' => 'CSC201',
            'title' => 'Data Structures',
            'units' => 3,
            'course_type' => 'departmental',
        ]);
        $this->ug->courses()->attach($course->id);
        $offering = CourseOffering::query()->create([
            'course_id' => $course->id,
            'academic_term_id' => $this->term->id,
            'section' => 'A',
            'capacity' => 40,
        ]);

        Sanctum::actingAs($studentUser);
        $this->getJson('/api/academic/my-registration')
            ->assertOk()
            ->assertJsonPath('can_self_register', false)
            ->assertJsonPath('cannot_register_reason', 'Pay at least 25% of current-session tuition before registering courses.')
            ->assertJsonPath('available.0.id', $offering->id);

        $this->postJson('/api/academic/my-registration', ['course_offering_id' => $offering->id])
            ->assertStatus(422);
    }

    /**
     * @param  list<string>  $permissions
     * @param  list<string>  $navKeys
     */
    private function staffUser(array $permissions, array $navKeys): User
    {
        $role = Role::query()->create([
            'name' => 'Test '.implode('-', $permissions),
            'slug' => 'test-'.substr(sha1(implode(',', $permissions).uniqid()), 0, 12),
            'is_system' => false,
            'is_active' => true,
        ]);
        $ids = Permission::query()->whereIn('key', $permissions)->pluck('id');
        $role->permissions()->sync($ids);
        $office = OfficeDepartment::query()->create([
            'name' => 'Test office '.$role->slug,
            'code' => substr($role->slug, 0, 20),
            'is_active' => true,
        ]);
        $office->syncNavKeys($navKeys);
        $user = User::factory()->create();
        $user->roles()->attach($role->id);
        Staff::query()->create([
            'user_id' => $user->id,
            'staff_number' => 'ST-'.strtoupper(substr($role->slug, -8)),
            'office_department_id' => $office->id,
        ]);

        return $user->fresh(['roles.permissions', 'staff']);
    }

    private function pgApplication(?Program $program = null, ?User $user = null): Application
    {
        $user ??= User::factory()->create();
        $program ??= $this->taught;
        $application = Application::query()->create([
            'application_number' => 'APP/2026/'.str_pad((string) (Application::query()->count() + 1), 5, '0', STR_PAD_LEFT),
            'user_id' => $user->id,
            'intake_id' => $this->pgIntake->id,
            'program_id' => $program->id,
            'entry_mode' => 'pg',
            'stage' => 'form_in_progress',
            'current_step' => 'biodata',
        ]);
        foreach (Application::formSteps('pg') as $step) {
            $payload = $step === 'biodata'
                ? ['nin_locked' => true, 'nin' => '12345678901', 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'photo_path' => 'passports/a.jpg']
                : [];
            $application->steps()->create([
                'step_key' => $step,
                'status' => $step === 'biodata' ? 'saved' : 'pending',
                'payload' => $payload,
            ]);
        }

        return $application->fresh(['user', 'steps']);
    }

    private function ugApplication(?User $user = null): Application
    {
        $user ??= User::factory()->create();
        $application = Application::query()->create([
            'application_number' => 'APP/2026/U'.str_pad((string) (Application::query()->count() + 1), 5, '0', STR_PAD_LEFT),
            'user_id' => $user->id,
            'intake_id' => $this->ugIntake->id,
            'program_id' => $this->ug->id,
            'entry_mode' => 'utme',
            'stage' => 'submitted',
            'submitted_at' => now(),
        ]);
        foreach (Application::formSteps('utme') as $step) {
            $application->steps()->create(['step_key' => $step, 'status' => 'saved', 'payload' => $step === 'biodata' ? ['nin_locked' => true] : []]);
        }

        return $application;
    }

    private function readyPgApplication(Program $program, string $class, string $nysc): Application
    {
        $application = $this->pgApplication($program);
        Sanctum::actingAs($application->user);
        $this->postJson("/api/applications/{$application->id}/steps", [
            'step_key' => 'academic_qualifications',
            'payload' => $this->olevelPayload(),
        ])->assertOk();
        $this->postJson("/api/applications/{$application->id}/steps", [
            'step_key' => 'pg_background',
            'payload' => $this->backgroundPayload($class, $nysc),
        ])->assertOk();
        $this->saveProgramme($application, $program);
        $this->postJson("/api/applications/{$application->id}/steps", [
            'step_key' => 'pg_research',
            'payload' => [
                'research_interest' => 'Computing',
                'proposed_area' => 'Software systems',
                'statement_of_purpose' => 'I want to study this programme.',
                'supervisor_preferences' => $program->is_research_degree ? [$this->supervisor->id] : [],
            ],
        ])->assertOk();
        $this->postJson("/api/applications/{$application->id}/steps", [
            'step_key' => 'pg_referees',
            'payload' => [
                'referees' => [
                    ['name' => 'Ref One', 'email' => 'r1-'.$application->id.'@example.com', 'institution' => 'UI', 'position' => 'Lecturer'],
                    ['name' => 'Ref Two', 'email' => 'r2-'.$application->id.'@example.com', 'institution' => 'OAU', 'position' => 'Professor'],
                ],
            ],
        ])->assertOk();

        foreach (['personal_details', 'health_information', 'next_of_kin', 'sponsor', 'application_form', 'required_documents'] as $step) {
            $application->steps()->where('step_key', $step)->update(['status' => 'saved', 'payload' => ['ok' => true]]);
        }
        $application->documents()->create(['doc_type' => 'degree_certificate', 'path' => 'docs/degree.pdf', 'original_name' => 'degree.pdf']);
        $application->documents()->create(['doc_type' => 'academic_transcript', 'path' => 'docs/transcript.pdf', 'original_name' => 'transcript.pdf']);
        $application->documents()->create(['doc_type' => 'olevel_first_sitting', 'path' => 'docs/olevel.pdf', 'original_name' => 'olevel.pdf']);
        if ($nysc !== 'not_applicable') {
            $application->documents()->create(['doc_type' => 'nysc_certificate', 'path' => 'docs/nysc.pdf', 'original_name' => 'nysc.pdf']);
        }

        return $application->fresh(['steps', 'documents', 'user', 'program']);
    }

    private function saveProgramme(Application $application, Program $program): void
    {
        $this->postJson("/api/applications/{$application->id}/steps", [
            'step_key' => 'programme_selection',
            'payload' => ['first_choice_program_id' => $program->id],
        ])->assertOk();
    }

    private function saveResearch(Application $application): void
    {
        Sanctum::actingAs($application->user);
        $this->postJson("/api/applications/{$application->id}/steps", [
            'step_key' => 'pg_research',
            'payload' => [
                'research_interest' => 'AI',
                'proposed_area' => 'ML',
                'statement_of_purpose' => 'Research statement of purpose text.',
                'supervisor_preferences' => [$this->supervisor->id],
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function olevelPayload(): array
    {
        return [
            'first_sitting' => [
                'exam_type' => 'WAEC',
                'exam_center' => 'Lagos',
                'exam_year' => '2018',
                'exam_number' => '1234567',
                'results' => [
                    ['subject_id' => $this->subject->id, 'subject_name' => 'English Language', 'grade' => 'C6'],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function backgroundPayload(string $class, string $nysc = 'completed'): array
    {
        return [
            'prior_degrees' => [[
                'degree_title' => 'B.Sc Computer Science',
                'institution' => 'Bells University',
                'field_of_study' => 'Computer Science',
                'class' => $class,
                'award_level' => 'bachelor',
                'year_awarded' => '2020',
                'country' => 'Nigeria',
            ]],
            'nysc_status' => $nysc,
            'nysc_number' => $nysc === 'not_applicable' ? null : 'NYSC-1',
            'nysc_exemption_reason' => $nysc === 'not_applicable' ? 'Foreign graduate' : null,
        ];
    }
}
