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
use App\Models\OfficeDepartment;
use App\Models\Permission;
use App\Models\Program;
use App\Models\Role;
use App\Models\Staff;
use App\Models\Student;
use App\Models\User;
use App\Services\ApplicationAdmissionService;
use App\Support\PermissionCatalog;
use App\Support\WorkflowCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApplicationPhysicalClearanceTest extends TestCase
{
    use RefreshDatabase;

    private User $officer;

    private Program $program;

    private Intake $intake;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (PermissionCatalog::all() as $perm) {
            Permission::query()->updateOrCreate(['key' => $perm['key']], $perm);
        }
        WorkflowCatalog::seed();
        Role::query()->firstOrCreate(['slug' => 'student'], ['name' => 'Student', 'is_system' => true, 'is_active' => true]);
        Role::query()->firstOrCreate(['slug' => 'applicant'], ['name' => 'Applicant', 'is_system' => true, 'is_active' => true]);

        $this->officer = $this->staffUser(['admissions.view', 'admissions.clear']);
        $this->program = $this->programme();
        $this->intake = $this->openIntake();
    }

    public function test_acceptance_payment_waits_for_physical_clearance(): void
    {
        $application = $this->paidApplicant();

        $this->assertSame('acceptance_paid', $application->fresh()->stage);
        $this->assertNull($application->fresh()->student_id);
        $this->assertNull($application->fresh()->physically_cleared_at);
        $this->assertSame(0, Student::query()->count());
    }

    public function test_staff_can_clear_one_applicant_and_create_the_student(): void
    {
        $application = $this->paidApplicant();
        Sanctum::actingAs($this->officer);

        $this->postJson("/api/applications/{$application->id}/clear")
            ->assertOk()
            ->assertJsonPath('data.stage', 'matriculated');

        $application = $application->fresh();
        $this->assertSame('matriculated', $application->stage);
        $this->assertNotNull($application->student_id);
        $this->assertNotNull($application->physically_cleared_at);
        $this->assertSame($this->officer->id, $application->physically_cleared_by);
        $this->assertSame(1, Student::query()->count());
    }

    public function test_staff_can_bulk_clear_eligible_applicants(): void
    {
        $first = $this->paidApplicant('APP/2026/C001', 'Ada One');
        $second = $this->paidApplicant('APP/2026/C002', 'Ada Two');
        $unpaid = $this->unpaidApplicant();
        Sanctum::actingAs($this->officer);

        $this->postJson('/api/applications/clearance/bulk', [
            'ids' => [$first->id, $second->id, $unpaid->id],
        ])
            ->assertOk()
            ->assertJsonPath('cleared_count', 2)
            ->assertJsonPath('skipped.0.id', $unpaid->id);

        $this->assertSame('matriculated', $first->fresh()->stage);
        $this->assertSame('matriculated', $second->fresh()->stage);
        $this->assertSame('awaiting_acceptance_fee', $unpaid->fresh()->stage);
        $this->assertSame(2, Student::query()->count());
    }

    public function test_pending_list_only_includes_paid_uncleared_applicants(): void
    {
        $pending = $this->paidApplicant();
        $this->unpaidApplicant();
        Sanctum::actingAs($this->officer);

        $this->getJson('/api/applications/clearance?entry_modes=utme,de,transfer')
            ->assertOk()
            ->assertJsonPath('summary.pending', 1)
            ->assertJsonPath('data.0.id', $pending->id)
            ->assertJsonPath('data.0.stage', 'acceptance_paid');

        $this->getJson('/api/applications/clearance?entry_modes=jupeb')
            ->assertOk()
            ->assertJsonPath('summary.pending', 0)
            ->assertJsonPath('total', 0);
    }

    public function test_cannot_clear_without_permission(): void
    {
        $application = $this->paidApplicant();
        Sanctum::actingAs($this->staffUser(['admissions.view']));

        $this->postJson("/api/applications/{$application->id}/clear")->assertForbidden();
        $this->postJson('/api/applications/clearance/bulk', ['ids' => [$application->id]])->assertForbidden();
    }

    public function test_cannot_clear_when_acceptance_is_unpaid(): void
    {
        $application = $this->unpaidApplicant();
        Sanctum::actingAs($this->officer);

        $this->postJson("/api/applications/{$application->id}/clear")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Acceptance fee has not been paid.');
    }

    public function test_cannot_clear_the_same_applicant_twice(): void
    {
        $application = $this->paidApplicant();
        Sanctum::actingAs($this->officer);
        $this->postJson("/api/applications/{$application->id}/clear")->assertOk();

        $this->postJson("/api/applications/{$application->id}/clear")
            ->assertStatus(422)
            ->assertJsonPath('message', 'This applicant has already been cleared.');
    }

    private function paidApplicant(string $number = 'APP/2026/C100', string $name = 'Ada Okoye'): Application
    {
        $application = $this->makeApplication($number, $name, 'awaiting_acceptance_fee');
        $invoice = Invoice::query()->create([
            'number' => 'ACC-'.$application->id,
            'user_id' => $application->user_id,
            'application_id' => $application->id,
            'category' => 'acceptance_fee',
            'amount' => 25000,
            'balance' => 0,
            'status' => 'paid',
        ]);
        $application->update(['acceptance_fee_invoice_id' => $invoice->id]);
        app(ApplicationAdmissionService::class)->handleInvoicePaid($invoice);

        return $application->fresh(['acceptanceFeeInvoice', 'user']);
    }

    private function unpaidApplicant(): Application
    {
        $application = $this->makeApplication('APP/2026/UNPAID', 'Unpaid Applicant', 'awaiting_acceptance_fee');
        $invoice = Invoice::query()->create([
            'number' => 'ACC-UNPAID-'.$application->id,
            'user_id' => $application->user_id,
            'application_id' => $application->id,
            'category' => 'acceptance_fee',
            'amount' => 25000,
            'balance' => 25000,
            'status' => 'unpaid',
        ]);
        $application->update(['acceptance_fee_invoice_id' => $invoice->id]);

        return $application->fresh(['acceptanceFeeInvoice']);
    }

    private function makeApplication(string $number, string $name, string $stage): Application
    {
        $user = User::factory()->create(['name' => $name, 'status' => 'active']);
        $application = Application::query()->create([
            'application_number' => $number,
            'user_id' => $user->id,
            'intake_id' => $this->intake->id,
            'program_id' => $this->program->id,
            'entry_mode' => 'utme',
            'stage' => $stage,
            'offer_reference' => 'OFF/'.$number,
        ]);
        foreach (Application::formSteps('utme') as $step) {
            $payload = [];
            if ($step === 'biodata') {
                $payload = ['first_name' => 'Ada', 'last_name' => 'Okoye'];
            }
            $application->steps()->create([
                'step_key' => $step,
                'status' => 'saved',
                'payload' => $payload,
            ]);
        }

        return $application;
    }

    private function programme(): Program
    {
        $campus = Campus::query()->create(['name' => 'Main', 'is_active' => true]);
        $faculty = Faculty::query()->create(['campus_id' => $campus->id, 'name' => 'College of Natural Sciences']);
        $department = Department::query()->create(['faculty_id' => $faculty->id, 'name' => 'Computer Science']);

        return Program::query()->create([
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
    }

    private function openIntake(): Intake
    {
        $session = AcademicSession::query()->create(['label' => '2026/2027']);
        $term = AcademicTerm::query()->create([
            'academic_session_id' => $session->id,
            'name' => 'First',
            'session_label' => '2026/2027',
            'is_current' => true,
        ]);

        return Intake::query()->create([
            'academic_term_id' => $term->id,
            'name' => 'UTME 2026',
            'entry_mode' => 'utme',
            'is_open' => true,
            'application_fee_amount' => 5000,
            'acceptance_fee_amount' => 25000,
            'opens_on' => now()->subDay()->toDateString(),
            'closes_on' => now()->addMonth()->toDateString(),
        ]);
    }

    /**
     * @param  list<string>  $permissions
     */
    private function staffUser(array $permissions): User
    {
        $role = Role::query()->create([
            'name' => 'Clearance officer '.uniqid(),
            'slug' => 'clearance-'.uniqid(),
            'is_system' => false,
            'is_active' => true,
        ]);
        $role->permissions()->sync(Permission::query()->whereIn('key', $permissions)->pluck('id'));
        $office = OfficeDepartment::query()->create([
            'name' => 'Admissions '.$role->slug,
            'code' => substr($role->slug, 0, 20),
            'is_active' => true,
        ]);
        $office->syncNavKeys(['admissions-undergraduate', 'admissions-clearance-undergraduate']);
        $user = User::factory()->create(['status' => 'active']);
        $user->roles()->attach($role->id);
        Staff::query()->create([
            'user_id' => $user->id,
            'staff_number' => 'ST-'.strtoupper(substr($role->slug, -8)),
            'office_department_id' => $office->id,
        ]);

        return $user->fresh(['roles.permissions', 'staff']);
    }
}
