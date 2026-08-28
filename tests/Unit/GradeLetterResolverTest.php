<?php

namespace Tests\Unit;

use App\Support\GradeLetterResolver;
use App\Support\GradeStatus;
use Database\Seeders\GradingScaleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GradeLetterResolverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GradingScaleSeeder::class);
    }

    public function test_resolves_letter_and_points_from_score(): void
    {
        $this->assertEquals(['letter' => 'A', 'grade_point' => 5.0], GradeLetterResolver::fromScore(70));
        $this->assertEquals(['letter' => 'B', 'grade_point' => 4.0], GradeLetterResolver::fromScore(69.99));
        $this->assertEquals(['letter' => 'F', 'grade_point' => 0.0], GradeLetterResolver::fromScore(20));
        $this->assertEquals(4.0, GradeLetterResolver::gradePointForLetter('B'));
    }

    public function test_ensures_default_scale_when_none_exists(): void
    {
        \App\Models\GradeBoundary::query()->delete();
        \App\Models\GradingScale::query()->delete();

        $this->assertEquals(['letter' => 'A', 'grade_point' => 5.0], GradeLetterResolver::fromScore(72));
        $this->assertNotNull(\App\Models\GradingScale::query()->where('is_default', true)->first());
    }

    public function test_lane_from_course_type(): void
    {
        $this->assertEquals(GradeStatus::LANE_GENERAL, GradeStatus::laneFromCourseType('general'));
        $this->assertEquals(GradeStatus::LANE_FACULTY, GradeStatus::laneFromCourseType('faculty'));
    }
}
