<?php

namespace Tests\Feature;

use App\Models\AcademicLevel;
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

class StudentLevelFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_jupeb_level_filter_finds_jupeb_students_and_undergraduate_100_excludes_them(): void
    {
        [$staff] = $this->seedStudents();
        Sanctum::actingAs($staff);

        $this->getJson('/api/registrations?entry_modes[]=jupeb&level=JUPEB%20Year%201')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.matric_number', 'JFS26/24/1305');

        $this->getJson('/api/registrations?entry_modes[]=jupeb&level=JUPEB')
            ->assertOk()
            ->assertJsonPath('total', 1);

        $this->getJson('/api/registrations?entry_modes[]=utme&level=100')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.matric_number', 'BUT/2026/UG100');

        $this->getJson('/api/students?level=100')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.matric_number', 'BUT/2026/UG100');

        $this->getJson('/api/students?level=JUPEB')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.matric_number', 'JFS26/24/1305');
    }

    /**
     * @return array{0: User}
     */
    private function seedStudents(): array
    {
        foreach (PermissionCatalog::all() as $perm) {
            Permission::query()->updateOrCreate(['key' => $perm['key']], $perm);
        }

        AcademicLevel::query()->create([
            'name' => '100 Level',
            'code' => '100',
            'study_level' => 'undergraduate',
            'sort_order' => 1,
            'is_active' => true,
        ]);
        AcademicLevel::query()->create([
            'name' => 'JUPEB Year 1',
            'code' => '100',
            'study_level' => 'jupeb',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $campus = Campus::query()->create(['name' => 'Main', 'is_active' => true]);
        $faculty = Faculty::query()->create(['campus_id' => $campus->id, 'name' => 'Science']);
        $department = Department::query()->create(['faculty_id' => $faculty->id, 'name' => 'Computer Science']);
        $ugProgram = Program::query()->create([
            'department_id' => $department->id,
            'name' => 'Computer Science',
            'code' => 'CSC',
            'award_type' => 'BSc',
            'study_level' => 'undergraduate',
            'duration_years' => 4,
            'is_active' => true,
        ]);
        $jupebProgram = Program::query()->create([
            'department_id' => $department->id,
            'name' => 'JUPEB Sciences',
            'code' => 'JFS',
            'award_type' => 'JUPEB',
            'study_level' => 'jupeb',
            'entry_modes' => ['jupeb'],
            'duration_years' => 1,
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
        $ugIntake = Intake::query()->create([
            'academic_term_id' => $term->id,
            'name' => 'UTME 2026',
            'entry_mode' => 'utme',
            'is_open' => true,
            'application_fee_amount' => 5000,
        ]);
        $jupebIntake = Intake::query()->create([
            'academic_term_id' => $term->id,
            'name' => 'JUPEB 2026',
            'entry_mode' => 'jupeb',
            'is_open' => true,
            'application_fee_amount' => 5000,
        ]);

        $this->registeredStudent('Ada Ug', 'BUT/2026/UG100', $ugProgram, $ugIntake, 'utme', 'undergraduate');
        $this->registeredStudent('Pascal Jupeb', 'JFS26/24/1305', $jupebProgram, $jupebIntake, 'jupeb', 'jupeb');

        $role = Role::query()->create([
            'name' => 'Registry',
            'slug' => 'registry-level-filter-'.uniqid(),
            'is_system' => false,
            'is_active' => true,
        ]);
        $role->permissions()->sync(
            Permission::query()->whereIn('key', ['registrations.view', 'students.view_any', 'students.manage'])->pluck('id'),
        );
        $staff = User::factory()->create(['status' => 'active']);
        $staff->roles()->attach($role->id);

        return [$staff->fresh(['roles.permissions'])];
    }

    private function registeredStudent(
        string $name,
        string $matric,
        Program $program,
        Intake $intake,
        string $entryMode,
        string $studyLevel,
    ): Student {
        [$first, $last] = array_pad(explode(' ', $name, 2), 2, 'Student');
        $user = User::factory()->create([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)).'@example.com',
            'status' => 'active',
        ]);
        $application = Application::query()->create([
            'application_number' => 'APP/'.uniqid(),
            'user_id' => $user->id,
            'intake_id' => $intake->id,
            'program_id' => $program->id,
            'entry_mode' => $entryMode,
            'stage' => 'matriculated',
        ]);
        $student = Student::query()->create([
            'user_id' => $user->id,
            'application_id' => $application->id,
            'program_id' => $program->id,
            'matric_number' => $matric,
            'first_name' => $first,
            'last_name' => $last,
            'current_level' => 100,
            'study_level' => $studyLevel,
            'status' => 'active',
        ]);
        $application->update(['student_id' => $student->id]);
        Invoice::query()->create([
            'number' => 'INV-'.uniqid(),
            'user_id' => $user->id,
            'student_id' => $student->id,
            'category' => 'tuition',
            'amount' => 100000,
            'full_amount' => 100000,
            'balance' => 0,
            'status' => 'paid',
            'wallet_allowed' => true,
            'installment_percent' => 100,
            'academic_session_id' => $intake->term?->academic_session_id,
        ]);

        return $student;
    }
}
