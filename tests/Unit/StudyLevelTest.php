<?php

namespace Tests\Unit;

use App\Models\Application;
use App\Models\Program;
use App\Models\Student;
use App\Support\StudyLevel;
use Tests\TestCase;

class StudyLevelTest extends TestCase
{
    public function test_jupeb_matric_beats_undergraduate_stamp(): void
    {
        $program = new Program([
            'name' => 'JUPEB Sciences',
            'code' => 'JUP-SCI',
            'study_level' => 'undergraduate',
            'entry_modes' => ['utme'],
        ]);
        $student = new Student([
            'study_level' => 'undergraduate',
            'current_level' => 100,
            'matric_number' => 'J/2026/000003',
        ]);
        $student->setRelation('program', $program);
        $student->setRelation('application', null);

        $this->assertSame(StudyLevel::JUPEB, StudyLevel::ofStudent($student));
    }

    public function test_jupeb_programme_name_beats_undergraduate_stamp(): void
    {
        $program = new Program([
            'name' => 'JUPEB Sciences',
            'award_type' => 'JUPEB',
            'study_level' => 'undergraduate',
            'entry_modes' => ['utme'],
        ]);
        $student = new Student([
            'study_level' => 'undergraduate',
            'current_level' => 100,
            'student_number' => 'TMP-1',
        ]);
        $student->setRelation('program', $program);
        $student->setRelation('application', null);

        $this->assertSame(StudyLevel::JUPEB, StudyLevel::ofStudent($student));
    }

    public function test_jupeb_application_beats_undergraduate_stamp(): void
    {
        $application = new Application(['entry_mode' => 'jupeb']);
        $student = new Student([
            'study_level' => 'undergraduate',
            'current_level' => 100,
        ]);
        $student->setRelation('application', $application);
        $student->setRelation('program', null);

        $this->assertSame(StudyLevel::JUPEB, StudyLevel::ofStudent($student));
    }

    public function test_undergraduate_matric_stays_undergraduate(): void
    {
        $program = new Program([
            'name' => 'Computer Science',
            'study_level' => 'undergraduate',
            'entry_modes' => ['utme'],
        ]);
        $student = new Student([
            'study_level' => 'undergraduate',
            'current_level' => 100,
            'matric_number' => '2026/000150',
        ]);
        $student->setRelation('program', $program);
        $student->setRelation('application', null);

        $this->assertSame(StudyLevel::UNDERGRADUATE, StudyLevel::ofStudent($student));
    }
}
