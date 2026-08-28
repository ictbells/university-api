<?php

namespace Tests\Feature;

use App\Models\AcademicLevel;
use App\Models\AcademicSession;
use App\Models\AcademicTerm;
use App\Models\Campus;
use App\Models\Course;
use App\Models\CourseOffering;
use App\Models\Department;
use App\Models\Enrollment;
use App\Models\Faculty;
use App\Models\Grade;
use App\Models\Program;
use App\Models\Student;
use App\Models\StudentProgrammeChange;
use App\Models\User;
use App\Support\GradeStatus;
use App\Support\TranscriptBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProgrammeChangeCgpaTest extends TestCase
{
    use RefreshDatabase;

    public function test_cross_college_300_to_200_cgpa_keeps_only_old_100l(): void
    {
        [$student, $old, $new, $levels, $terms] = $this->studentOnOldProgramme(300);
        $this->mapAndGrade($student, $old, $levels, $terms, [
            100 => ['letter' => 'A', 'points' => 5, 'score' => 72],
            200 => ['letter' => 'C', 'points' => 3, 'score' => 55],
            300 => ['letter' => 'F', 'points' => 0, 'score' => 20],
        ]);
        $this->recordChange($student, $old, $new, 300, 200, sameCollege: false);

        $transcript = TranscriptBuilder::forStudent($student->fresh(['programmeChanges']), true);

        $this->assertEquals(5.0, (float) $transcript['cgpa']);
        $this->assertSame(3, (int) $transcript['total_credits']);
        $this->assertNotNull($transcript['cgpa_note']);

        $codes = collect($transcript['rows'])->pluck('course.code')->all();
        $this->assertSame(['OLD100'], $codes);
        $this->assertNull(collect($transcript['terms'])->firstWhere('name', '200L term'));
        $this->assertNull(collect($transcript['terms'])->firstWhere('name', '300L term'));
    }

    public function test_new_programme_results_join_cgpa_after_cross_college_change(): void
    {
        [$student, $old, $new, $levels, $terms] = $this->studentOnOldProgramme(300);
        $this->mapAndGrade($student, $old, $levels, $terms, [
            100 => ['letter' => 'A', 'points' => 5, 'score' => 72],
            200 => ['letter' => 'C', 'points' => 3, 'score' => 55],
        ]);
        $this->recordChange($student, $old, $new, 300, 200, sameCollege: false);

        $level200 = $levels[200];
        $course = Course::query()->create([
            'department_id' => $new->department_id,
            'code' => 'NEW201',
            'title' => 'New programme 200L',
            'units' => 3,
            'course_type' => 'departmental',
            'status' => 'core',
        ]);
        $new->courses()->attach($course->id, ['academic_level_id' => $level200->id, 'bucket' => 'departmental']);
        $current = AcademicTerm::query()->where('is_current', true)->firstOrFail();
        $offering = CourseOffering::query()->create([
            'course_id' => $course->id,
            'academic_term_id' => $current->id,
            'section' => 'A',
        ]);
        $this->gradeOffering($student, $offering, 'B', 4, 62, now());

        $transcript = TranscriptBuilder::forStudent($student->fresh(['programmeChanges']), true);

        $this->assertEquals(4.5, (float) $transcript['cgpa']);
        $this->assertSame(6, (int) $transcript['total_credits']);
        $codes = collect($transcript['rows'])->pluck('course.code')->all();
        $this->assertEqualsCanonicalizing(['OLD100', 'NEW201'], $codes);
        $this->assertNotContains('OLD200', $codes);
    }

    public function test_same_college_change_keeps_every_grade_in_cgpa(): void
    {
        [$student, $old, $new, $levels, $terms] = $this->studentOnOldProgramme(200, otherCollege: false);
        $this->mapAndGrade($student, $old, $levels, $terms, [
            100 => ['letter' => 'A', 'points' => 5, 'score' => 72],
            200 => ['letter' => 'C', 'points' => 3, 'score' => 55],
        ]);
        $this->recordChange($student, $old, $new, 200, 200, sameCollege: true);

        $transcript = TranscriptBuilder::forStudent($student->fresh(['programmeChanges']), true);

        $this->assertEquals(4.0, (float) $transcript['cgpa']);
        $this->assertSame(6, (int) $transcript['total_credits']);
        $this->assertNull($transcript['cgpa_note']);
    }

    public function test_cross_college_200_to_100_drops_all_old_programme_grades(): void
    {
        [$student, $old, $new, $levels, $terms] = $this->studentOnOldProgramme(200, otherCollege: true);
        $this->mapAndGrade($student, $old, $levels, $terms, [
            100 => ['letter' => 'A', 'points' => 5, 'score' => 72],
            200 => ['letter' => 'B', 'points' => 4, 'score' => 62],
        ]);
        $this->recordChange($student, $old, $new, 200, 100, sameCollege: false);

        $transcript = TranscriptBuilder::forStudent($student->fresh(['programmeChanges']), true);

        $this->assertSame(0, (int) $transcript['cgpa']);
        $this->assertSame(0, (int) $transcript['total_credits']);
        $this->assertSame([], $transcript['rows']);
        $this->assertSame([], $transcript['terms']);
    }

    /**
     * @return array{0: Student, 1: Program, 2: Program, 3: array<int, AcademicLevel>, 4: array<int, AcademicTerm>}
     */
    private function studentOnOldProgramme(int $level, bool $otherCollege = true): array
    {
        $campus = Campus::query()->create(['name' => 'Main', 'is_active' => true]);
        $oldCollege = Faculty::query()->create(['campus_id' => $campus->id, 'name' => 'College of Computing']);
        $oldDept = Department::query()->create(['faculty_id' => $oldCollege->id, 'name' => 'Computer Science']);
        $old = Program::query()->create([
            'department_id' => $oldDept->id,
            'name' => 'B.Sc Computer Science',
            'code' => 'BSC-CS',
            'award_type' => 'B.Sc',
            'study_level' => 'undergraduate',
            'entry_modes' => ['utme'],
            'duration_years' => 4,
            'is_active' => true,
        ]);
        $newFaculty = $otherCollege
            ? Faculty::query()->create(['campus_id' => $campus->id, 'name' => 'College of Engineering'])
            : $oldCollege;
        $newDept = Department::query()->create([
            'faculty_id' => $newFaculty->id,
            'name' => $otherCollege ? 'Electrical' : 'Cybersecurity',
        ]);
        $new = Program::query()->create([
            'department_id' => $newDept->id,
            'name' => $otherCollege ? 'B.Eng Electrical' : 'B.Sc Cybersecurity',
            'code' => $otherCollege ? 'BENG-EEE' : 'BSC-CYB',
            'award_type' => $otherCollege ? 'B.Eng' : 'B.Sc',
            'study_level' => 'undergraduate',
            'entry_modes' => ['utme'],
            'duration_years' => 4,
            'is_active' => true,
        ]);
        $levels = [];
        foreach ([100, 200, 300] as $code) {
            $levels[$code] = AcademicLevel::query()->create([
                'name' => $code.' Level',
                'code' => (string) $code,
                'study_level' => 'undergraduate',
                'sort_order' => (int) ($code / 100),
                'is_active' => true,
            ]);
        }
        $terms = [];
        foreach ([100 => '2023/2024', 200 => '2024/2025', 300 => '2025/2026'] as $code => $label) {
            $session = AcademicSession::query()->create([
                'label' => $label,
                'starts_on' => ($code === 100 ? '2023-10-01' : ($code === 200 ? '2024-10-01' : '2025-10-01')),
                'ends_on' => ($code === 100 ? '2024-09-30' : ($code === 200 ? '2025-09-30' : '2026-09-30')),
            ]);
            $terms[$code] = AcademicTerm::query()->create([
                'academic_session_id' => $session->id,
                'name' => $code.'L term',
                'session_label' => $label,
                'is_current' => $code === 300,
            ]);
        }
        $user = User::factory()->create(['status' => 'active']);
        $student = Student::query()->create([
            'user_id' => $user->id,
            'program_id' => $old->id,
            'first_name' => 'Ada',
            'last_name' => 'Okoye',
            'current_level' => $level,
            'study_level' => 'undergraduate',
            'status' => 'active',
        ]);

        return [$student, $old, $new, $levels, $terms];
    }

    /**
     * @param  array<int, AcademicLevel>  $levels
     * @param  array<int, AcademicTerm>  $terms
     * @param  array<int, array{letter: string, points: float|int, score: float|int}>  $byLevel
     */
    private function mapAndGrade(Student $student, Program $program, array $levels, array $terms, array $byLevel): void
    {
        foreach ($byLevel as $code => $result) {
            $course = Course::query()->create([
                'department_id' => $program->department_id,
                'code' => 'OLD'.$code,
                'title' => 'Old programme '.$code,
                'units' => 3,
                'course_type' => 'departmental',
                'status' => 'core',
            ]);
            $program->courses()->attach($course->id, [
                'academic_level_id' => $levels[$code]->id,
                'bucket' => 'departmental',
            ]);
            $offering = CourseOffering::query()->create([
                'course_id' => $course->id,
                'academic_term_id' => $terms[$code]->id,
                'section' => 'A',
            ]);
            $this->gradeOffering(
                $student,
                $offering,
                $result['letter'],
                $result['points'],
                $result['score'],
                now()->subYears(4 - (int) ($code / 100)),
            );
        }
    }

    private function gradeOffering(
        Student $student,
        CourseOffering $offering,
        string $letter,
        float|int $points,
        float|int $score,
        $registeredAt,
    ): void {
        $enrollment = Enrollment::query()->create([
            'student_id' => $student->id,
            'course_offering_id' => $offering->id,
            'status' => 'enrolled',
            'registered_at' => $registeredAt,
        ]);
        Grade::query()->create([
            'enrollment_id' => $enrollment->id,
            'student_id' => $student->id,
            'course_offering_id' => $offering->id,
            'sitting' => 'main',
            'letter' => $letter,
            'points' => $points,
            'score' => $score,
            'status' => GradeStatus::RELEASED,
        ]);
    }

    private function recordChange(
        Student $student,
        Program $from,
        Program $to,
        int $fromLevel,
        int $toLevel,
        bool $sameCollege,
    ): void {
        StudentProgrammeChange::query()->create([
            'student_id' => $student->id,
            'from_program_id' => $from->id,
            'to_program_id' => $to->id,
            'from_level' => $fromLevel,
            'to_level' => $toLevel,
            'same_college' => $sameCollege,
        ]);
        $student->update([
            'program_id' => $to->id,
            'current_level' => $toLevel,
        ]);
    }
}
