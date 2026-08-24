<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\OfficeDepartment;
use App\Models\Permission;
use App\Models\Program;
use App\Models\Role;
use App\Models\Staff;
use App\Models\Student;
use App\Models\User;
use App\Support\PermissionCatalog;
use App\Support\Studentship;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GraduationAndStudentshipTest extends TestCase
{
    use RefreshDatabase;

    private User $staffUser;

    private Program $program;

    private OfficeDepartment $office;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (PermissionCatalog::all() as $perm) {
            Permission::query()->updateOrCreate(['key' => $perm['key']], $perm);
        }

        $role = Role::query()->create(['name' => 'Registrar', 'slug' => 'registrar']);
        $role->permissions()->sync(
            Permission::query()->whereIn('key', ['academic.graduate', 'students.view_any'])->pluck('id'),
        );

        $this->staffUser = User::factory()->create(['status' => 'active']);
        $this->staffUser->roles()->attach($role->id);
        $this->office = OfficeDepartment::query()->create([
            'name' => 'Registry',
            'code' => 'REG',
            'is_active' => true,
        ]);
        $this->office->syncNavKeys(['graduation']);
        Staff::query()->create([
            'user_id' => $this->staffUser->id,
            'staff_number' => 'STF-GRAD',
            'office_department_id' => $this->office->id,
        ]);

        $campus = Campus::query()->create(['name' => 'Main', 'is_active' => true]);
        $faculty = Faculty::query()->create(['campus_id' => $campus->id, 'name' => 'Science']);
        $department = Department::query()->create(['faculty_id' => $faculty->id, 'name' => 'CS']);
        $this->program = Program::query()->create([
            'department_id' => $department->id,
            'name' => 'B.Sc CS',
            'code' => 'BSC-CS',
            'award_type' => 'B.Sc',
            'study_level' => 'undergraduate',
            'entry_modes' => ['utme'],
            'duration_years' => 4,
            'is_active' => true,
        ]);
    }

    public function test_conferment_marks_final_year_student_graduated_and_sets_expiry(): void
    {
        $student = $this->makeStudent(400, 'BUT/2024/0001');
        Sanctum::actingAs($this->staffUser);

        $this->getJson('/api/academic/graduation/candidates')
            ->assertOk()
            ->assertJsonPath('total', 1);

        $this->postJson('/api/academic/graduation/confer', [
            'student_ids' => [$student->id],
            'graduated_at' => '2024-08-24',
        ])->assertOk()
            ->assertJsonPath('conferred_count', 1)
            ->assertJsonPath('studentship_expires_at', '2026-08-24');

        $student->refresh();
        $this->assertSame(Studentship::STATUS_GRADUATED, $student->status);
        $this->assertSame('2024-08-24', $student->graduated_at?->toDateString());
        $this->assertSame('2026-08-24', $student->studentship_expires_at?->toDateString());
    }

    public function test_expiry_command_moves_graduated_students_to_alumni(): void
    {
        $student = $this->makeStudent(400, 'BUT/2022/0002', Studentship::STATUS_GRADUATED, '2024-08-24', '2026-08-24');

        $this->artisan('students:expire-studentship', ['--date' => '2026-08-24'])
            ->assertSuccessful();

        $this->assertSame(Studentship::STATUS_ALUMNI, $student->fresh()->status);
    }

    public function test_student_login_allowed_while_graduated_and_blocked_after_expiry(): void
    {
        $user = User::factory()->create(['status' => 'active', 'password' => 'password']);
        $student = $this->makeStudent(400, 'BUT/2024/0003', Studentship::STATUS_GRADUATED, '2025-08-24', now()->addYear()->toDateString(), $user);

        $this->postJson('/api/login', [
            'portal' => 'student',
            'login' => 'BUT/2024/0003',
            'password' => 'password',
        ])->assertOk();

        $student->update([
            'status' => Studentship::STATUS_ALUMNI,
            'studentship_expires_at' => now()->subDay()->toDateString(),
        ]);

        $this->postJson('/api/login', [
            'portal' => 'student',
            'login' => 'BUT/2024/0003',
            'password' => 'password',
        ])->assertUnprocessable()
            ->assertJsonPath(
                'errors.login.0',
                'Your studentship ended on '.$student->fresh()->studentship_expires_at?->toDateString().'. Sign in is no longer available on the student portal.'
            );
    }

    public function test_alumni_with_staff_record_can_still_use_staff_portal(): void
    {
        $user = User::factory()->create(['status' => 'active', 'email' => 'dual@example.com', 'password' => 'password']);
        $this->makeStudent(400, 'BUT/2020/0004', Studentship::STATUS_ALUMNI, '2022-08-24', '2024-08-24', $user);
        $user->roles()->attach($this->staffUser->roles()->first()->id);
        Staff::query()->create([
            'user_id' => $user->id,
            'staff_number' => 'STF-DUAL',
            'office_department_id' => $this->office->id,
        ]);

        $this->postJson('/api/login', [
            'portal' => 'student',
            'login' => 'BUT/2020/0004',
            'password' => 'password',
        ])->assertStatus(422);

        $this->postJson('/api/login', [
            'email' => 'dual@example.com',
            'password' => 'password',
        ])->assertOk()
            ->assertJsonPath('is_staff', true);
    }

    private function makeStudent(
        int $level,
        string $matric,
        string $status = Studentship::STATUS_ACTIVE,
        ?string $graduatedAt = null,
        ?string $expiresAt = null,
        ?User $user = null,
    ): Student {
        return Student::query()->create([
            'user_id' => ($user ?? User::factory()->create(['status' => 'active']))->id,
            'program_id' => $this->program->id,
            'first_name' => 'Ada',
            'last_name' => 'Okafor',
            'matric_number' => $matric,
            'current_level' => $level,
            'study_level' => 'undergraduate',
            'status' => $status,
            'graduated_at' => $graduatedAt,
            'studentship_expires_at' => $expiresAt,
        ]);
    }
}
