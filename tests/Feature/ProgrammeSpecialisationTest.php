<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\AcademicTerm;
use App\Models\Application;
use App\Models\Campus;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\Intake;
use App\Models\OfficeDepartment;
use App\Models\Permission;
use App\Models\Program;
use App\Models\ProgrammeSpecialisation;
use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use App\Support\PermissionCatalog;
use App\Support\WorkflowCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProgrammeSpecialisationTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_can_manage_programme_specialisations_and_required_flag(): void
    {
        [$staff, $department] = $this->staffAndDepartment();
        Sanctum::actingAs($staff);

        $create = $this->postJson('/api/programs', [
            'department_id' => $department->id,
            'name' => 'M.Sc Computer Science',
            'code' => 'MSC-CS',
            'award_type' => 'M.Sc',
            'study_level' => 'postgraduate',
            'entry_modes' => ['pg'],
            'duration_years' => 2,
            'is_active' => true,
            'specialisation_required' => true,
            'specialisations' => [
                ['name' => 'Software Engineering', 'code' => 'SE', 'is_active' => true],
                ['name' => 'Data Science', 'code' => 'DS', 'is_active' => true],
            ],
        ])->assertCreated();

        $programId = (int) $create->json('id');
        $this->assertTrue((bool) $create->json('specialisation_required'));
        $this->assertCount(2, $create->json('specialisations'));

        $this->getJson('/api/academic/programs')
            ->assertOk()
            ->assertJsonFragment(['name' => 'Software Engineering']);

        $seId = (int) collect($create->json('specialisations'))->firstWhere('code', 'SE')['id'];

        $this->patchJson("/api/programs/{$programId}", [
            'specialisation_required' => false,
            'specialisations' => [
                ['id' => $seId, 'name' => 'Software Engineering', 'code' => 'SE', 'is_active' => true],
                ['name' => 'Cyber Security', 'code' => 'CYB', 'is_active' => true],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('specialisation_required', false)
            ->assertJsonCount(2, 'specialisations');

        $this->assertDatabaseMissing('programme_specialisations', [
            'program_id' => $programId,
            'code' => 'DS',
            'deleted_at' => null,
        ]);
        $this->assertDatabaseHas('programme_specialisations', [
            'program_id' => $programId,
            'code' => 'CYB',
            'deleted_at' => null,
        ]);
    }

    public function test_required_specialisation_needs_at_least_one_active_row(): void
    {
        [$staff, $department] = $this->staffAndDepartment();
        Sanctum::actingAs($staff);

        $this->postJson('/api/programs', [
            'department_id' => $department->id,
            'name' => 'M.Sc Incomplete',
            'award_type' => 'M.Sc',
            'study_level' => 'postgraduate',
            'entry_modes' => ['pg'],
            'duration_years' => 2,
            'specialisation_required' => true,
            'specialisations' => [],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['specialisations']);
    }

    public function test_applicant_must_select_specialisation_when_required(): void
    {
        [, $department] = $this->staffAndDepartment();
        $program = Program::query()->create([
            'department_id' => $department->id,
            'name' => 'M.Sc Computer Science',
            'code' => 'MSC-CS',
            'award_type' => 'M.Sc',
            'study_level' => 'postgraduate',
            'entry_modes' => ['pg'],
            'duration_years' => 2,
            'is_active' => true,
            'specialisation_required' => true,
            'workflow_template_id' => WorkflowCatalog::idByCode(WorkflowCatalog::PG_TAUGHT),
        ]);
        $spec = ProgrammeSpecialisation::query()->create([
            'program_id' => $program->id,
            'name' => 'Software Engineering',
            'code' => 'SE',
            'is_active' => true,
            'display_order' => 1,
        ]);
        $application = $this->pgApplication();
        Sanctum::actingAs($application->user);

        $this->getJson('/api/programs?entry_mode=pg')
            ->assertOk()
            ->assertJsonFragment(['name' => 'Software Engineering'])
            ->assertJsonFragment(['specialisation_required' => true]);

        $this->postJson("/api/applications/{$application->id}/steps", [
            'step_key' => 'programme_selection',
            'payload' => ['first_choice_program_id' => $program->id],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['payload.specialisation_id']);

        $this->postJson("/api/applications/{$application->id}/steps", [
            'step_key' => 'programme_selection',
            'payload' => [
                'first_choice_program_id' => $program->id,
                'specialisation_id' => $spec->id,
            ],
        ])->assertOk();

        $application->refresh();
        $this->assertSame($program->id, (int) $application->program_id);
        $this->assertSame($spec->id, (int) $application->specialisation_id);
        $payload = $application->steps()->where('step_key', 'programme_selection')->value('payload');
        $this->assertSame($spec->id, (int) ($payload['specialisation_id'] ?? 0));
    }

    public function test_optional_specialisation_can_be_omitted(): void
    {
        [, $department] = $this->staffAndDepartment();
        $program = Program::query()->create([
            'department_id' => $department->id,
            'name' => 'M.Sc Optional Spec',
            'award_type' => 'M.Sc',
            'study_level' => 'postgraduate',
            'entry_modes' => ['pg'],
            'duration_years' => 2,
            'is_active' => true,
            'specialisation_required' => false,
            'workflow_template_id' => WorkflowCatalog::idByCode(WorkflowCatalog::PG_TAUGHT),
        ]);
        ProgrammeSpecialisation::query()->create([
            'program_id' => $program->id,
            'name' => 'Networks',
            'is_active' => true,
            'display_order' => 1,
        ]);
        $application = $this->pgApplication();
        Sanctum::actingAs($application->user);

        $this->postJson("/api/applications/{$application->id}/steps", [
            'step_key' => 'programme_selection',
            'payload' => ['first_choice_program_id' => $program->id],
        ])->assertOk();

        $this->assertNull($application->fresh()->specialisation_id);
    }

    /**
     * @return array{0: User, 1: Department}
     */
    private function staffAndDepartment(): array
    {
        foreach (PermissionCatalog::all() as $perm) {
            Permission::query()->updateOrCreate(['key' => $perm['key']], $perm);
        }
        WorkflowCatalog::seed();

        $campus = Campus::query()->create(['name' => 'Main', 'is_active' => true]);
        $faculty = Faculty::query()->create(['campus_id' => $campus->id, 'name' => 'Science']);
        $department = Department::query()->create(['faculty_id' => $faculty->id, 'name' => 'Computer Science']);

        $role = Role::query()->create(['name' => 'Registry', 'slug' => 'registry-aos', 'is_active' => true]);
        $role->permissions()->sync(
            Permission::query()->whereIn('key', ['academic.programmes.manage'])->pluck('id'),
        );
        $office = OfficeDepartment::query()->create(['name' => 'Registry', 'code' => 'REG-AOS', 'is_active' => true]);
        $office->syncNavKeys(['programmes']);
        $user = User::factory()->create(['status' => 'active']);
        $user->roles()->attach($role->id);
        Staff::query()->create([
            'user_id' => $user->id,
            'staff_number' => 'ST-AOS',
            'office_department_id' => $office->id,
        ]);

        return [$user->fresh(['roles.permissions', 'staff']), $department];
    }

    private function pgApplication(): Application
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
            'name' => 'PG 2025',
            'entry_mode' => 'pg',
            'is_open' => true,
            'application_fee_amount' => 10000,
            'opens_on' => now()->subDay()->toDateString(),
            'closes_on' => now()->addMonth()->toDateString(),
        ]);
        $role = Role::query()->firstOrCreate(
            ['slug' => 'applicant'],
            ['name' => 'Applicant', 'is_system' => true, 'is_active' => true],
        );
        $user = User::factory()->create(['status' => 'active']);
        $user->roles()->attach($role->id);
        $application = Application::query()->create([
            'application_number' => 'APP/PG/'.uniqid(),
            'user_id' => $user->id,
            'intake_id' => $intake->id,
            'entry_mode' => 'pg',
            'stage' => 'form_in_progress',
            'current_step' => 'programme_selection',
        ]);
        foreach (Application::formSteps('pg') as $step) {
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
