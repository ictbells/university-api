<?php

namespace Tests\Feature;

use App\Models\AcademicLevel;
use App\Models\AcademicSession;
use App\Models\AcademicTerm;
use App\Models\Hostel;
use App\Models\HostelAllocation;
use App\Models\HostelBlock;
use App\Models\HostelLevelWindow;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\Student;
use App\Models\User;
use App\Services\HostelRoomService;
use App\Services\HostelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
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

    public function test_student_sees_open_when_academic_level_code_is_100L(): void
    {
        [$student, $level, $term] = $this->studentWithLevel('100L');

        app(HostelService::class)->syncLevelWindows('undergraduate', [[
            'academic_level_id' => $level->id,
            'is_active' => true,
        ]], $term->id);

        $snapshot = app(HostelService::class)->studentSnapshot($student);
        $this->assertTrue($snapshot['window_open']);
        $this->assertFalse($snapshot['tuition_ok']);
        $this->assertFalse($snapshot['can_select']);
    }

    public function test_open_window_still_blocks_select_until_25_percent_tuition(): void
    {
        [$student, $level, $term] = $this->studentWithLevel();

        app(HostelService::class)->syncLevelWindows('undergraduate', [[
            'academic_level_id' => $level->id,
            'is_active' => true,
        ]], $term->id);

        $hostels = app(HostelService::class);
        $closed = $hostels->studentSnapshot($student);
        $this->assertTrue($closed['window_open']);
        $this->assertFalse($closed['tuition_ok']);
        $this->assertFalse($closed['can_select']);

        $this->payTuitionPercent($student, 25);

        $open = $hostels->studentSnapshot($student->fresh());
        $this->assertTrue($open['window_open']);
        $this->assertTrue($open['tuition_ok']);
        $this->assertTrue($open['can_select']);
        $this->assertEquals(25.0, $open['tuition_percent']);
    }

    public function test_open_level_is_live_even_when_session_dates_are_in_the_past_or_future(): void
    {
        [$student, $level, $term] = $this->studentWithLevel();
        $term->session->update([
            'starts_on' => now()->addMonth()->toDateString(),
            'ends_on' => now()->addMonths(10)->toDateString(),
        ]);

        $hostels = app(HostelService::class);
        $hostels->syncLevelWindows('undergraduate', [[
            'academic_level_id' => $level->id,
            'is_active' => true,
        ]], $term->id);

        $this->assertTrue($hostels->isLevelOpen('undergraduate', $student));
        $snapshot = $hostels->studentSnapshot($student->fresh());
        $this->assertTrue($snapshot['window_open']);
        $this->assertNull($snapshot['window']['opens_at']);
        $this->assertNull($snapshot['window']['closes_at']);

        $term->session->update([
            'starts_on' => now()->subYear()->toDateString(),
            'ends_on' => now()->subDay()->toDateString(),
        ]);
        $hostels = app(HostelService::class);
        $this->assertTrue($hostels->isLevelOpen('undergraduate', $student->fresh()));
        $this->assertTrue($hostels->studentSnapshot($student->fresh())['window_open']);
    }

    public function test_hostel_window_closes_when_academic_session_is_closed(): void
    {
        [$student, $level, $term] = $this->studentWithLevel();
        $term->session->update([
            'starts_on' => now()->subYear()->toDateString(),
            'ends_on' => now()->addMonth()->toDateString(),
            'closed_at' => now()->subHour(),
        ]);

        app(HostelService::class)->syncLevelWindows('undergraduate', [[
            'academic_level_id' => $level->id,
            'is_active' => true,
        ]], $term->id);

        $hostels = app(HostelService::class);
        $this->assertFalse($hostels->isLevelOpen('undergraduate', $student));
        $this->assertFalse($hostels->studentSnapshot($student)['window_open']);
    }

    public function test_staff_toggle_survives_a_stale_current_term_setting_after_session_close(): void
    {
        [$student, $level, $oldTerm] = $this->studentWithLevel('200');
        $student->update(['current_level' => 200]);
        $oldTerm->session->update(['closed_at' => now()->subHour()]);
        $oldTerm->update(['is_current' => false]);

        $hostels = app(HostelService::class);
        $hostels->syncLevelWindows('undergraduate', [[
            'academic_level_id' => $level->id,
            'is_active' => true,
        ]], $oldTerm->id);

        $newSession = AcademicSession::query()->create(['label' => '2026/2027']);
        $newTerm = AcademicTerm::query()->create([
            'academic_session_id' => $newSession->id,
            'name' => 'First',
            'session_label' => '2026/2027',
            'is_current' => true,
        ]);
        Setting::setValue('current_term_id', (string) $oldTerm->id);

        $hostels = app(HostelService::class);
        $staffRow = $hostels->levelWindows('undergraduate')->firstWhere('academic_level_id', $level->id);
        $this->assertTrue($staffRow['is_active']);
        $this->assertTrue($staffRow['is_open']);
        $this->assertTrue($hostels->studentSnapshot($student->fresh())['window_open']);
        $this->assertSame($newTerm->id, $hostels->currentTermId());
    }

    public function test_previous_session_allocation_does_not_block_the_new_session(): void
    {
        [$student, $level, $oldTerm] = $this->studentWithLevel('200');
        $student->update(['current_level' => 200, 'gender' => 'female']);

        $hostel = Hostel::query()->create([
            'name' => 'Hall A',
            'category' => 'undergraduate',
            'gender' => 'female',
            'is_active' => true,
            'due_required' => false,
        ]);
        $block = HostelBlock::query()->create([
            'hostel_id' => $hostel->id,
            'name' => 'Block A',
        ]);
        $room = app(HostelRoomService::class)->storeRoom($block, [
            'number' => '101',
            'capacity' => 1,
            'bedding_type' => 'single',
        ]);
        $bed = $room->beds()->first();
        $bed->update(['status' => 'occupied']);

        HostelAllocation::query()->create([
            'student_id' => $student->id,
            'hostel_bed_id' => $bed->id,
            'academic_term_id' => $oldTerm->id,
            'status' => 'allocated',
            'allocated_at' => now()->subYear(),
        ]);

        $oldTerm->update(['is_current' => false]);
        $newSession = AcademicSession::query()->create(['label' => '2026/2027']);
        $newTerm = AcademicTerm::query()->create([
            'academic_session_id' => $newSession->id,
            'name' => 'First',
            'session_label' => '2026/2027',
            'is_current' => true,
        ]);
        Setting::setValue('current_term_id', (string) $newTerm->id);

        app(HostelService::class)->syncLevelWindows('undergraduate', [[
            'academic_level_id' => $level->id,
            'is_active' => true,
        ]], $newTerm->id);
        $this->payTuitionPercent($student, 25);

        $snapshot = app(HostelService::class)->studentSnapshot($student->fresh());
        $this->assertTrue($snapshot['window_open']);
        $this->assertNull($snapshot['allocation']);
        $this->assertTrue($snapshot['can_select']);
        $this->assertSame('vacated', HostelAllocation::query()->first()->status);
        $this->assertSame('available', $bed->fresh()->status);
    }

    public function test_request_bed_requires_25_percent_tuition(): void
    {
        [$student, $level, $term] = $this->studentWithLevel();
        $student->update(['gender' => 'female']);

        app(HostelService::class)->syncLevelWindows('undergraduate', [[
            'academic_level_id' => $level->id,
            'is_active' => true,
        ]], $term->id);

        $hostel = Hostel::query()->create([
            'name' => 'Hall A',
            'category' => 'undergraduate',
            'gender' => 'female',
            'is_active' => true,
            'due_required' => false,
        ]);
        $block = HostelBlock::query()->create([
            'hostel_id' => $hostel->id,
            'name' => 'Block A',
        ]);
        $room = app(HostelRoomService::class)->storeRoom($block, [
            'number' => '101',
            'capacity' => 1,
            'bedding_type' => 'single',
        ]);
        $bed = $room->beds()->first();

        $hostels = app(HostelService::class);
        try {
            $hostels->requestBed($student->fresh(), $bed);
            $this->fail('Expected tuition validation to fail.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('25%', $e->errors()['hostel_bed_id'][0] ?? '');
        }

        $this->payTuitionPercent($student, 25);
        $allocation = $hostels->requestBed($student->fresh(), $bed);
        $this->assertSame('pending', $allocation->status);
    }

    public function test_jupeb_windows_use_jupeb_levels_not_undergraduate(): void
    {
        [, $ugLevel] = $this->studentWithLevel();
        $jupebLevel = AcademicLevel::query()->create([
            'name' => 'JUPEB Year 1',
            'code' => '100',
            'study_level' => 'jupeb',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $windows = app(HostelService::class)->levelWindows('jupeb');
        $ids = $windows->pluck('academic_level_id')->all();

        $this->assertContains($jupebLevel->id, $ids);
        $this->assertNotContains($ugLevel->id, $ids);
    }

    /**
     * @return array{0: Student, 1: AcademicLevel, 2: AcademicTerm}
     */
    private function studentWithLevel(string $levelCode = '100'): array
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
            'code' => $levelCode,
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

    private function payTuitionPercent(Student $student, float $percent): void
    {
        $sessionId = AcademicTerm::query()->where('is_current', true)->value('academic_session_id');
        Invoice::query()->create([
            'number' => 'INV-HOSTEL-'.$student->id.'-'.(int) $percent,
            'user_id' => $student->user_id,
            'student_id' => $student->id,
            'category' => 'tuition',
            'installment_percent' => $percent,
            'amount' => 10000,
            'full_amount' => 40000,
            'balance' => 0,
            'status' => 'paid',
            'wallet_allowed' => true,
            'academic_session_id' => $sessionId,
            'level_code' => $student->current_level !== null ? (string) $student->current_level : '100',
        ]);
    }
}
