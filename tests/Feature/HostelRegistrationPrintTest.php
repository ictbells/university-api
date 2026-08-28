<?php

namespace Tests\Feature;

use App\Models\AcademicLevel;
use App\Models\AcademicSession;
use App\Models\AcademicTerm;
use App\Models\Campus;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\Hostel;
use App\Models\HostelAllocation;
use App\Models\HostelBlock;
use App\Models\Program;
use App\Models\Setting;
use App\Models\Student;
use App\Models\User;
use App\Services\HostelRoomService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class HostelRegistrationPrintTest extends TestCase
{
    use RefreshDatabase;

    public function test_allocated_student_can_print_hostel_registration_form(): void
    {
        [$student] = $this->allocatedStudent();
        Sanctum::actingAs($student->user);

        $html = $this->get('/api/me/hostel/print')->assertOk()->getContent();

        $this->assertStringContainsString('Hostel Registration Form', $html);
        $this->assertStringContainsString('2025/2026', $html);
        $this->assertStringContainsString('First', $html);
        $this->assertStringContainsString('08/09/2024', $html);
        $this->assertStringContainsString('2022/11578', $html);
        $this->assertStringContainsString('AGBOGHE, PRECIOUS OLUWANIFESI', $html);
        $this->assertStringContainsString('COMPUTER SCIENCE', $html);
        $this->assertStringContainsString('Female', $html);
        $this->assertStringContainsString('09020370707', $html);
        $this->assertStringContainsString('08027266741', $html);
        $this->assertStringContainsString('Allocated Hostel/Room: Female Silver Hall Wing A Room 2', $html);
        $this->assertStringContainsString("Hostel's Manager Signature", $html);
        $this->assertStringNotContainsString('Additional Information:', $html);
    }

    public function test_pending_allocation_can_print_hostel_registration_form(): void
    {
        [$student] = $this->allocatedStudent('pending');
        Sanctum::actingAs($student->user);

        $html = $this->get('/api/me/hostel/print')->assertOk()->getContent();
        $this->assertStringContainsString('Hostel Registration Form', $html);
        $this->assertStringContainsString('Allocated Hostel/Room: Female Silver Hall Wing A Room 2', $html);
    }

    public function test_student_without_allocation_cannot_print_hostel_registration_form(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        Student::query()->create([
            'user_id' => $user->id,
            'first_name' => 'Ada',
            'last_name' => 'Okoye',
            'matric_number' => 'BUT/2026/H/0001',
            'current_level' => 100,
            'study_level' => 'undergraduate',
            'status' => 'active',
        ]);
        Sanctum::actingAs($user);

        $this->getJson('/api/me/hostel/print')->assertStatus(422);
    }

    /**
     * @return array{0: Student}
     */
    private function allocatedStudent(string $status = 'allocated'): array
    {
        $session = AcademicSession::query()->create(['label' => '2025/2026']);
        $term = AcademicTerm::query()->create([
            'academic_session_id' => $session->id,
            'name' => 'First',
            'session_label' => '2025/2026',
            'is_current' => true,
        ]);
        Setting::setValue('current_term_id', (string) $term->id);
        AcademicLevel::query()->create([
            'name' => '300 Level',
            'code' => '300',
            'study_level' => 'undergraduate',
            'sort_order' => 3,
            'is_active' => true,
        ]);
        $campus = Campus::query()->create(['name' => 'Main', 'is_active' => true]);
        $faculty = Faculty::query()->create(['campus_id' => $campus->id, 'name' => 'Natural Sciences']);
        $department = Department::query()->create(['faculty_id' => $faculty->id, 'name' => 'Computer Science']);
        $program = Program::query()->create([
            'department_id' => $department->id,
            'name' => 'Computer Science',
            'code' => 'BSC-CSC',
            'award_type' => 'B.Sc',
            'study_level' => 'undergraduate',
            'entry_modes' => ['utme'],
            'duration_years' => 4,
            'is_active' => true,
        ]);
        $user = User::factory()->create(['status' => 'active']);
        $student = Student::query()->create([
            'user_id' => $user->id,
            'program_id' => $program->id,
            'first_name' => 'Precious',
            'middle_name' => 'Oluwanifesi',
            'last_name' => 'Agboghe',
            'gender' => 'female',
            'phone' => '09020370707',
            'next_of_kin_phone' => '08027266741',
            'matric_number' => '2022/11578',
            'current_level' => 300,
            'study_level' => 'undergraduate',
            'status' => 'active',
        ]);
        $hostel = Hostel::query()->create([
            'name' => 'Silver Hall',
            'category' => 'undergraduate',
            'gender' => 'female',
            'is_active' => true,
            'due_required' => false,
        ]);
        $block = HostelBlock::query()->create([
            'hostel_id' => $hostel->id,
            'name' => 'Wing A',
        ]);
        $room = app(HostelRoomService::class)->storeRoom($block, [
            'number' => '2',
            'capacity' => 1,
            'bedding_type' => 'single',
        ]);
        $bed = $room->beds()->first();
        HostelAllocation::query()->create([
            'student_id' => $student->id,
            'hostel_bed_id' => $bed->id,
            'academic_term_id' => $term->id,
            'status' => $status,
            'allocated_at' => '2024-09-08 09:00:00',
        ]);

        return [$student->fresh(['user', 'program'])];
    }
}
