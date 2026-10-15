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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RegistrationExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_requires_registrations_permission(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        Sanctum::actingAs($user);

        $this->getJson('/api/registrations/export?format=csv')->assertForbidden();
    }

    public function test_csv_and_excel_export_succeed_for_registrations(): void
    {
        [$staff] = $this->seedRegisteredStudent();
        Sanctum::actingAs($staff);

        $csv = $this->get('/api/registrations/export?format=csv&title=Undergraduate+registrations')
            ->assertOk();
        $this->assertStringContainsString('text/csv', (string) $csv->headers->get('content-type'));
        $body = $csv->streamedContent();
        $this->assertStringContainsString('Ada Lovelace', $body);
        $this->assertStringContainsString('BUT/2026/REG01', $body);
        $this->assertStringContainsString('1 record(s)', $body);

        $excel = $this->get('/api/registrations/export?format=excel&title=Undergraduate+registrations')
            ->assertOk();
        $this->assertStringContainsString(
            'spreadsheetml.sheet',
            (string) $excel->headers->get('content-type'),
        );
        $this->assertNotSame('', $excel->streamedContent());
    }

    public function test_pdf_and_word_remain_accepted_but_csv_is_preferred_for_large_lists(): void
    {
        [$staff] = $this->seedRegisteredStudent();
        Sanctum::actingAs($staff);

        $this->get('/api/registrations/export?format=pdf')->assertOk();
        $this->get('/api/registrations/export?format=word')->assertOk();
        $this->getJson('/api/registrations/export?format=xml')->assertStatus(422);
    }

    /**
     * @return array{0: User, 1: Student}
     */
    private function seedRegisteredStudent(): array
    {
        foreach (PermissionCatalog::all() as $perm) {
            Permission::query()->updateOrCreate(['key' => $perm['key']], $perm);
        }

        $campus = Campus::query()->create(['name' => 'Main', 'is_active' => true]);
        $faculty = Faculty::query()->create(['campus_id' => $campus->id, 'name' => 'Science']);
        $department = Department::query()->create(['faculty_id' => $faculty->id, 'name' => 'Computer Science']);
        $program = Program::query()->create([
            'department_id' => $department->id,
            'name' => 'Computer Science',
            'code' => 'CSC',
            'award_type' => 'BSc',
            'study_level' => 'undergraduate',
            'duration_years' => 4,
            'is_active' => true,
        ]);
        $session = AcademicSession::query()->create([
            'label' => '2026/2027',
            'starts_on' => '2026-10-01',
            'ends_on' => '2027-09-30',
        ]);
        $term = AcademicTerm::query()->create([
            'academic_session_id' => $session->id,
            'name' => 'First',
            'session_label' => '2026/2027',
            'is_current' => true,
        ]);
        $intake = Intake::query()->create([
            'academic_term_id' => $term->id,
            'name' => 'UTME 2026',
            'entry_mode' => 'utme',
            'is_open' => true,
            'application_fee_amount' => 5000,
        ]);

        $studentUser = User::factory()->create([
            'name' => 'Ada Lovelace',
            'email' => 'ada.reg@example.com',
            'status' => 'active',
        ]);
        $application = Application::query()->create([
            'application_number' => 'APP/2026/REG01',
            'user_id' => $studentUser->id,
            'intake_id' => $intake->id,
            'program_id' => $program->id,
            'entry_mode' => 'utme',
            'stage' => 'matriculated',
        ]);
        $student = Student::query()->create([
            'user_id' => $studentUser->id,
            'application_id' => $application->id,
            'program_id' => $program->id,
            'matric_number' => 'BUT/2026/REG01',
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'current_level' => 100,
            'study_level' => 'undergraduate',
            'status' => 'active',
        ]);
        $application->update(['student_id' => $student->id]);
        Invoice::query()->create([
            'number' => 'INV-REG-TUI',
            'user_id' => $studentUser->id,
            'student_id' => $student->id,
            'category' => 'tuition',
            'amount' => 100000,
            'full_amount' => 100000,
            'balance' => 0,
            'status' => 'paid',
            'wallet_allowed' => true,
            'installment_percent' => 100,
            'academic_session_id' => $session->id,
        ]);

        $role = Role::query()->create([
            'name' => 'Registry',
            'slug' => 'registry-reg-export-'.uniqid(),
            'is_system' => false,
            'is_active' => true,
        ]);
        $role->permissions()->sync(
            Permission::query()->where('key', 'registrations.view')->pluck('id'),
        );
        $staff = User::factory()->create(['status' => 'active']);
        $staff->roles()->attach($role->id);

        return [$staff->fresh(['roles.permissions']), $student];
    }
}
