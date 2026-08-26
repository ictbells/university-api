<?php

namespace Tests\Feature;

use App\Models\Hostel;
use App\Models\HostelBed;
use App\Models\HostelBlock;
use App\Models\HostelRoom;
use App\Models\Student;
use App\Models\User;
use App\Services\HostelRoomService;
use App\Services\HostelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HostelBunkBeddingTest extends TestCase
{
    use RefreshDatabase;

    public function test_bunk_room_labels_lower_and_upper_beds(): void
    {
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
            'capacity' => 4,
            'bedding_type' => 'bunk',
        ]);

        $labels = $room->beds()->orderBy('id')->pluck('label')->all();
        $this->assertSame(['Lower 1', 'Upper 1', 'Lower 2', 'Upper 2'], $labels);
        $this->assertSame(
            ['lower', 'upper', 'lower', 'upper'],
            $room->beds()->orderBy('id')->pluck('bunk_position')->all()
        );

        $formatted = app(HostelRoomService::class)->formatRoom($room->fresh('beds'), true);
        $this->assertTrue($formatted['uses_bunks']);
        $this->assertSame(2, $formatted['available_bunk_summary']['lower']);
        $this->assertSame(2, $formatted['available_bunk_summary']['upper']);
        $this->assertStringContainsString('lower', $formatted['available_bunk_summary']['text']);
        $this->assertSame('Lower bunk 1', $formatted['beds'][0]['display_label']);
    }

    public function test_student_selection_includes_bunk_beds(): void
    {
        $hostel = Hostel::query()->create([
            'name' => 'Hall B',
            'category' => 'undergraduate',
            'gender' => 'mixed',
            'is_active' => true,
            'due_required' => false,
        ]);
        $block = HostelBlock::query()->create([
            'hostel_id' => $hostel->id,
            'name' => 'Block B',
        ]);
        app(HostelRoomService::class)->storeRoom($block, [
            'number' => '202',
            'capacity' => 2,
            'bedding_type' => 'bunk',
        ]);

        $user = User::factory()->create(['status' => 'active']);
        $student = Student::query()->create([
            'user_id' => $user->id,
            'first_name' => 'Ada',
            'last_name' => 'Okoye',
            'gender' => 'female',
            'current_level' => 100,
            'study_level' => 'undergraduate',
            'status' => 'active',
        ]);

        $hostels = app(HostelService::class)->selectionHostelsForStudent($student);
        $this->assertNotEmpty($hostels);
        $room = $hostels[0]['blocks'][0]['rooms'][0];
        $this->assertTrue($room['uses_bunks']);
        $this->assertCount(2, $room['beds']);
        $this->assertSame('Lower bunk 1', $room['beds'][0]['display_label']);
        $this->assertSame('available', $room['beds'][0]['status']);
    }

    public function test_switching_to_bunk_relabels_existing_beds(): void
    {
        $hostel = Hostel::query()->create([
            'name' => 'Hall C',
            'category' => 'undergraduate',
            'gender' => 'male',
            'is_active' => true,
            'due_required' => false,
        ]);
        $block = HostelBlock::query()->create([
            'hostel_id' => $hostel->id,
            'name' => 'Block C',
        ]);
        $rooms = app(HostelRoomService::class);
        $room = $rooms->storeRoom($block, [
            'number' => '303',
            'capacity' => 2,
            'bedding_type' => 'single',
        ]);
        $this->assertSame(['1', '2'], $room->beds()->orderBy('id')->pluck('label')->all());

        $updated = $rooms->updateRoom($room, ['bedding_type' => 'bunk']);
        $this->assertSame(['Lower 1', 'Upper 1'], $updated->beds()->orderBy('id')->pluck('label')->all());
        $this->assertTrue(
            HostelBed::query()->where('hostel_room_id', $updated->id)->where('bunk_position', 'lower')->exists()
        );
    }
}
