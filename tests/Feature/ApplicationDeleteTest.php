<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\AcademicTerm;
use App\Models\Application;
use App\Models\Campus;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\Intake;
use App\Models\Invoice;
use App\Models\Permission;
use App\Models\Program;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use App\Support\PermissionCatalog;
use App\Support\WorkflowCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApplicationDeleteTest extends TestCase
{
    use RefreshDatabase;

    private Intake $utmeIntake;

    private Intake $pgIntake;

    private Program $utmeProgram;

    private Program $pgProgram;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (PermissionCatalog::all() as $perm) {
            Permission::query()->updateOrCreate(['key' => $perm['key']], $perm);
        }
        WorkflowCatalog::seed();
        Role::query()->firstOrCreate(['slug' => 'applicant'], ['name' => 'Applicant', 'is_system' => true, 'is_active' => true]);

        $campus = Campus::query()->create(['name' => 'Main', 'is_active' => true]);
        $faculty = Faculty::query()->create(['campus_id' => $campus->id, 'name' => 'College of Natural Sciences']);
        $department = Department::query()->create(['faculty_id' => $faculty->id, 'name' => 'Computer Science']);
        $session = AcademicSession::query()->create(['label' => '2026/2027']);
        $term = AcademicTerm::query()->create([
            'academic_session_id' => $session->id,
            'name' => 'First',
            'session_label' => '2026/2027',
            'is_current' => true,
        ]);

        $this->utmeProgram = Program::query()->create([
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
        $this->pgProgram = Program::query()->create([
            'department_id' => $department->id,
            'name' => 'M.Sc Computer Science',
            'code' => 'MSC-CS',
            'award_type' => 'M.Sc',
            'study_level' => 'postgraduate',
            'entry_modes' => ['pg'],
            'duration_years' => 2,
            'is_active' => true,
            'workflow_template_id' => WorkflowCatalog::idByCode(WorkflowCatalog::PG_TAUGHT),
        ]);
        $this->utmeIntake = Intake::query()->create([
            'academic_term_id' => $term->id,
            'name' => 'UTME 2026',
            'entry_mode' => 'utme',
            'is_open' => true,
            'application_fee_amount' => 5000,
            'acceptance_fee_amount' => 25000,
            'opens_on' => now()->subDay()->toDateString(),
            'closes_on' => now()->addMonth()->toDateString(),
        ]);
        $this->pgIntake = Intake::query()->create([
            'academic_term_id' => $term->id,
            'name' => 'PG 2026',
            'entry_mode' => 'pg',
            'is_open' => true,
            'application_fee_amount' => 15000,
            'acceptance_fee_amount' => 50000,
            'opens_on' => now()->subDay()->toDateString(),
            'closes_on' => now()->addMonth()->toDateString(),
        ]);
    }

    public function test_staff_can_delete_one_form_without_removing_the_shared_account(): void
    {
        [$pg, $utme, $user] = $this->linkedForms();
        $paid = Invoice::query()->create([
            'number' => 'APP-FEE-'.$utme->id,
            'user_id' => $user->id,
            'application_id' => $utme->id,
            'category' => 'application_fee',
            'amount' => 5000,
            'balance' => 0,
            'status' => 'paid',
        ]);
        $unpaid = Invoice::query()->create([
            'number' => 'APP-UNPAID-'.$utme->id,
            'user_id' => $user->id,
            'application_id' => $utme->id,
            'category' => 'application_fee',
            'amount' => 2000,
            'balance' => 2000,
            'status' => 'unpaid',
        ]);
        $utme->update(['application_fee_invoice_id' => $paid->id]);

        Sanctum::actingAs($this->staffUser(['admissions.view', 'admissions.delete']));

        $this->deleteJson("/api/applications/{$utme->id}", [
            'reason' => 'Bought UTME in error after the postgraduate form.',
        ])->assertOk()
            ->assertJsonPath('remaining_application_id', $pg->id);

        $this->assertSoftDeleted('applications', ['id' => $utme->id]);
        $this->assertDatabaseHas('applications', ['id' => $pg->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'email' => 'ada.okoye@example.com', 'deleted_at' => null]);
        $this->assertSame('paid', $paid->fresh()->status);
        $this->assertSame('cancelled', $unpaid->fresh()->status);
        $this->assertNull(Application::query()->find($utme->id));
        $this->assertSame($pg->id, $user->fresh()->latestApplication?->id);
    }

    public function test_file_lists_the_other_form_on_the_same_account(): void
    {
        [$pg, $utme] = $this->linkedForms();
        Sanctum::actingAs($this->staffUser(['admissions.view', 'admissions.delete']));

        $this->getJson("/api/applications/{$pg->id}")
            ->assertOk()
            ->assertJsonPath('can_delete', true)
            ->assertJsonPath('linked_applications.0.id', $utme->id)
            ->assertJsonPath('linked_applications.0.entry_mode', 'utme')
            ->assertJsonPath('linked_applications.0.entry_mode_label', 'UTME');
    }

    public function test_view_only_staff_cannot_delete_a_form(): void
    {
        [, $utme] = $this->linkedForms();
        Sanctum::actingAs($this->staffUser(['admissions.view']));

        $this->deleteJson("/api/applications/{$utme->id}", [
            'reason' => 'Bought UTME in error after the postgraduate form.',
        ])->assertForbidden();

        $this->assertDatabaseHas('applications', ['id' => $utme->id, 'deleted_at' => null]);
    }

    public function test_applicant_cannot_delete_their_own_form(): void
    {
        [, $utme, $user] = $this->linkedForms();
        Sanctum::actingAs($user);

        $this->deleteJson("/api/applications/{$utme->id}", [
            'reason' => 'I bought the wrong form.',
        ])->assertForbidden();
    }

    public function test_cannot_delete_after_an_offer_is_issued(): void
    {
        [, $utme] = $this->linkedForms();
        $utme->update(['stage' => 'offer_issued', 'offer_reference' => 'OFF/UTME/1']);
        Sanctum::actingAs($this->staffUser(['admissions.view', 'admissions.delete']));

        $this->deleteJson("/api/applications/{$utme->id}", [
            'reason' => 'Bought UTME in error after the postgraduate form.',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['application']);

        $this->assertDatabaseHas('applications', ['id' => $utme->id, 'deleted_at' => null]);
    }

    public function test_cannot_delete_when_a_student_record_exists(): void
    {
        [$pg, , $user] = $this->linkedForms();
        $student = Student::query()->create([
            'user_id' => $user->id,
            'application_id' => $pg->id,
            'program_id' => $this->pgProgram->id,
            'first_name' => 'Ada',
            'last_name' => 'Okoye',
            'current_level' => 100,
            'status' => 'active',
        ]);
        $pg->update(['student_id' => $student->id, 'stage' => 'submitted']);
        Sanctum::actingAs($this->staffUser(['admissions.view', 'admissions.delete']));

        $this->deleteJson("/api/applications/{$pg->id}", [
            'reason' => 'Duplicate postgraduate form.',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['application']);
    }

    public function test_reason_is_required(): void
    {
        [, $utme] = $this->linkedForms();
        Sanctum::actingAs($this->staffUser(['admissions.view', 'admissions.delete']));

        $this->deleteJson("/api/applications/{$utme->id}", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['reason']);
    }

    /**
     * @return array{0: Application, 1: Application, 2: User}
     */
    private function linkedForms(): array
    {
        $user = User::factory()->create([
            'name' => 'Ada Okoye',
            'email' => 'ada.okoye@example.com',
            'status' => 'active',
        ]);
        $pg = Application::query()->create([
            'application_number' => 'APP/2026/PG001',
            'user_id' => $user->id,
            'intake_id' => $this->pgIntake->id,
            'program_id' => $this->pgProgram->id,
            'entry_mode' => 'pg',
            'stage' => 'form_in_progress',
        ]);
        $utme = Application::query()->create([
            'application_number' => 'APP/2026/UT001',
            'user_id' => $user->id,
            'intake_id' => $this->utmeIntake->id,
            'program_id' => $this->utmeProgram->id,
            'entry_mode' => 'utme',
            'stage' => 'fee_paid',
        ]);

        return [$pg, $utme, $user];
    }

    /**
     * @param  list<string>  $permissions
     */
    private function staffUser(array $permissions): User
    {
        $role = Role::query()->create([
            'name' => 'Admissions officer',
            'slug' => 'admissions-officer-'.uniqid(),
            'is_system' => false,
            'is_active' => true,
        ]);
        $role->permissions()->sync(Permission::query()->whereIn('key', $permissions)->pluck('id'));
        $user = User::factory()->create();
        $user->roles()->attach($role->id);

        return $user->fresh(['roles.permissions']);
    }
}
