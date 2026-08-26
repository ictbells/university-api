<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\AcademicTerm;
use App\Models\Application;
use App\Models\Campus;
use App\Models\Course;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\FeeItem;
use App\Models\Intake;
use App\Models\OlevelSubject;
use App\Models\Permission;
use App\Models\Program;
use App\Models\Role;
use App\Models\User;
use App\Services\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PortalFormImprovementsTest extends TestCase
{
    use RefreshDatabase;

    public function test_course_can_be_created_with_elective_status(): void
    {
        $department = $this->department();
        $course = Course::query()->create([
            'department_id' => $department->id,
            'code' => 'CPE 201',
            'title' => 'Digital Logic',
            'units' => 3,
            'course_type' => 'departmental',
            'status' => 'elective',
        ]);

        $this->assertSame('elective', $course->fresh()->status);
        $this->assertSame('Elective', Course::statusLabel('elective'));
        $this->assertContains('required', Course::STATUSES);
    }

    public function test_application_fee_invoice_uses_catalog_amount_for_entry_mode(): void
    {
        $intake = $this->openIntake();
        $intake->update(['application_fee_amount' => 5000]);
        FeeItem::query()->create([
            'name' => 'UTME application fee',
            'category' => 'application_fee',
            'entry_mode' => 'utme',
            'amount' => 12000,
            'wallet_allowed' => false,
            'is_active' => true,
            'is_required' => true,
            'display_order' => 0,
        ]);

        $user = User::factory()->create();
        $application = Application::query()->create([
            'application_number' => 'APP/2026/00888',
            'user_id' => $user->id,
            'intake_id' => $intake->id,
            'entry_mode' => 'utme',
            'stage' => 'form_in_progress',
            'current_step' => 'biodata',
        ]);
        $invoice = app(InvoiceService::class)->createApplicationFeeInvoice($user, $intake->fresh(), $application->id);

        $this->assertSame(12000.0, (float) $invoice->amount);
        $this->assertSame('application_fee', $invoice->category);
    }

    public function test_application_fee_falls_back_to_intake_when_catalog_has_no_line(): void
    {
        $intake = $this->openIntake();
        $this->assertSame(5000.0, app(InvoiceService::class)->resolveApplicationFeeAmount($intake));
    }

    public function test_olevel_rejects_more_than_nine_subjects_on_first_sitting(): void
    {
        $application = $this->formInProgressApplication();
        $subject = OlevelSubject::query()->create(['name' => 'English Language', 'code' => 'ENG', 'is_active' => true]);
        Sanctum::actingAs($application->user);

        $results = [];
        for ($i = 0; $i < 10; $i++) {
            $results[] = ['subject_id' => $subject->id, 'subject_name' => 'English Language', 'grade' => 'C6'];
        }

        $this->postJson("/api/applications/{$application->id}/steps", [
            'step_key' => 'academic_qualifications',
            'payload' => [
                'first_sitting' => [
                    'exam_type' => 'WAEC',
                    'exam_center' => 'Lagos',
                    'exam_year' => '2018',
                    'exam_number' => '1234567',
                    'results' => $results,
                ],
            ],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['payload.first_sitting.results']);
    }

    public function test_olevel_exam_type_is_kept_on_applicant_save_and_printout(): void
    {
        $application = $this->formInProgressApplication();
        $subject = OlevelSubject::query()->create(['name' => 'English Language', 'code' => 'ENG', 'is_active' => true]);
        Sanctum::actingAs($application->user);

        $this->postJson("/api/applications/{$application->id}/steps", [
            'step_key' => 'academic_qualifications',
            'payload' => $this->sittingPayload($subject->id),
        ])->assertOk();

        $payload = $application->fresh(['steps'])->steps->firstWhere('step_key', 'academic_qualifications')?->payload;
        $this->assertSame('WAEC', $payload['first_sitting']['exam_type'] ?? null);
        $this->assertSame('Ibadan', $payload['first_sitting']['exam_center'] ?? null);

        $html = $this->get("/api/applications/{$application->id}/form-print")->assertOk()->getContent();
        $this->assertStringContainsString('WAEC', $html);
        $this->assertStringContainsString('Ibadan', $html);
        $this->assertStringContainsString('2019', $html);
        $this->assertStringContainsString('12345678AB', $html);
    }

    public function test_staff_update_keeps_olevel_exam_meta(): void
    {
        $application = $this->formInProgressApplication();
        $subject = OlevelSubject::query()->create(['name' => 'English Language', 'code' => 'ENG', 'is_active' => true]);
        $program = $this->programme();
        $application->update(['program_id' => $program->id, 'jamb_registration' => '12345678AB']);
        $application->user->update(['jamb_registration' => '12345678AB']);
        $application->steps()->where('step_key', 'academic_qualifications')->update([
            'status' => 'saved',
            'payload' => $this->sittingPayload($subject->id),
        ]);
        $application->steps()->where('step_key', 'programme_selection')->update([
            'status' => 'saved',
            'payload' => ['first_choice_program_id' => $program->id],
        ]);

        Sanctum::actingAs($this->staffUser(['admissions.view']));

        $this->patchJson("/api/applications/{$application->id}", [
            'email' => $application->user->email,
            'jamb_registration' => '12345678AB',
            'first_name' => 'Favour',
            'last_name' => 'Akinremi',
            'first_choice_program_id' => $program->id,
            'first_sitting' => [
                'exam_type' => 'NECO',
                'exam_center' => 'Ojoo, Ibadan',
                'exam_year' => '2020',
                'exam_number' => 'NECO-999',
                'results' => [
                    ['subject_id' => $subject->id, 'subject_name' => 'English Language', 'grade' => 'C6'],
                ],
            ],
        ])->assertOk();

        $payload = $application->fresh(['steps'])->steps->firstWhere('step_key', 'academic_qualifications')?->payload;
        $this->assertSame('NECO', $payload['first_sitting']['exam_type'] ?? null);
        $this->assertSame('Ojoo, Ibadan', $payload['first_sitting']['exam_center'] ?? null);
        $this->assertSame('2020', $payload['first_sitting']['exam_year'] ?? null);
        $this->assertSame('NECO-999', $payload['first_sitting']['exam_number'] ?? null);

        Sanctum::actingAs($this->staffUser(['admissions.view']));
        $html = $this->get("/api/applications/{$application->id}/form-print")->assertOk()->getContent();
        $this->assertStringContainsString('NECO', $html);
        $this->assertStringContainsString('Ojoo, Ibadan', $html);
    }

    /**
     * @return array<string, mixed>
     */
    private function sittingPayload(int $subjectId): array
    {
        return [
            'first_sitting' => [
                'exam_type' => 'WAEC',
                'exam_center' => 'Ibadan',
                'exam_year' => '2019',
                'exam_number' => '12345678AB',
                'results' => [
                    ['subject_id' => $subjectId, 'subject_name' => 'English Language', 'grade' => 'C6'],
                ],
            ],
        ];
    }

    private function programme(): Program
    {
        $department = $this->department();

        return Program::query()->create([
            'department_id' => $department->id,
            'name' => 'B.Sc Computer Science',
            'code' => 'CSC',
            'award_type' => 'B.Sc',
            'study_level' => 'undergraduate',
            'entry_modes' => ['utme'],
            'duration_years' => 4,
            'is_active' => true,
        ]);
    }

    /**
     * @param  list<string>  $permissions
     */
    private function staffUser(array $permissions): User
    {
        foreach ($permissions as $key) {
            Permission::query()->updateOrCreate(
                ['key' => $key],
                ['module' => 'admissions', 'label' => $key],
            );
        }

        $role = Role::query()->create([
            'name' => 'Admissions tester',
            'slug' => 'admissions-tester-'.Str::lower(Str::random(8)),
            'is_system' => false,
            'is_active' => true,
        ]);
        $role->permissions()->sync(
            Permission::query()->whereIn('key', $permissions)->pluck('id')
        );

        $user = User::factory()->create();
        $user->roles()->attach($role->id);

        return $user->fresh(['roles.permissions']);
    }

    private function department(): Department
    {
        $campus = Campus::query()->create(['name' => 'Main', 'code' => 'MAIN', 'is_active' => true]);
        $faculty = Faculty::query()->create(['campus_id' => $campus->id, 'name' => 'College of Engineering', 'code' => 'COE']);

        return Department::query()->create(['faculty_id' => $faculty->id, 'name' => 'Computer Engineering', 'code' => 'CPE']);
    }

    private function openIntake(): Intake
    {
        $session = AcademicSession::query()->create(['label' => '2025/2026']);
        $term = AcademicTerm::query()->create([
            'academic_session_id' => $session->id,
            'name' => 'First',
            'session_label' => '2025/2026',
            'is_current' => true,
        ]);

        return Intake::query()->create([
            'academic_term_id' => $term->id,
            'name' => 'UTME 2025',
            'entry_mode' => 'utme',
            'is_open' => true,
            'application_fee_amount' => 5000,
            'opens_on' => now()->subDay()->toDateString(),
            'closes_on' => now()->addMonth()->toDateString(),
        ]);
    }

    private function formInProgressApplication(): Application
    {
        $intake = $this->openIntake();
        $role = Role::query()->firstOrCreate(
            ['slug' => 'applicant'],
            ['name' => 'Applicant', 'is_system' => true, 'is_active' => true],
        );
        $user = User::factory()->create();
        $user->roles()->attach($role->id);
        $application = Application::query()->create([
            'application_number' => 'APP/2026/00999',
            'user_id' => $user->id,
            'intake_id' => $intake->id,
            'entry_mode' => 'utme',
            'stage' => 'form_in_progress',
            'current_step' => 'academic_qualifications',
        ]);
        foreach (Application::formSteps('utme') as $step) {
            $application->steps()->create([
                'step_key' => $step,
                'status' => $step === 'biodata' ? 'saved' : 'pending',
                'payload' => $step === 'biodata'
                    ? ['nin_locked' => true, 'nin' => '12345678901', 'photo_path' => 'passports/a.jpg']
                    : [],
            ]);
        }

        return $application->fresh(['user', 'steps']);
    }
}
