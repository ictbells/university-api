<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\AcademicTerm;
use App\Models\Campus;
use App\Models\Course;
use App\Models\CourseOffering;
use App\Models\Department;
use App\Models\Enrollment;
use App\Models\Faculty;
use App\Models\OfficeDepartment;
use App\Models\Permission;
use App\Models\Program;
use App\Models\Role;
use App\Models\Staff;
use App\Models\Student;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class CourseOfferingExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_excel_export_lists_registered_counts_for_selected_semester(): void
    {
        [$user, $department, $first, $second] = $this->seedContext();
        $chem = Course::query()->create(['department_id' => $department->id, 'code' => 'CHE121', 'title' => 'Intro Chemistry', 'units' => 3, 'course_type' => 'departmental']);
        $gst = Course::query()->create(['department_id' => $department->id, 'code' => 'GST111', 'title' => 'Use of English', 'units' => 2, 'course_type' => 'general']);

        $chemFirst = CourseOffering::query()->create(['course_id' => $chem->id, 'academic_term_id' => $first->id, 'section' => 'A', 'capacity' => 50]);
        CourseOffering::query()->create(['course_id' => $gst->id, 'academic_term_id' => $first->id, 'section' => 'A']);
        $chemSecond = CourseOffering::query()->create(['course_id' => $chem->id, 'academic_term_id' => $second->id, 'section' => 'A']);

        $program = Program::query()->create([
            'department_id' => $department->id,
            'name' => 'Chemistry',
            'code' => 'CHE',
            'award_type' => 'BSc',
            'study_level' => 'undergraduate',
            'duration_years' => 4,
            'is_active' => true,
        ]);
        foreach (['A', 'B', 'C'] as $i => $suffix) {
            $student = Student::query()->create([
                'user_id' => User::factory()->create()->id,
                'program_id' => $program->id,
                'first_name' => 'Student',
                'last_name' => $suffix,
                'current_level' => 100,
                'study_level' => 'undergraduate',
                'status' => 'active',
            ]);
            Enrollment::query()->create([
                'student_id' => $student->id,
                'course_offering_id' => $chemFirst->id,
                'status' => $i === 2 ? 'dropped' : 'enrolled',
            ]);
            Enrollment::query()->create([
                'student_id' => $student->id,
                'course_offering_id' => $chemSecond->id,
                'status' => 'enrolled',
            ]);
        }

        Sanctum::actingAs($user);
        $response = $this->get('/api/academic/offerings/export?format=excel&academic_term_id='.$first->id);
        $response->assertOk();

        $path = tempnam(sys_get_temp_dir(), 'offx');
        file_put_contents($path, $response->streamedContent());
        $sheet = IOFactory::load($path)->getActiveSheet()->toArray();
        @unlink($path);

        $dataRows = array_values(array_filter($sheet, fn ($row) => in_array($row[1] ?? null, ['CHE121', 'GST111'], true)));
        $this->assertCount(2, $dataRows);
        $byCode = collect($dataRows)->keyBy(1);
        $this->assertSame(2, (int) $byCode['CHE121'][11]);
        $this->assertSame(0, (int) $byCode['GST111'][11]);
        $this->assertSame('First', $byCode['CHE121'][8]);

        $total = collect($sheet)->first(fn ($row) => ($row[0] ?? null) === 'Total');
        $this->assertSame(2, (int) $total[11]);
    }

    public function test_class_list_returns_only_enrolled_students_and_downloads_excel(): void
    {
        [$user, $department, $first] = $this->seedContext();
        $course = Course::query()->create(['department_id' => $department->id, 'code' => 'CHE121', 'title' => 'Intro Chemistry', 'units' => 3, 'course_type' => 'departmental']);
        $offering = CourseOffering::query()->create(['course_id' => $course->id, 'academic_term_id' => $first->id, 'section' => 'A']);
        $program = Program::query()->create([
            'department_id' => $department->id,
            'name' => 'Chemistry',
            'code' => 'CHE',
            'award_type' => 'BSc',
            'study_level' => 'undergraduate',
            'duration_years' => 4,
            'is_active' => true,
        ]);
        foreach ([['BU/22/002', 'Bello', 'enrolled'], ['BU/22/001', 'Adeyemi', 'enrolled'], ['BU/22/003', 'Okafor', 'dropped']] as [$matric, $surname, $status]) {
            $student = Student::query()->create([
                'user_id' => User::factory()->create()->id,
                'program_id' => $program->id,
                'matric_number' => $matric,
                'first_name' => 'Test',
                'last_name' => $surname,
                'current_level' => 100,
                'study_level' => 'undergraduate',
                'status' => 'active',
            ]);
            Enrollment::query()->create(['student_id' => $student->id, 'course_offering_id' => $offering->id, 'status' => $status]);
        }

        Sanctum::actingAs($user);
        $this->getJson("/api/academic/offerings/{$offering->id}/students")
            ->assertOk()
            ->assertJsonCount(2, 'students')
            ->assertJsonPath('students.0.matric', 'BU/22/001')
            ->assertJsonPath('students.1.surname', 'Bello')
            ->assertJsonPath('offering.course_code', 'CHE121');

        $response = $this->get("/api/academic/offerings/{$offering->id}/students/export?format=excel");
        $response->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'rost');
        file_put_contents($path, $response->streamedContent());
        $sheet = IOFactory::load($path)->getActiveSheet()->toArray();
        @unlink($path);

        $matrics = collect($sheet)->pluck(1)->filter(fn ($v) => is_string($v) && str_starts_with($v, 'BU/'))->values()->all();
        $this->assertSame(['BU/22/001', 'BU/22/002'], $matrics);
    }

    public function test_export_rejects_unknown_format(): void
    {
        [$user] = $this->seedContext();
        Sanctum::actingAs($user);

        $this->getJson('/api/academic/offerings/export?format=csv')->assertStatus(422);
    }

    /**
     * @return array{0: User, 1: Department, 2: AcademicTerm, 3: AcademicTerm}
     */
    private function seedContext(): array
    {
        foreach (PermissionCatalog::all() as $perm) {
            Permission::query()->updateOrCreate(['key' => $perm['key']], $perm);
        }

        $campus = Campus::query()->create(['name' => 'Main', 'is_active' => true]);
        $faculty = Faculty::query()->create(['campus_id' => $campus->id, 'name' => 'Science']);
        $department = Department::query()->create(['faculty_id' => $faculty->id, 'name' => 'Chemistry']);
        $session = AcademicSession::query()->create(['label' => '2026/2027', 'starts_on' => '2026-10-01', 'ends_on' => '2027-09-30']);
        $first = AcademicTerm::query()->create(['academic_session_id' => $session->id, 'name' => 'First', 'session_label' => '2026/2027', 'is_current' => true]);
        $second = AcademicTerm::query()->create(['academic_session_id' => $session->id, 'name' => 'Second', 'session_label' => '2026/2027', 'is_current' => false]);

        $role = Role::query()->create(['name' => 'Registry', 'slug' => 'registry-offering-export', 'is_active' => true]);
        $role->permissions()->sync(Permission::query()->where('key', 'academic.offerings.manage')->pluck('id'));
        $office = OfficeDepartment::query()->create(['name' => 'Registry', 'code' => 'REG', 'is_active' => true]);
        $office->syncNavKeys(['offerings']);

        $user = User::factory()->create(['status' => 'active']);
        $user->roles()->attach($role->id);
        Staff::query()->create(['user_id' => $user->id, 'staff_number' => 'ST-EXP', 'office_department_id' => $office->id]);

        return [$user->fresh(['roles.permissions', 'staff']), $department, $first, $second];
    }
}
