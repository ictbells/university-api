<?php

namespace Tests\Feature;

use App\Models\AcademicLevel;
use App\Models\AcademicSession;
use App\Models\AcademicTerm;
use App\Models\Hostel;
use App\Models\HostelBlock;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\Student;
use App\Models\User;
use App\Services\HostelRoomService;
use App\Services\HostelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class HostelLevelInventoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_only_sees_hostels_and_rooms_for_their_level(): void
    {
        [$student, $level100, $level200] = $this->studentWithLevels();
        $this->payTuitionPercent($student, 25);
        $this->openLevel($level100);

        $for100 = $this->makeHostel('100L Hall');
        $for200 = $this->makeHostel('200L Hall');
        $openToAll = $this->makeHostel('Mixed Hall');
        $mixedRooms = $this->makeHostel('Split Hall');

        app(HostelService::class)->syncHostelLevels($for100, [$level100->id]);
        app(HostelService::class)->syncHostelLevels($for200, [$level200->id]);
        app(HostelService::class)->syncHostelLevels($mixedRooms, [$level100->id, $level200->id]);

        $room100 = $this->addRoom($for100, '101');
        $this->addRoom($for200, '201');
        $this->addRoom($openToAll, '301');
        $split100 = $this->addRoom($mixedRooms, 'A1');
        $split200 = $this->addRoom($mixedRooms, 'B1');
        app(HostelService::class)->syncRoomLevels($split100, [$level100->id]);
        app(HostelService::class)->syncRoomLevels($split200, [$level200->id]);

        $snapshot = app(HostelService::class)->studentSnapshot($student->fresh());
        $this->assertTrue($snapshot['can_select']);
        $names = collect($snapshot['hostels'])->pluck('name')->all();
        $this->assertContains('100L Hall', $names);
        $this->assertContains('Mixed Hall', $names);
        $this->assertContains('Split Hall', $names);
        $this->assertNotContains('200L Hall', $names);

        $split = collect($snapshot['hostels'])->firstWhere('name', 'Split Hall');
        $roomNumbers = collect($split['blocks'][0]['rooms'] ?? [])->pluck('number')->all();
        $this->assertContains('A1', $roomNumbers);
        $this->assertNotContains('B1', $roomNumbers);

        $this->assertSame('101', collect($snapshot['hostels'])->firstWhere('name', '100L Hall')['blocks'][0]['rooms'][0]['number']);
        $this->assertSame($room100->id, collect($snapshot['hostels'])->firstWhere('name', '100L Hall')['blocks'][0]['rooms'][0]['id']);
    }

    public function test_request_bed_rejects_room_assigned_to_another_level(): void
    {
        [$student, $level100, $level200] = $this->studentWithLevels();
        $this->payTuitionPercent($student, 25);
        $this->openLevel($level100);

        $hostel = $this->makeHostel('Split Hall');
        app(HostelService::class)->syncHostelLevels($hostel, [$level100->id, $level200->id]);
        $room200 = $this->addRoom($hostel, 'B1');
        app(HostelService::class)->syncRoomLevels($room200, [$level200->id]);
        $bed = $room200->beds()->first();

        try {
            app(HostelService::class)->requestBed($student->fresh(), $bed);
            $this->fail('Expected level mismatch to fail.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('level', strtolower($e->errors()['hostel_bed_id'][0] ?? ''));
        }
    }

    public function test_unassigned_hostel_stays_visible_to_every_level_in_the_category(): void
    {
        [$student, $level100] = $this->studentWithLevels();
        $this->payTuitionPercent($student, 25);
        $this->openLevel($level100);
        $this->addRoom($this->makeHostel('Open Hall'), '101');

        $grouped = app(HostelService::class)->academicLevelsByCategory();
        $this->assertSame('100 Level', $grouped['undergraduate'][0]['name']);
        $this->assertSame('200 Level', $grouped['undergraduate'][1]['name']);

        $snapshot = app(HostelService::class)->studentSnapshot($student->fresh());
        $this->assertContains('Open Hall', collect($snapshot['hostels'])->pluck('name')->all());
    }

    /**
     * @return array{0: Student, 1: AcademicLevel, 2: AcademicLevel}
     */
    private function studentWithLevels(): array
    {
        $session = AcademicSession::query()->create(['label' => '2025/2026']);
        $term = AcademicTerm::query()->create([
            'academic_session_id' => $session->id,
            'name' => 'First',
            'session_label' => '2025/2026',
            'is_current' => true,
        ]);
        Setting::setValue('current_term_id', (string) $term->id);

        $level100 = AcademicLevel::query()->create([
            'name' => '100 Level',
            'code' => '100',
            'study_level' => 'undergraduate',
            'sort_order' => 1,
            'is_active' => true,
        ]);
        $level200 = AcademicLevel::query()->create([
            'name' => '200 Level',
            'code' => '200',
            'study_level' => 'undergraduate',
            'sort_order' => 2,
            'is_active' => true,
        ]);
        $student = Student::query()->create([
            'user_id' => User::factory()->create(['status' => 'active'])->id,
            'first_name' => 'Ada',
            'last_name' => 'Okoye',
            'matric_number' => 'BUT/2026/H/0100',
            'current_level' => 100,
            'study_level' => 'undergraduate',
            'gender' => 'female',
            'status' => 'active',
        ]);

        return [$student, $level100, $level200];
    }

    private function openLevel(AcademicLevel $level): void
    {
        $termId = AcademicTerm::query()->where('is_current', true)->value('id');
        app(HostelService::class)->syncLevelWindows('undergraduate', [[
            'academic_level_id' => $level->id,
            'is_active' => true,
        ]], $termId);
    }

    private function makeHostel(string $name): Hostel
    {
        return Hostel::query()->create([
            'name' => $name,
            'category' => 'undergraduate',
            'gender' => 'female',
            'is_active' => true,
            'due_required' => false,
        ]);
    }

    private function addRoom(Hostel $hostel, string $number)
    {
        $block = $hostel->blocks()->first()
            ?: HostelBlock::query()->create(['hostel_id' => $hostel->id, 'name' => 'Block A']);

        return app(HostelRoomService::class)->storeRoom($block, [
            'number' => $number,
            'capacity' => 1,
            'bedding_type' => 'single',
        ]);
    }

    private function payTuitionPercent(Student $student, float $percent): void
    {
        $sessionId = AcademicTerm::query()->where('is_current', true)->value('academic_session_id');
        Invoice::query()->create([
            'number' => 'INV-HOSTEL-LVL-'.$student->id.'-'.(int) $percent,
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
            'level_code' => (string) $student->current_level,
        ]);
    }
}
