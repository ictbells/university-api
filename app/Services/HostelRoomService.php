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
    public function formatRoom(HostelRoom $room): array
    {
        $room->loadMissing(['block.hostel', 'beds']);

        $beds = $room->beds;
        $occupied = $beds->where('status', 'occupied')->count();
        $available = $beds->where('status', 'available')->count();
        $effectiveGender = $this->roomEffectiveGender($room);

        return [
            ...$room->toArray(),
            'hostel_id' => $room->block?->hostel_id,
            'hostel_name' => $room->block?->hostel?->name,
            'hostel_gender' => $room->block?->hostel?->gender,
            'hostel_category' => $room->block?->hostel?->category,
            'block_name' => $room->block?->name,
            'bed_count' => $beds->count(),
            'occupied_beds' => $occupied,
            'available_beds' => $available,
            'effective_gender' => $effectiveGender,
            'gender_label' => $effectiveGender ? ucfirst($effectiveGender) : 'Unassigned',
            'beds' => $beds->map(fn (HostelBed $bed) => [
                'id' => $bed->id,
                'label' => $bed->label,
                'status' => $bed->status,
            ])->values(),
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
                ->whereIn('status', ['available', 'reserved', 'disabled'])
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
        foreach ($room->beds as $bed) {
            if ($bed->status === 'occupied') {
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

        $occupantGender = HostelAllocation::query()
            ->where('status', 'allocated')
            ->whereHas('bed', fn ($query) => $query->where('hostel_room_id', $room->id))
            ->with('student')
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
            ->where('status', 'allocated')
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
