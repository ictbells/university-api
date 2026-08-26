<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\AcademicTerm;
use App\Models\Campus;
use App\Models\Course;
use App\Models\CourseOffering;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\OfficeDepartment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CourseOfferingLecturerNameTest extends TestCase
{
    use RefreshDatabase;

    public function test_offering_accepts_typed_lecturer_name(): void
    {
        [$user, $course, $term] = $this->seedOfferingContext();
        Sanctum::actingAs($user);

        $this->postJson('/api/academic/offerings', [
            'course_id' => $course->id,
            'academic_term_id' => $term->id,
            'lecturer_name' => 'Dr. Ada Okonkwo',
            'section' => 'A',
            'capacity' => 80,
        ])
            ->assertCreated()
            ->assertJsonPath('lecturer_name', 'Dr. Ada Okonkwo')
            ->assertJsonPath('lecturer_display_name', 'Dr. Ada Okonkwo')
            ->assertJsonPath('faculty_staff_id', null);
    }

    public function test_display_name_falls_back_to_staff_user(): void
    {
        [, $course, $term, $lecturerStaff] = $this->seedOfferingContext();

        $offering = CourseOffering::query()->create([
            'course_id' => $course->id,
            'academic_term_id' => $term->id,
            'faculty_staff_id' => $lecturerStaff->id,
            'section' => 'A',
            'capacity' => 50,
        ]);

        $this->assertSame('Staff Lecturer', $offering->fresh('lecturer.user')->lecturer_display_name);
    }

    public function test_unset_capacity_is_unlimited(): void
    {
        [$user, $course, $term] = $this->seedOfferingContext();
        Sanctum::actingAs($user);

        $this->postJson('/api/academic/offerings', [
            'course_id' => $course->id,
            'academic_term_id' => $term->id,
            'section' => 'A',
        ])
            ->assertCreated()
            ->assertJsonPath('capacity', null);

        $offering = CourseOffering::query()->first();
        $this->assertTrue($offering->hasUnlimitedCapacity());
        $this->assertFalse($offering->isFull(999));
        $this->assertNull($offering->seatsLeft(999));

        $this->getJson('/api/academic/offerings')
            ->assertOk()
            ->assertJsonPath('0.unlimited', true)
            ->assertJsonPath('0.seats_left', null);
    }

    /**
     * @return array{0: User, 1: Course, 2: AcademicTerm, 3: Staff}
     */
    private function seedOfferingContext(): array
    {
        foreach (PermissionCatalog::all() as $perm) {
            Permission::query()->updateOrCreate(['key' => $perm['key']], $perm);
        }

        $campus = Campus::query()->create(['name' => 'Main', 'is_active' => true]);
        $faculty = Faculty::query()->create(['campus_id' => $campus->id, 'name' => 'Science']);
        $department = Department::query()->create(['faculty_id' => $faculty->id, 'name' => 'Chemistry']);
        $course = Course::query()->create([
            'department_id' => $department->id,
            'code' => 'CHE121',
            'title' => 'Introduction to Chemistry',
            'units' => 3,
            'course_type' => 'departmental',
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

        $role = Role::query()->create(['name' => 'Registry', 'slug' => 'registry-offerings', 'is_active' => true]);
        $role->permissions()->sync(
            Permission::query()->where('key', 'academic.offerings.manage')->pluck('id'),
        );
        $office = OfficeDepartment::query()->create(['name' => 'Registry', 'code' => 'REG', 'is_active' => true]);
        $office->syncNavKeys(['offerings']);

        $user = User::factory()->create(['status' => 'active']);
        $user->roles()->attach($role->id);
        Staff::query()->create([
            'user_id' => $user->id,
            'staff_number' => 'ST-OFF',
            'office_department_id' => $office->id,
        ]);

        $lecturerUser = User::factory()->create(['name' => 'Staff Lecturer', 'status' => 'active']);
        $lecturerStaff = Staff::query()->create([
            'user_id' => $lecturerUser->id,
            'staff_number' => 'ST-LEC',
        ]);

        return [$user->fresh(['roles.permissions', 'staff']), $course, $term, $lecturerStaff];
    }
}
