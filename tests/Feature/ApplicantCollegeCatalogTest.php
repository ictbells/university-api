<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\AcademicTerm;
use App\Models\Application;
use App\Models\Campus;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\Intake;
use App\Models\Program;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApplicantCollegeCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_list_colleges(): void
    {
        $this->getJson('/api/colleges')->assertUnauthorized();
    }

    public function test_applicant_sees_staff_created_college_before_programmes_exist(): void
    {
        $campus = Campus::query()->create(['name' => 'Main', 'is_active' => true]);
        $faculty = Faculty::query()->create([
            'campus_id' => $campus->id,
            'name' => 'College of Law',
            'code' => 'LAW',
        ]);
        Department::query()->create([
            'faculty_id' => $faculty->id,
            'name' => 'Private Law',
            'code' => 'PLW',
        ]);

        Sanctum::actingAs($this->applicant());

        $this->getJson('/api/colleges')
            ->assertOk()
            ->assertJsonFragment(['name' => 'College of Law'])
            ->assertJsonFragment(['name' => 'Private Law']);

        $this->getJson('/api/programs?entry_mode=utme')
            ->assertOk()
            ->assertExactJson([]);
    }

    public function test_applicant_cannot_save_programme_step_without_a_programme(): void
    {
        $application = $this->formInProgressApplication();
        Sanctum::actingAs($application->user);

        $this->postJson("/api/applications/{$application->id}/steps", [
            'step_key' => 'programme_selection',
            'payload' => [
                'first_choice_college_id' => 1,
                'first_choice_department_id' => 1,
            ],
        ])->assertStatus(422);

        $this->assertNull($application->fresh()->program_id);
    }

    public function test_applicant_cannot_submit_without_a_programme(): void
    {
        $application = $this->formInProgressApplication();
        foreach (Application::formSteps('utme') as $step) {
            $application->steps()->where('step_key', $step)->update([
                'status' => 'saved',
                'payload' => $step === 'biodata'
                    ? ['nin_locked' => true, 'nin' => '12345678901', 'photo_path' => 'passports/a.jpg']
                    : [],
            ]);
        }
        foreach (['birth_certificate', 'jamb_result', 'olevel_first_sitting'] as $docType) {
            $application->documents()->create([
                'doc_type' => $docType,
                'path' => "docs/{$docType}.pdf",
                'original_name' => "{$docType}.pdf",
            ]);
        }

        Sanctum::actingAs($application->user);

        $this->postJson("/api/applications/{$application->id}/submit")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Select a programme before submitting your application.');

        $this->assertSame('form_in_progress', $application->fresh()->stage);
        $this->assertNull($application->fresh()->program_id);
    }

    public function test_applicant_can_save_programme_selection_when_entry_modes_are_stored_as_a_string(): void
    {
        $application = $this->formInProgressApplication();
        $campus = Campus::query()->create(['name' => 'Main', 'is_active' => true]);
        $faculty = Faculty::query()->create(['campus_id' => $campus->id, 'name' => 'College of Law']);
        $department = Department::query()->create(['faculty_id' => $faculty->id, 'name' => 'Private Law']);
        $program = Program::query()->create([
            'department_id' => $department->id,
            'name' => 'LL.B Law',
            'award_type' => 'LL.B',
            'study_level' => 'undergraduate',
            'entry_modes' => ['utme'],
            'duration_years' => 5,
            'is_active' => true,
        ]);
        $program->forceFill(['entry_modes' => 'utme'])->saveQuietly();

        Sanctum::actingAs($application->user);

        $this->postJson("/api/applications/{$application->id}/steps", [
            'step_key' => 'programme_selection',
            'payload' => [
                'first_choice_college_id' => $faculty->id,
                'first_choice_department_id' => $department->id,
                'first_choice_program_id' => $program->id,
                'second_choice_college_id' => null,
                'second_choice_department_id' => null,
                'second_choice_program_id' => null,
                'program_id' => $program->id,
            ],
        ])
            ->assertOk()
            ->assertJsonPath('program_id', $program->id);

        $this->assertSame($program->id, $application->fresh()->program_id);
    }

    private function applicant(): User
    {
        $role = Role::query()->firstOrCreate(
            ['slug' => 'applicant'],
            ['name' => 'Applicant', 'is_system' => true, 'is_active' => true],
        );
        $user = User::factory()->create();
        $user->roles()->attach($role->id);

        return $user->fresh(['roles']);
    }

    private function formInProgressApplication(): Application
    {
        $session = AcademicSession::query()->create(['label' => '2025/2026']);
        $term = AcademicTerm::query()->create([
            'academic_session_id' => $session->id,
            'name' => 'First',
            'session_label' => '2025/2026',
            'is_current' => true,
        ]);
        $intake = Intake::query()->create([
            'academic_term_id' => $term->id,
            'name' => 'UTME 2025',
            'entry_mode' => 'utme',
            'is_open' => true,
            'application_fee_amount' => 5000,
            'opens_on' => now()->subDay()->toDateString(),
            'closes_on' => now()->addMonth()->toDateString(),
        ]);
        $user = $this->applicant();
        $application = Application::query()->create([
            'application_number' => 'APP/2026/00001',
            'user_id' => $user->id,
            'intake_id' => $intake->id,
            'program_id' => null,
            'entry_mode' => 'utme',
            'stage' => 'form_in_progress',
            'current_step' => 'programme_selection',
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
