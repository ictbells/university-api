<?php

namespace App\Services;

use App\Models\Hostel;
use App\Models\HostelAllocation;
use App\Models\HostelBed;
use App\Models\HostelBlock;
use App\Models\HostelRoom;
use App\Models\Student;
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
        $beddingType = in_array($room->bedding_type, HostelRoom::BEDDING_TYPES, true)
            ? $room->bedding_type
            : HostelRoom::BEDDING_SINGLE;

        $payload = [
            'id' => $room->id,
            'hostel_block_id' => $room->hostel_block_id,
            'number' => $room->number,
            'room_type' => $room->normalizedRoomType(),
            'room_type_label' => HostelRoom::roomTypeLabel($room->normalizedRoomType()),
            'is_residential' => $room->isResidential(),
            'capacity' => $room->capacity,
            'bedding_type' => $beddingType,
            'uses_bunks' => $beddingType === HostelRoom::BEDDING_BUNK,
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
            'available_bunk_summary' => null,
        ];

        if ($includeBeds || $room->relationLoaded('beds')) {
            $room->loadMissing('beds');
            $payload['available_bunk_summary'] = $this->availableBunkSummary($room);
        }

        if ($includeBeds) {
            $room->loadMissing('beds');
            $payload['beds'] = $room->beds
                ->sortBy(fn (HostelBed $bed) => [
                    (int) ($bed->bunk_pair ?? 0),
                    $bed->bunk_position === HostelBed::POSITION_UPPER ? 1 : 0,
                    (int) $bed->label,
                    $bed->id,
                ])
                ->values()
                ->map(fn (HostelBed $bed) => [
                    'id' => $bed->id,
                    'label' => $bed->label,
                    'display_label' => $bed->displayLabel(),
                    'bunk_position' => $bed->bunk_position,
                    'bunk_pair' => $bed->bunk_pair,
                    'status' => $bed->status,
                ])
                ->values();
        }

        return $payload;
    }

    /**
     * @return array{lower: int, upper: int, text: string}|null
     */
    public function availableBunkSummary(HostelRoom $room): ?array
    {
        if (! $room->usesBunks()) {
            return null;
        }

        $room->loadMissing('beds');
        $available = $room->beds->where('status', 'available');
        $lower = $available->where('bunk_position', HostelBed::POSITION_LOWER)->count();
        $upper = $available->where('bunk_position', HostelBed::POSITION_UPPER)->count();
        $parts = [];
        if ($lower > 0) {
            $parts[] = $lower === 1 ? '1 lower' : "{$lower} lower";
        }
        if ($upper > 0) {
            $parts[] = $upper === 1 ? '1 upper' : "{$upper} upper";
        }

        return [
            'lower' => $lower,
            'upper' => $upper,
            'text' => $parts !== [] ? implode(' · ', $parts).' free' : 'No bunks free',
        ];
    }

    /**
     * @return array{label: string, bunk_position: ?string, bunk_pair: ?int}
     */
    public function bedSlotForIndex(HostelRoom $room, int $zeroBasedIndex): array
    {
        if (! $room->usesBunks()) {
            $number = $zeroBasedIndex + 1;

            return [
                'label' => (string) $number,
                'bunk_position' => null,
                'bunk_pair' => null,
            ];
        }

        $pair = intdiv($zeroBasedIndex, 2) + 1;
        $isLower = $zeroBasedIndex % 2 === 0;
        $position = $isLower ? HostelBed::POSITION_LOWER : HostelBed::POSITION_UPPER;

        return [
            'label' => ($isLower ? 'Lower' : 'Upper').' '.$pair,
            'bunk_position' => $position,
            'bunk_pair' => $pair,
        ];
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

        $targetStatus = $this->bedStatusForRoom($room);
        $beds = $room->beds->sortBy('id')->values();

        if ($beds->count() < $capacity) {
            for ($i = $beds->count(); $i < $capacity; $i++) {
                $slot = $this->bedSlotForIndex($room, $i);
                $room->beds()->create([
                    ...$slot,
                    'status' => $targetStatus,
                ]);
            }
            $room->load('beds');
        }

        if ($room->beds()->count() > $capacity) {
            $removable = $room->beds()
                ->whereIn('status', ['available', 'disabled'])
                ->orderByDesc('id')
                ->get();

            $toRemove = $room->beds()->count() - $capacity;
            foreach ($removable->take($toRemove) as $bed) {
                $bed->delete();
            }

            if ($room->beds()->count() > $capacity) {
                throw ValidationException::withMessages([
                    'capacity' => 'Reduce occupied or reserved beds before lowering capacity.',
                ]);
            }
            $room->load('beds');
        }

        $this->relabelBeds($room->fresh('beds'));

        return $room->fresh('beds');
    }

    public function relabelBeds(HostelRoom $room): void
    {
        $room->loadMissing('beds');
        $beds = $room->beds->sortBy('id')->values();
        foreach ($beds as $index => $bed) {
            $slot = $this->bedSlotForIndex($room, $index);
            $bed->fill($slot);
            if ($bed->isDirty()) {
                $bed->save();
            }
        }
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
            ->whereIn('hostel_bed_id', $room->beds()->pluck('id'))
            ->pluck('hostel_bed_id');

        foreach ($room->beds as $bed) {
            if ($bed->status === 'occupied' || $pendingBedIds->contains($bed->id)) {
                continue;
            }
            if ($bed->status !== $targetStatus) {
                $bed->update(['status' => $targetStatus]);
            }
        }
    }

    public function assertRoomGenderMatch(Student $student, HostelRoom $room, Hostel $hostel): void
    {
        $effective = $room->gender
            ? strtolower((string) $room->gender)
            : $this->inferredRoomGender($room);

        if (! $effective) {
            return;
        }

        $studentGender = strtolower((string) $student->gender);
        if (! $studentGender || strtolower((string) $hostel->gender) !== 'mixed') {
            return;
        }
        if ($studentGender !== $effective) {
            throw ValidationException::withMessages([
                'hostel_bed_id' => 'This room is reserved for '.$effective.' students.',
            ]);
        }
    }

    public function inferredRoomGender(HostelRoom $room): ?string
    {
        if ($room->gender) {
            return strtolower((string) $room->gender);
        }

        $room->loadMissing(['beds.allocations' => fn ($q) => $q->whereIn('status', ['allocated', 'pending'])->with('student')]);
        $genders = $room->beds
            ->flatMap(fn (HostelBed $bed) => $bed->allocations)
            ->map(fn ($allocation) => $allocation->student?->gender)
            ->filter()
            ->map(fn ($gender) => strtolower((string) $gender))
            ->unique()
            ->values();

        return $genders->count() === 1 ? $genders->first() : null;
    }

    public function lockRoomGenderFromStudent(HostelRoom $room, Hostel $hostel, Student $student): void
    {
        if (strtolower((string) $hostel->gender) !== 'mixed' || $room->gender) {
            return;
        }

        $gender = strtolower((string) $student->gender);
        if (in_array($gender, ['male', 'female'], true)) {
            $room->update(['gender' => $gender]);
        }
    }

    public function resetRoomGenderIfEmpty(HostelRoom $room, Hostel $hostel): void
    {
        if (strtolower((string) $hostel->gender) !== 'mixed') {
            return;
        }
        if ($room->gender && $this->occupiedBedCountForRoom($room) === 0) {
            $hasPending = HostelAllocation::query()
                ->where('status', 'pending')
                ->whereHas('bed', fn ($q) => $q->where('hostel_room_id', $room->id))
                ->exists();
            if (! $hasPending) {
                $room->update(['gender' => null]);
            }
        }
    }

    public function occupiedBedCountForRoom(HostelRoom $room): int
    {
        return $room->beds()->where('status', 'occupied')->count();
    }

    public function occupiedBedCountForBlock(HostelBlock $block): int
    {
        return HostelBed::query()
            ->whereHas('room', fn ($q) => $q->where('hostel_block_id', $block->id))
            ->where('status', 'occupied')
            ->count();
    }

    public function occupiedBedCountForHostel(Hostel $hostel): int
    {
        return HostelBed::query()
            ->whereHas('room.block', fn ($q) => $q->where('hostel_id', $hostel->id))
            ->where('status', 'occupied')
            ->count();
    }

    public function storeBlock(Hostel $hostel, array $data): HostelBlock
    {
        return $hostel->blocks()->create([
            'name' => $data['name'],
        ]);
    }

    public function updateBlock(HostelBlock $block, array $data): HostelBlock
    {
        $block->update([
            'name' => $data['name'],
        ]);

        return $block->fresh();
    }

    public function deleteRoom(HostelRoom $room): void
    {
        $this->assertDeletable($this->occupiedBedCountForRoom($room), 'room');
        $room->beds()->delete();
        $room->delete();
    }

    public function deleteBlock(HostelBlock $block): void
    {
        $this->assertDeletable($this->occupiedBedCountForBlock($block), 'block');
        foreach ($block->rooms as $room) {
            $room->beds()->delete();
            $room->delete();
        }
        $block->delete();
    }

    public function deleteHostel(Hostel $hostel): void
    {
        $this->assertDeletable($this->occupiedBedCountForHostel($hostel), 'hostel');
        foreach ($hostel->blocks as $block) {
            foreach ($block->rooms as $room) {
                $room->beds()->delete();
                $room->delete();
            }
            $block->delete();
        }
        $hostel->delete();
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
        $beddingType = $data['bedding_type'] ?? HostelRoom::BEDDING_SINGLE;
        if (! in_array($beddingType, HostelRoom::BEDDING_TYPES, true)) {
            $beddingType = HostelRoom::BEDDING_SINGLE;
        }

        $roomType = strtolower((string) ($data['room_type'] ?? HostelRoom::TYPE_STANDARD));
        if (! in_array($roomType, HostelRoom::ROOM_TYPES, true)) {
            $roomType = HostelRoom::TYPE_STANDARD;
        }

        $room = $block->rooms()->create([
            'number' => $data['number'],
            'room_type' => $roomType,
            'capacity' => $data['capacity'] ?? 4,
            'bedding_type' => $beddingType,
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
            'room_type',
            'capacity',
            'bedding_type',
            'gender',
            'is_active',
            'is_reserved',
            'reserve_note',
        ])->all());

        if (array_key_exists('capacity', $data) || array_key_exists('bedding_type', $data)) {
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
