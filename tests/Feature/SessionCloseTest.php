<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\AcademicTerm;
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
use App\Services\SessionCloseService;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SessionCloseTest extends TestCase
{
    use RefreshDatabase;

    private User $staffUser;

    private Program $ugProgram;

    private Program $pgProgram;

    private AcademicSession $session;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (PermissionCatalog::all() as $perm) {
            Permission::query()->updateOrCreate(['key' => $perm['key']], $perm);
        }

        $role = Role::query()->create(['name' => 'Registrar', 'slug' => 'registrar']);
        $role->permissions()->sync(
            Permission::query()->whereIn('key', ['academic.sessions.manage', 'academic.sessions.close'])->pluck('id'),
        );

        $this->staffUser = User::factory()->create(['status' => 'active']);
        $this->staffUser->roles()->attach($role->id);
        $office = OfficeDepartment::query()->create([
            'name' => 'Academic Affairs',
            'code' => 'AA',
            'is_active' => true,
        ]);
        $office->syncNavKeys(['sessions']);
        Staff::query()->create([
            'user_id' => $this->staffUser->id,
            'staff_number' => 'STF001',
            'office_department_id' => $office->id,
        ]);

        $campus = Campus::query()->create(['name' => 'Main', 'is_active' => true]);
        $faculty = Faculty::query()->create(['campus_id' => $campus->id, 'name' => 'Science']);
        $department = Department::query()->create(['faculty_id' => $faculty->id, 'name' => 'CS']);

        $this->ugProgram = Program::query()->create([
            'department_id' => $department->id,
            'name' => 'B.Sc CS',
            'code' => 'BSC-CS',
            'award_type' => 'B.Sc',
            'study_level' => 'undergraduate',
            'entry_modes' => ['utme'],
            'duration_years' => 4,
            'is_active' => true,
        ]);
        $this->pgProgram = Program::query()->create([
            'department_id' => $department->id,
            'name' => 'M.Sc CS',
            'code' => 'MSC-CS',
            'award_type' => 'M.Sc',
            'study_level' => 'postgraduate',
            'entry_modes' => ['pg'],
            'duration_years' => 2,
            'is_active' => true,
        ]);

        $this->session = AcademicSession::query()->create([
            'label' => '2024/2025',
            'starts_on' => '2024-10-01',
            'ends_on' => '2025-09-30',
        ]);
        AcademicTerm::query()->create([
            'academic_session_id' => $this->session->id,
            'name' => 'First',
            'session_label' => '2024/2025',
            'is_current' => true,
        ]);
    }

    public function test_preview_and_close_promote_undergraduate_and_postgraduate_students(): void
    {
        $ug100 = $this->makeStudent($this->ugProgram, 100);
        $ug400 = $this->makeStudent($this->ugProgram, 400);
        $pg1 = $this->makeStudent($this->pgProgram, 1, 'postgraduate');
        $inactive = $this->makeStudent($this->ugProgram, 200, 'undergraduate', 'withdrawn');
        $noProgram = Student::query()->create([
            'user_id' => User::factory()->create()->id,
            'first_name' => 'No',
            'last_name' => 'Program',
            'current_level' => 100,
            'study_level' => 'undergraduate',
            'status' => 'active',
        ]);

        Sanctum::actingAs($this->staffUser);

        $preview = $this->getJson("/api/academic/sessions/{$this->session->id}/close-preview")
            ->assertSuccessful()
            ->json();

        $this->assertSame(2, $preview['promoted_count']);
        $this->assertSame(1, $preview['skipped_final_count']);
        $this->assertSame(1, $preview['skipped_inactive_count']);
        $this->assertSame(1, $preview['skipped_no_program_count']);

        $response = $this->postJson("/api/academic/sessions/{$this->session->id}/close")
            ->assertSuccessful()
            ->json();

        $this->assertSame(2, $response['promoted_count']);

        $this->assertSame(200, $ug100->fresh()->current_level);
        $this->assertSame(400, $ug400->fresh()->current_level);
        $this->assertSame(2, $pg1->fresh()->current_level);
        $this->assertSame('withdrawn', $inactive->fresh()->status);
        $this->assertNotNull($this->session->fresh()->closed_at);
        $this->assertFalse((bool) AcademicTerm::query()->where('academic_session_id', $this->session->id)->where('is_current', true)->exists());

        $this->postJson("/api/academic/sessions/{$this->session->id}/close")
            ->assertStatus(422);
    }

    public function test_auto_close_runs_for_flagged_sessions_past_end_date(): void
    {
        $student = $this->makeStudent($this->ugProgram, 300);

        $session = AcademicSession::query()->create([
            'label' => '2023/2024',
            'ends_on' => now()->subDay()->toDateString(),
            'auto_close_on_end' => true,
        ]);

        app(SessionCloseService::class)->close($session, 'auto', null);

        $this->assertSame(400, $student->fresh()->current_level);
        $this->assertNotNull($session->fresh()->closed_at);
    }

    public function test_auto_close_skips_sessions_without_flag(): void
    {
        $student = $this->makeStudent($this->ugProgram, 100);

        $session = AcademicSession::query()->create([
            'label' => '2022/2023',
            'ends_on' => now()->subDays(5)->toDateString(),
            'auto_close_on_end' => false,
        ]);

        $this->artisan('academic:sync-calendar', ['--date' => now()->toDateString()])
            ->assertSuccessful();

        $this->assertSame(100, $student->fresh()->current_level);
        $this->assertNull($session->fresh()->closed_at);
    }

    private function makeStudent(Program $program, int $level, string $studyLevel = 'undergraduate', string $status = 'active'): Student
    {
        return Student::query()->create([
            'user_id' => User::factory()->create()->id,
            'program_id' => $program->id,
            'first_name' => 'Test',
            'last_name' => 'Student'.$level,
            'current_level' => $level,
            'study_level' => $studyLevel,
            'status' => $status,
        ]);
    }
}
