<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\Permission;
use App\Models\Program;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StudentListFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_students_filter_by_college_department_programme_and_matric(): void
    {
        $campus = Campus::query()->create(['name' => 'Main', 'is_active' => true]);
        $science = Faculty::query()->create(['campus_id' => $campus->id, 'name' => 'Science']);
        $engineering = Faculty::query()->create(['campus_id' => $campus->id, 'name' => 'Engineering']);
        $chemistry = Department::query()->create(['faculty_id' => $science->id, 'name' => 'Chemistry']);
        $physics = Department::query()->create(['faculty_id' => $science->id, 'name' => 'Physics']);
        $civil = Department::query()->create(['faculty_id' => $engineering->id, 'name' => 'Civil']);
        $che = $this->program($chemistry, 'Industrial Chemistry', 'ICH');
        $phy = $this->program($physics, 'Physics', 'PHY');
        $cve = $this->program($civil, 'Civil Engineering', 'CVE');

        $this->student($che, 'BUT/22/ICH/001');
        $this->student($che, 'BUT/22/ICH/002');
        $this->student($phy, 'BUT/22/PHY/001');
        $this->student($cve, 'BUT/23/CVE/001');

        Sanctum::actingAs($this->viewer());

        $this->getJson('/api/students?faculty_id='.$science->id)->assertOk()->assertJsonPath('total', 3);
        $this->getJson('/api/students?department_id='.$chemistry->id)->assertOk()->assertJsonPath('total', 2);
        $this->getJson('/api/students?program_id='.$cve->id)->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/students?matric_contains=ich/00')->assertOk()->assertJsonPath('total', 2);
        $this->getJson('/api/students?matric_contains=23/CVE&faculty_id='.$science->id)->assertOk()->assertJsonPath('total', 0);

        $this->getJson('/api/students/filter-meta')
            ->assertOk()
            ->assertJsonCount(2, 'faculties')
            ->assertJsonCount(3, 'departments')
            ->assertJsonCount(3, 'programs')
            ->assertJsonFragment(['id' => $che->id, 'department_id' => $chemistry->id, 'faculty_id' => $science->id]);
    }

    private function program(Department $department, string $name, string $code): Program
    {
        return Program::query()->create([
            'department_id' => $department->id,
            'name' => $name,
            'code' => $code,
            'award_type' => 'BSc',
            'study_level' => 'undergraduate',
            'duration_years' => 4,
            'is_active' => true,
        ]);
    }

    private function student(Program $program, string $matric): Student
    {
        return Student::query()->create([
            'user_id' => User::factory()->create()->id,
            'program_id' => $program->id,
            'matric_number' => $matric,
            'first_name' => 'Test',
            'last_name' => $matric,
            'current_level' => 100,
            'study_level' => 'undergraduate',
            'status' => 'active',
        ]);
    }

    private function viewer(): User
    {
        foreach (PermissionCatalog::all() as $perm) {
            Permission::query()->updateOrCreate(['key' => $perm['key']], $perm);
        }
        $role = Role::query()->create(['name' => 'Registry', 'slug' => 'registry-students-list', 'is_active' => true]);
        $role->permissions()->sync(Permission::query()->where('key', 'students.view_any')->pluck('id'));
        $user = User::factory()->create(['status' => 'active']);
        $user->roles()->attach($role->id);

        return $user->fresh(['roles.permissions']);
    }
}
