<?php

namespace Tests\Unit;

use App\Models\Setting;
use App\Models\Student;
use App\Models\User;
use App\Support\Studentship;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentshipTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_student_is_current_and_alumni_is_not(): void
    {
        $active = $this->makeStudent(Studentship::STATUS_ACTIVE);
        $this->assertTrue(Studentship::isCurrent($active));
        $this->assertTrue(Studentship::canRegisterCourses($active));

        $alumni = $this->makeStudent(Studentship::STATUS_ALUMNI, now()->subYear()->toDateString(), now()->subDay()->toDateString());
        $this->assertFalse(Studentship::isCurrent($alumni));
        $this->assertFalse(Studentship::canRegisterCourses($alumni));
    }

    public function test_graduated_student_is_current_until_expiry_date(): void
    {
        $graduated = $this->makeStudent(
            Studentship::STATUS_GRADUATED,
            now()->subYear()->toDateString(),
            now()->addDay()->toDateString(),
        );
        $this->assertTrue(Studentship::isCurrent($graduated));
        $this->assertFalse(Studentship::canRegisterCourses($graduated));

        $expired = $this->makeStudent(
            Studentship::STATUS_GRADUATED,
            now()->subYears(2)->toDateString(),
            now()->toDateString(),
        );
        $this->assertFalse(Studentship::isCurrent($expired));
    }

    public function test_only_graduated_or_alumni_students_may_apply_for_another_programme(): void
    {
        $this->assertTrue(Studentship::canApplyForAnotherProgramme(null));
        $this->assertFalse(Studentship::canApplyForAnotherProgramme($this->makeStudent(Studentship::STATUS_ACTIVE)));
        $this->assertFalse(Studentship::canApplyForAnotherProgramme($this->makeStudent(Studentship::STATUS_SUSPENDED)));
        $this->assertFalse(Studentship::canApplyForAnotherProgramme($this->makeStudent(Studentship::STATUS_WITHDRAWN)));
        $this->assertTrue(Studentship::canApplyForAnotherProgramme(
            $this->makeStudent(Studentship::STATUS_GRADUATED, now()->subYear()->toDateString(), now()->addYear()->toDateString()),
        ));
        $this->assertTrue(Studentship::canApplyForAnotherProgramme(
            $this->makeStudent(Studentship::STATUS_ALUMNI, now()->subYears(3)->toDateString(), now()->subDay()->toDateString()),
        ));
    }

    public function test_expiry_date_uses_configured_years(): void
    {
        Setting::setValue(Studentship::YEARS_KEY, '3');
        $expires = Studentship::expiryDate(now()->startOfDay());
        $this->assertSame(now()->addYears(3)->toDateString(), $expires->toDateString());
    }

    private function makeStudent(string $status, ?string $graduatedAt = null, ?string $expiresAt = null): Student
    {
        return Student::query()->create([
            'user_id' => User::factory()->create(['status' => 'active'])->id,
            'first_name' => 'Ada',
            'last_name' => 'Okafor',
            'current_level' => 400,
            'study_level' => 'undergraduate',
            'status' => $status,
            'graduated_at' => $graduatedAt,
            'studentship_expires_at' => $expiresAt,
        ]);
    }
}
