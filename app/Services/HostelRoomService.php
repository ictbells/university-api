<?php

namespace App\Services;

use App\Models\Hostel;
use App\Models\HostelAllocation;
use App\Models\HostelBed;
use App\Models\HostelBlock;
use App\Models\HostelRoom;
use App\Models\Student;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class HostelRoomService
{
    public function formatRoom(HostelRoom $room, bool $includeBeds = false): array
    {
        $room->loadMissing(['block.hostel']);

        $occupied = (int) ($room->occupied_beds ?? 0);
        $available = (int) ($room->available_beds ?? 0);
        $bedCount = (int) ($room->bed_count ?? 0);

        if ($includeBeds || ($room->occupied_beds === null && $room->relationLoaded('beds'))) {
            $room->loadMissing('beds');
            $beds = $room->beds;
            $occupied = $beds->where('status', 'occupied')->count();
            $available = $beds->where('status', 'available')->count();
            $bedCount = $beds->count();
        }

        $effectiveGender = $room->gender ? strtolower((string) $room->gender) : null;

        $payload = [
            'id' => $room->id,
            'hostel_block_id' => $room->hostel_block_id,
            'number' => $room->number,
            'capacity' => $room->capacity,
            'gender' => $room->gender,
            'is_active' => (bool) $room->is_active,
            'is_reserved' => (bool) $room->is_reserved,
            'reserve_note' => $room->reserve_note,
            'hostel_id' => $room->block?->hostel_id,
            'hostel_name' => $room->block?->hostel?->name,
            'hostel_gender' => $room->block?->hostel?->gender,
            'hostel_category' => $room->block?->hostel?->category,
            'block_name' => $room->block?->name,
            'bed_count' => $bedCount,
            'occupied_beds' => $occupied,
            'available_beds' => $available,
            'effective_gender' => $effectiveGender,
            'gender_label' => $effectiveGender ? ucfirst($effectiveGender) : 'Unassigned',
        ];

        if ($includeBeds) {
            $room->loadMissing('beds');
            $payload['beds'] = $room->beds->map(fn (HostelBed $bed) => [
                'id' => $bed->id,
                'label' => $bed->label,
                'status' => $bed->status,
            ])->values();
        }

        return $payload;
    }

    public function syncBedsToCapacity(HostelRoom $room): HostelRoom
    {
        $room->load('beds');
        $capacity = max(1, (int) $room->capacity);
        $occupiedCount = $room->beds->where('status', 'occupied')->count();

        if ($capacity < $occupiedCount) {
            throw ValidationException::withMessages([
                'capacity' => "Capacity cannot be less than occupied beds ({$occupiedCount}).",
            ]);
        }

        $beds = $room->beds->sortBy(fn (HostelBed $bed) => (int) $bed->label)->values();
        $targetStatus = $this->bedStatusForRoom($room);

        if ($beds->count() < $capacity) {
            $nextLabel = 1;
            $usedLabels = $beds->pluck('label')->map(fn ($label) => (int) $label)->all();
            while (in_array($nextLabel, $usedLabels, true)) {
                $nextLabel++;
            }

            for ($i = $beds->count(); $i < $capacity; $i++) {
                while (in_array($nextLabel, $usedLabels, true)) {
                    $nextLabel++;
                }
                $room->beds()->create([
                    'label' => (string) $nextLabel,
                    'status' => $targetStatus,
                ]);
                $usedLabels[] = $nextLabel;
                $nextLabel++;
            }
        }

        if ($beds->count() > $capacity) {
            $removable = $room->beds()
                ->whereIn('status', ['available', 'disabled'])
                ->orderByDesc('label')
                ->get();

            $toRemove = $beds->count() - $capacity;
            foreach ($removable->take($toRemove) as $bed) {
                $bed->delete();
            }

            if ($room->beds()->count() > $capacity) {
                throw ValidationException::withMessages([
                    'capacity' => 'Reduce occupied or reserved beds before lowering capacity.',
                ]);
            }
        }

        return $room->fresh('beds');
    }

    public function bedStatusForRoom(HostelRoom $room): string
    {
        if (! $room->is_active) {
            return 'disabled';
        }
        if ($room->is_reserved) {
            return 'reserved';
        }

        return 'available';
    }

    public function applyRoomAvailability(HostelRoom $room): void
    {
        $targetStatus = $this->bedStatusForRoom($room);
        $pendingBedIds = HostelAllocation::query()
            ->where('status', 'pending')
            ->whereHas('bed', fn ($query) => $query->where('hostel_room_id', $room->id))
            ->pluck('hostel_bed_id');

        foreach ($room->beds as $bed) {
            if ($bed->status === 'occupied' || $pendingBedIds->contains($bed->id)) {
                continue;
            }
            $bed->update(['status' => $targetStatus]);
        }
    }

    public function roomEffectiveGender(HostelRoom $room): ?string
    {
        if ($room->gender) {
            return strtolower((string) $room->gender);
        }

        $room->loadMissing('beds');
        if ($room->beds->whereIn('status', ['occupied', 'reserved'])->isEmpty()) {
            return null;
        }

        $occupantGender = HostelAllocation::query()
            ->whereIn('status', ['allocated', 'pending'])
            ->whereHas('bed', fn ($query) => $query->where('hostel_room_id', $room->id))
            ->with('student:id,gender')
            ->get()
            ->pluck('student.gender')
            ->filter()
            ->map(fn ($gender) => strtolower((string) $gender))
            ->unique()
            ->values();

        return $occupantGender->count() === 1 ? $occupantGender->first() : null;
    }

    public function assertRoomGenderMatch(Student $student, HostelRoom $room, Hostel $hostel): void
    {
        $studentGender = strtolower((string) $student->gender);
        if (! $studentGender || strtolower((string) $hostel->gender) !== 'mixed') {
            return;
        }

        $roomGender = $this->roomEffectiveGender($room);
        if ($roomGender && $roomGender !== $studentGender) {
            throw ValidationException::withMessages([
                'hostel_bed_id' => 'This room is assigned to '.$roomGender.' students. The selected student is '.$studentGender.'.',
            ]);
        }
    }

    public function lockRoomGenderFromStudent(HostelRoom $room, Hostel $hostel, Student $student): void
    {
        if (strtolower((string) $hostel->gender) !== 'mixed' || $room->gender) {
            return;
        }

        $gender = strtolower((string) $student->gender);
        if ($gender) {
            $room->update(['gender' => $gender]);
        }
    }

    public function resetRoomGenderIfEmpty(HostelRoom $room, Hostel $hostel): void
    {
        if (strtolower((string) $hostel->gender) !== 'mixed') {
            return;
        }

        $hasOccupants = HostelAllocation::query()
            ->whereIn('status', ['allocated', 'pending'])
            ->whereHas('bed', fn ($query) => $query->where('hostel_room_id', $room->id))
            ->exists();

        if (! $hasOccupants) {
            $room->update(['gender' => null]);
        }
    }

    public function storeBlock(Hostel $hostel, array $data): HostelBlock
    {
        return $hostel->blocks()->create([
            'name' => $data['name'],
        ]);
    }

    public function updateBlock(HostelBlock $block, array $data): HostelBlock
    {
        $block->update(['name' => $data['name']]);

        return $block->fresh(['rooms.beds', 'hostel']);
    }

    public function occupiedBedCountForRoom(HostelRoom $room): int
    {
        return $room->beds()->where('status', 'occupied')->count();
    }

    public function occupiedBedCountForBlock(HostelBlock $block): int
    {
        return HostelBed::query()
            ->where('status', 'occupied')
            ->whereHas('room', fn ($query) => $query->where('hostel_block_id', $block->id))
            ->count();
    }

    public function occupiedBedCountForHostel(Hostel $hostel): int
    {
        return HostelBed::query()
            ->where('status', 'occupied')
            ->whereHas('room.block', fn ($query) => $query->where('hostel_id', $hostel->id))
            ->count();
    }

    public function deleteRoom(HostelRoom $room): void
    {
        $this->assertDeletable($this->occupiedBedCountForRoom($room), 'room');

        DB::transaction(function () use ($room) {
            foreach ($room->beds()->get() as $bed) {
                $bed->delete();
            }
            $room->delete();
        });
    }

    public function deleteBlock(HostelBlock $block): void
    {
        $this->assertDeletable($this->occupiedBedCountForBlock($block), 'block');

        DB::transaction(function () use ($block) {
            $block->load('rooms.beds');
            foreach ($block->rooms as $room) {
                foreach ($room->beds as $bed) {
                    $bed->delete();
                }
                $room->delete();
            }
            $block->delete();
        });
    }

    public function deleteHostel(Hostel $hostel): void
    {
        $this->assertDeletable($this->occupiedBedCountForHostel($hostel), 'hostel');

        DB::transaction(function () use ($hostel) {
            $hostel->load('blocks.rooms.beds');
            foreach ($hostel->blocks as $block) {
                foreach ($block->rooms as $room) {
                    foreach ($room->beds as $bed) {
                        $bed->delete();
                    }
                    $room->delete();
                }
                $block->delete();
            }
            $hostel->delete();
        });
    }

    private function assertDeletable(int $occupiedBeds, string $entity): void
    {
        if ($occupiedBeds > 0) {
            throw ValidationException::withMessages([
                $entity => "Cannot delete this {$entity} while {$occupiedBeds} bed(s) are occupied. Vacate allocations first.",
            ]);
        }
    }

    public function storeRoom(HostelBlock $block, array $data): HostelRoom
    {
        $room = $block->rooms()->create([
            'number' => $data['number'],
            'capacity' => $data['capacity'] ?? 4,
            'gender' => $data['gender'] ?? null,
            'is_active' => $data['is_active'] ?? true,
            'is_reserved' => false,
        ]);

        $this->syncBedsToCapacity($room);

        return $room->fresh(['beds', 'block.hostel']);
    }

    public function updateRoom(HostelRoom $room, array $data): HostelRoom
    {
        $room->update(collect($data)->only([
            'number',
            'capacity',
            'gender',
            'is_active',
            'is_reserved',
            'reserve_note',
        ])->all());

        if (array_key_exists('capacity', $data)) {
            $this->syncBedsToCapacity($room);
        }

        $this->applyRoomAvailability($room->fresh('beds'));

        return $room->fresh(['beds', 'block.hostel']);
    }

    public function reserveRoom(HostelRoom $room, ?string $note = null): HostelRoom
    {
        $room->update([
            'is_reserved' => true,
            'reserve_note' => $note,
        ]);
        $this->applyRoomAvailability($room->fresh('beds'));

        return $room->fresh(['beds', 'block.hostel']);
    }

    public function releaseRoom(HostelRoom $room): HostelRoom
    {
        $room->update([
            'is_reserved' => false,
            'reserve_note' => null,
        ]);
        $this->applyRoomAvailability($room->fresh('beds'));

        return $room->fresh(['beds', 'block.hostel']);
    }

    public function setRoomActive(HostelRoom $room, bool $active): HostelRoom
    {
        $room->update(['is_active' => $active]);
        $this->applyRoomAvailability($room->fresh('beds'));

        return $room->fresh(['beds', 'block.hostel']);
    }
}
