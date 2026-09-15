<?php

namespace Tests\Feature;

use App\Mail\AdmissionOfferMail;
use App\Models\AcademicSession;
use App\Models\AcademicTerm;
use App\Models\Application;
use App\Models\Campus;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\Intake;
use App\Models\Permission;
use App\Models\Program;
use App\Models\Role;
use App\Models\User;
use App\Support\PermissionCatalog;
use App\Support\WorkflowCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApplicationOfferEmailTest extends TestCase
{
    use RefreshDatabase;

    public function test_issuing_an_offer_emails_the_applicant(): void
    {
        Mail::fake();

        [$application, $applicant] = $this->approvedApplication();
        Sanctum::actingAs($this->staffUser(['admissions.offer']));

        $this->postJson("/api/applications/{$application->id}/transition", [
            'to_stage' => 'offer_issued',
            'acceptance_fee_amount' => 25000,
        ])->assertOk();

        $this->assertSame('BUT/AD/COLNAS/26/20261234567AB', $application->fresh()->offer_reference);

        Mail::assertSent(AdmissionOfferMail::class, function (AdmissionOfferMail $mail) use ($applicant, $application) {
            $fresh = $application->fresh(['user', 'program', 'intake.term', 'acceptanceFeeInvoice']);
            $html = $mail->render();

            return $mail->hasTo($applicant->email)
                && str_contains($html, 'B.Sc Computer Science')
                && str_contains($html, '2026/2027')
                && str_contains($html, (string) $fresh->offer_reference)
                && str_contains($html, '25,000')
                && str_contains($html, 'Open student portal');
        });
    }

    public function test_offer_email_is_skipped_when_the_applicant_has_no_address(): void
    {
        Mail::fake();

        [$application, $applicant] = $this->approvedApplication();
        $applicant->update(['email' => '']);
        Sanctum::actingAs($this->staffUser(['admissions.offer']));

        $this->postJson("/api/applications/{$application->id}/transition", [
            'to_stage' => 'offer_issued',
            'acceptance_fee_amount' => 25000,
        ])->assertOk();

        Mail::assertNothingSent();
    }

    public function test_offer_reference_uses_application_number_when_jamb_is_absent(): void
    {
        [$application] = $this->approvedApplication(jamb: null);
        Sanctum::actingAs($this->staffUser(['admissions.offer']));

        $this->postJson("/api/applications/{$application->id}/transition", [
            'to_stage' => 'offer_issued',
            'acceptance_fee_amount' => 25000,
        ])->assertOk();

        $this->assertSame('BUT/AD/COLNAS/26/APP/2026/01001', $application->fresh()->offer_reference);
    }

    public function test_jupeb_offer_reference_uses_foundation_serial(): void
    {
        [$application] = $this->approvedApplication(jamb: null, jupeb: true);
        Sanctum::actingAs($this->staffUser(['admissions.offer']));

        $this->postJson("/api/applications/{$application->id}/transition", [
            'to_stage' => 'offer_issued',
            'acceptance_fee_amount' => 100000,
        ])->assertOk();

        $this->assertSame('BUT/AD/JFS/2027/JU/0100', $application->fresh()->offer_reference);
    }

    public function test_pg_offer_reference_uses_colpgs_serial(): void
    {
        Mail::fake();

        [$application] = $this->approvedApplication(jamb: null, pg: true);
        Sanctum::actingAs($this->staffUser(['admissions.offer']));

        $this->postJson("/api/applications/{$application->id}/transition", [
            'to_stage' => 'admission',
            'acceptance_fee_amount' => 100000,
        ])->assertOk();

        $this->assertSame('BUT/COLPGS.01001/COLMANS/026', $application->fresh()->offer_reference);
    }

    /**
     * @return array{0: Application, 1: User}
     */
    private function approvedApplication(?string $jamb = '20261234567AB', bool $jupeb = false, bool $pg = false): array
    {
        foreach (PermissionCatalog::all() as $perm) {
            Permission::query()->updateOrCreate(['key' => $perm['key']], $perm);
        }
        WorkflowCatalog::seed();

        $campus = Campus::query()->create(['name' => 'Main', 'is_active' => true]);
        $faculty = Faculty::query()->create([
            'campus_id' => $campus->id,
            'name' => $pg ? 'College of Management Sciences' : 'College of Natural Sciences',
            'code' => $pg ? 'COLMANS' : 'COLNAS',
            'is_jupeb_centre' => $jupeb,
        ]);
        $department = Department::query()->create([
            'faculty_id' => $faculty->id,
            'name' => $pg ? 'Business Administration' : ($jupeb ? 'Biological Sciences' : 'Computer Science'),
        ]);
        $program = Program::query()->create([
            'department_id' => $department->id,
            'name' => $pg ? 'PGD Human Resource Management' : ($jupeb ? 'JUPEB SCIENCE' : 'B.Sc Computer Science'),
            'code' => $pg ? 'PGD-HRM' : ($jupeb ? 'JUPEB-SCI' : 'BSC-CS'),
            'award_type' => $pg ? 'PGD' : ($jupeb ? 'JUPEB' : 'B.Sc'),
            'study_level' => $pg ? 'postgraduate' : ($jupeb ? 'jupeb' : 'undergraduate'),
            'entry_modes' => $pg ? ['pg'] : ($jupeb ? ['jupeb'] : ['utme']),
            'duration_years' => ($pg || $jupeb) ? 1 : 4,
            'is_active' => true,
            'workflow_template_id' => WorkflowCatalog::idByCode(
                $pg ? WorkflowCatalog::PG_TAUGHT : WorkflowCatalog::UG_STANDARD
            ),
        ]);
        $session = AcademicSession::query()->create(['label' => '2026/2027']);
        $term = AcademicTerm::query()->create([
            'academic_session_id' => $session->id,
            'name' => 'First',
            'session_label' => '2026/2027',
            'is_current' => true,
        ]);
        $intake = Intake::query()->create([
            'academic_term_id' => $term->id,
            'name' => $pg ? 'PG 2026' : ($jupeb ? 'JUPEB 2026' : 'UTME 2026'),
            'entry_mode' => $pg ? 'pg' : ($jupeb ? 'jupeb' : 'utme'),
            'is_open' => true,
            'application_fee_amount' => $pg ? 10000 : 5000,
            'acceptance_fee_amount' => ($pg || $jupeb) ? 100000 : 25000,
            'opens_on' => now()->subDay()->toDateString(),
            'closes_on' => now()->addMonth()->toDateString(),
        ]);
        $applicant = User::factory()->create([
            'name' => 'Ada Okoye',
            'email' => 'ada.okoye@example.com',
            'jamb_registration' => $jamb,
        ]);
        $application = Application::query()->create([
            'application_number' => $jupeb ? 'APP/2026/0100' : 'APP/2026/01001',
            'user_id' => $applicant->id,
            'intake_id' => $intake->id,
            'program_id' => $program->id,
            'entry_mode' => $pg ? 'pg' : ($jupeb ? 'jupeb' : 'utme'),
            'jamb_registration' => $jamb,
            'stage' => $pg ? 'approval' : 'approved',
        ]);

        return [$application, $applicant];
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
