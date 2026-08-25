<?php

namespace Tests\Feature;

use App\Models\AcademicLevel;
use App\Models\AcademicSession;
use App\Models\AcademicTerm;
use App\Models\HostelLevelWindow;
use App\Models\Setting;
use App\Models\Student;
use App\Models\User;
use App\Services\HostelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HostelLevelWindowTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_window_follows_current_term_not_a_stale_closed_window(): void
    {
        [$student, $level, $term] = $this->studentWithLevel();

        HostelLevelWindow::query()->create([
            'category' => 'undergraduate',
            'academic_level_id' => $level->id,
            'academic_term_id' => null,
            'is_active' => true,
            'opens_at' => now()->subMonths(2),
            'closes_at' => now()->subMonth(),
        ]);
        HostelLevelWindow::query()->create([
            'category' => 'undergraduate',
            'academic_level_id' => $level->id,
            'academic_term_id' => $term->id,
            'is_active' => true,
            'opens_at' => null,
            'closes_at' => null,
        ]);

        $hostels = app(HostelService::class);
        $this->assertTrue($hostels->isLevelOpen('undergraduate', $student));

        $staffRow = $hostels->levelWindows('undergraduate')->firstWhere('academic_level_id', $level->id);
        $this->assertTrue($staffRow['is_active']);
        $this->assertTrue($staffRow['is_open']);
        $this->assertTrue($hostels->studentSnapshot($student)['window_open']);
    }

    public function test_saving_open_level_clears_dates_so_students_see_open(): void
    {
        [$student, $level, $term] = $this->studentWithLevel();

        HostelLevelWindow::query()->create([
            'category' => 'undergraduate',
            'academic_level_id' => $level->id,
            'academic_term_id' => $term->id,
            'is_active' => true,
            'opens_at' => now()->subDays(10),
            'closes_at' => now()->subDay(),
        ]);

        $hostels = app(HostelService::class);
        $this->assertFalse($hostels->isLevelOpen('undergraduate', $student));

        $hostels->syncLevelWindows('undergraduate', [[
            'academic_level_id' => $level->id,
            'is_active' => true,
        ]], $term->id);

        $this->assertTrue($hostels->isLevelOpen('undergraduate', $student->fresh()));
        $this->assertTrue($hostels->studentSnapshot($student->fresh())['window_open']);
    }

    /**
     * @return array{0: Student, 1: AcademicLevel, 2: AcademicTerm}
     */
    private function studentWithLevel(): array
    {
        $session = AcademicSession::query()->create(['label' => '2025/2026']);
        $term = AcademicTerm::query()->create([
            'academic_session_id' => $session->id,
            'name' => 'First',
            'session_label' => '2025/2026',
            'is_current' => true,
        ]);
        Setting::setValue('current_term_id', (string) $term->id);
        $level = AcademicLevel::query()->create([
            'name' => '100 Level',
            'code' => '100',
            'study_level' => 'undergraduate',
            'sort_order' => 1,
            'is_active' => true,
        ]);
        $student = Student::query()->create([
            'user_id' => User::factory()->create(['status' => 'active'])->id,
            'first_name' => 'Ada',
            'last_name' => 'Okoye',
            'matric_number' => 'BUT/2026/H/0001',
            'current_level' => 100,
            'study_level' => 'undergraduate',
            'status' => 'active',
        ]);

        return [$student, $level, $term];
    }
}
