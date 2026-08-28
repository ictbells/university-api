<?php

namespace Tests\Unit;

use App\Models\Program;
use App\Models\Student;
use App\Support\LevelProgression;
use PHPUnit\Framework\TestCase;

class LevelProgressionTest extends TestCase
{
    public function test_undergraduate_final_and_next_levels(): void
    {
        $program = new Program([
            'study_level' => 'undergraduate',
            'duration_years' => 4,
        ]);

        $this->assertSame(400, LevelProgression::finalLevelForProgram($program));
        $this->assertSame(200, LevelProgression::nextLevel(100, $program));
        $this->assertSame(400, LevelProgression::nextLevel(300, $program));
        $this->assertNull(LevelProgression::nextLevel(400, $program));
        $this->assertTrue(LevelProgression::isFinalYear($this->studentAt(400, $program)));
        $this->assertFalse(LevelProgression::isFinalYear($this->studentAt(300, $program)));
    }

    public function test_postgraduate_final_and_next_levels(): void
    {
        $program = new Program([
            'study_level' => 'postgraduate',
            'duration_years' => 2,
        ]);

        $this->assertSame(2, LevelProgression::finalLevelForProgram($program));
        $this->assertSame(2, LevelProgression::nextLevel(1, $program));
        $this->assertNull(LevelProgression::nextLevel(2, $program));
    }

    public function test_jupeb_one_year_programme_stays_at_100(): void
    {
        $program = new Program([
            'study_level' => 'jupeb',
            'duration_years' => 1,
        ]);

        $this->assertSame(100, LevelProgression::finalLevelForProgram($program));
        $this->assertNull(LevelProgression::nextLevel(100, $program));
        $this->assertTrue(LevelProgression::isFinalYear($this->studentAt(100, $program)));
    }

    private function studentAt(int $level, Program $program): Student
    {
        $student = new Student(['current_level' => $level]);
        $student->setRelation('program', $program);

        return $student;
    }
}
