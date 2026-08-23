<?php

namespace App\Http\Controllers;

use App\Models\FeeItem;
use App\Models\Hostel;
use App\Models\HostelAllocation;
use App\Models\HostelBed;
use App\Models\HostelBlock;
use App\Models\HostelRoom;
use App\Models\Student;
use App\Services\AuditWriter;
use App\Services\HostelRoomService;
use App\Services\HostelService;
use App\Services\InvoiceService;
use App\Services\Notifier;
use Illuminate\Http\Request;

class HostelController extends Controller
{
    public function __construct(
        private AuditWriter $audit,
        private InvoiceService $invoices,
        private Notifier $notifier,
        private HostelService $hostels,
        private HostelRoomService $rooms,
    ) {}

    public function overview()
    {
        return [
            'categories' => $this->hostels->categories(),
            'stats' => $this->hostels->hostelStats(),
            'current_term_id' => $this->hostels->currentTermId(),
        ];
    }

    public function index()
    {
        return Hostel::query()
            ->with('blocks.rooms.beds')
            ->orderBy('category')
            ->orderBy('name')
            ->get()
            ->map(fn (Hostel $hostel) => $this->formatHostel($hostel));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'campus_id' => 'nullable|exists:campuses,id',
            'name' => 'required|string|max:255',
            'gender' => 'nullable|in:male,female,mixed',
            'category' => 'required|in:undergraduate,jupeb',
            'is_active' => 'boolean',
        ]);

        $hostel = Hostel::query()->create($data);
        $this->audit->record('hostel.created', 'Hostel created', 'hostel', 'hostel', $hostel->id, null, $hostel);

        return $this->formatHostel($hostel->load('blocks.rooms.beds'));
    }

    public function update(Request $request, Hostel $hostel)
    {
        $data = $request->validate([
            'campus_id' => 'nullable|exists:campuses,id',
            'name' => 'sometimes|string|max:255',
            'gender' => 'nullable|in:male,female,mixed',
            'category' => 'sometimes|in:undergraduate,jupeb',
            'is_active' => 'boolean',
        ]);

        $before = $hostel->toArray();
        $hostel->update($data);
        $this->audit->record('hostel.updated', 'Hostel updated', 'hostel', 'hostel', $hostel->id, $before, $hostel->fresh());

        return $this->formatHostel($hostel->fresh('blocks.rooms.beds'));
    }

    public function levelWindows(Request $request)
    {
        $data = $request->validate([
            'category' => 'required|in:undergraduate,jupeb',
            'academic_term_id' => 'nullable|exists:academic_terms,id',
        ]);

        return $this->hostels->levelWindows($data['category'], $data['academic_term_id'] ?? null);
    }

    public function syncLevelWindows(Request $request)
    {
        $data = $request->validate([
            'category' => 'required|in:undergraduate,jupeb',
            'academic_term_id' => 'nullable|exists:academic_terms,id',
            'levels' => 'required|array|min:1',
            'levels.*.academic_level_id' => 'required|exists:academic_levels,id',
            'levels.*.is_active' => 'boolean',
            'levels.*.opens_at' => 'nullable|date',
            'levels.*.closes_at' => 'nullable|date',
        ]);

        $windows = $this->hostels->syncLevelWindows(
            $data['category'],
            $data['levels'],
            $data['academic_term_id'] ?? null,
        );

        $this->audit->record(
            'hostel.level_windows',
            'Hostel level windows updated',
            'hostel',
            'hostel_level_window',
            null,
            null,
            ['category' => $data['category'], 'levels' => $data['levels']],
        );

        return $windows;
    }

    public function queue(Request $request)
    {
        $data = $request->validate([
            'category' => 'required|in:undergraduate,jupeb',
            'academic_term_id' => 'nullable|exists:academic_terms,id',
        ]);

        return $this->hostels
            ->eligibleStudentsQuery($data['category'], $data['academic_term_id'] ?? null)
            ->limit(100)
            ->get()
            ->map(fn (Student $student) => [
                'id' => $student->id,
                'name' => trim("{$student->first_name} {$student->last_name}"),
                'matric_number' => $student->matric_number,
                'current_level' => $student->current_level,
                'priority' => $this->hostels->priorityLabel($student),
                'gender' => $student->gender,
                'program' => $student->program?->name,
                'category' => $this->hostels->studentCategory($student),
            ]);
    }

    public function allocate(Request $request)
    {
        $data = $request->validate([
            'student_id' => 'required|exists:students,id',
            'hostel_bed_id' => 'required|exists:hostel_beds,id',
            'academic_term_id' => 'nullable|exists:academic_terms,id',
        ]);

        $bed = HostelBed::query()->findOrFail($data['hostel_bed_id']);
        $student = Student::query()->with('application')->findOrFail($data['student_id']);
        $termId = $data['academic_term_id'] ?? $this->hostels->currentTermId();

        $this->hostels->validateAllocation($student, $bed, $termId);

        $allocation = HostelAllocation::query()->create([
            'student_id' => $student->id,
            'hostel_bed_id' => $bed->id,
            'academic_term_id' => $termId,
            'status' => 'allocated',
            'allocated_at' => now(),
        ]);
        $bed->update(['status' => 'occupied']);
        $this->rooms->lockRoomGenderFromStudent($bed->room, $bed->room->block->hostel, $student);

        $fee = FeeItem::query()->where('category', 'hostel')->where('is_active', true)->first();
        if ($fee) {
            $this->invoices->createForFee($student->user, $fee, $student->application_id, $student->id);
        }

        $this->audit->record('hostel.allocate', 'Bed allocated', 'hostel', 'hostel_allocation', $allocation->id, null, $allocation);
        $this->notifier->send($student->user, 'hostel', 'Hostel allocated', 'A bed has been assigned to you.', 'hostel', $allocation->id);

        return $allocation->load(['bed.room.block.hostel', 'student.application', 'academicTerm']);
    }

    public function autoAllocate(Request $request)
    {
        $data = $request->validate([
            'category' => 'required|in:undergraduate,jupeb',
            'hostel_bed_id' => 'required|exists:hostel_beds,id',
            'academic_term_id' => 'nullable|exists:academic_terms,id',
        ]);

        $bed = HostelBed::query()->findOrFail($data['hostel_bed_id']);
        $termId = $data['academic_term_id'] ?? $this->hostels->currentTermId();

        $student = $this->hostels
            ->eligibleStudentsQuery($data['category'], $termId)
            ->first();

        if (! $student) {
            return response()->json(['message' => 'No eligible student in the priority queue for this category.'], 422);
        }

        return $this->allocate(new Request([
            'student_id' => $student->id,
            'hostel_bed_id' => $bed->id,
            'academic_term_id' => $termId,
        ]));
    }

    public function vacate(HostelAllocation $allocation)
    {
        $allocation->load('bed.room.block.hostel');
        $allocation->update(['status' => 'vacated', 'vacated_at' => now()]);
        $allocation->bed->update(['status' => 'available']);
        if ($allocation->bed?->room && $allocation->bed->room->block?->hostel) {
            $this->rooms->resetRoomGenderIfEmpty($allocation->bed->room, $allocation->bed->room->block->hostel);
            $this->rooms->applyRoomAvailability($allocation->bed->room->fresh('beds'));
        }
        $this->audit->record('hostel.vacate', 'Bed vacated', 'hostel', 'hostel_allocation', $allocation->id, null, $allocation);

        return $allocation->load(['bed.room.block.hostel', 'student']);
    }

    public function rooms(Request $request)
    {
        $filters = $request->validate([
            'hostel_id' => 'nullable|exists:hostels,id',
            'category' => 'nullable|in:undergraduate,jupeb',
        ]);

        return HostelRoom::query()
            ->with(['block.hostel', 'beds'])
            ->when($filters['hostel_id'] ?? null, fn ($query, int $hostelId) => $query->whereHas(
                'block',
                fn ($block) => $block->where('hostel_id', $hostelId),
            ))
            ->when($filters['category'] ?? null, fn ($query, string $category) => $query->whereHas(
                'block.hostel',
                fn ($hostel) => $hostel->where('category', $category),
            ))
            ->orderBy('hostel_block_id')
            ->orderBy('number')
            ->get()
            ->map(fn (HostelRoom $room) => $this->rooms->formatRoom($room));
    }

    public function storeBlock(Request $request, Hostel $hostel)
    {
        $data = $request->validate(['name' => 'required|string|max:120']);
        $block = $this->rooms->storeBlock($hostel, $data);
        $this->audit->record('hostel.block.created', 'Hostel block created', 'hostel', 'hostel_block', $block->id, null, $block);

        return $block;
    }

    public function storeRoom(Request $request, HostelBlock $hostelBlock)
    {
        $data = $request->validate([
            'number' => 'required|string|max:50',
            'capacity' => 'required|integer|min:1|max:20',
            'gender' => 'nullable|in:male,female',
            'is_active' => 'boolean',
        ]);

        $room = $this->rooms->storeRoom($hostelBlock, $data);
        $this->audit->record('hostel.room.created', 'Hostel room created', 'hostel', 'hostel_room', $room->id, null, $room);

        return $this->rooms->formatRoom($room);
    }

    public function updateRoom(Request $request, HostelRoom $hostelRoom)
    {
        $data = $request->validate([
            'number' => 'sometimes|string|max:50',
            'capacity' => 'sometimes|integer|min:1|max:20',
            'gender' => 'nullable|in:male,female',
            'is_active' => 'boolean',
            'is_reserved' => 'boolean',
            'reserve_note' => 'nullable|string|max:255',
        ]);

        $before = $hostelRoom->toArray();
        $room = $this->rooms->updateRoom($hostelRoom, $data);
        $this->audit->record('hostel.room.updated', 'Hostel room updated', 'hostel', 'hostel_room', $room->id, $before, $room->toArray());

        return $this->rooms->formatRoom($room);
    }

    public function reserveRoom(Request $request, HostelRoom $hostelRoom)
    {
        $data = $request->validate(['reserve_note' => 'nullable|string|max:255']);
        $room = $this->rooms->reserveRoom($hostelRoom, $data['reserve_note'] ?? null);
        $this->audit->record('hostel.room.reserved', 'Hostel room reserved', 'hostel', 'hostel_room', $room->id, null, $room->toArray());

        return $this->rooms->formatRoom($room);
    }

    public function releaseRoom(HostelRoom $hostelRoom)
    {
        $room = $this->rooms->releaseRoom($hostelRoom);
        $this->audit->record('hostel.room.released', 'Hostel room reservation released', 'hostel', 'hostel_room', $room->id, null, $room->toArray());

        return $this->rooms->formatRoom($room);
    }

    public function disableRoom(HostelRoom $hostelRoom)
    {
        $room = $this->rooms->setRoomActive($hostelRoom, false);
        $this->audit->record('hostel.room.disabled', 'Hostel room disabled', 'hostel', 'hostel_room', $room->id, null, $room->toArray());

        return $this->rooms->formatRoom($room);
    }

    public function enableRoom(HostelRoom $hostelRoom)
    {
        $room = $this->rooms->setRoomActive($hostelRoom, true);
        $this->audit->record('hostel.room.enabled', 'Hostel room enabled', 'hostel', 'hostel_room', $room->id, null, $room->toArray());

        return $this->rooms->formatRoom($room);
    }

    public function allocations()
    {
        return HostelAllocation::query()
            ->with(['student.application', 'bed.room.block.hostel', 'academicTerm'])
            ->latest()
            ->get()
            ->map(function (HostelAllocation $allocation) {
                $student = $allocation->student;

                return [
                    ...$allocation->toArray(),
                    'student_name' => $student ? trim("{$student->first_name} {$student->last_name}") : null,
                    'student_level' => $student?->current_level,
                    'hostel_name' => $allocation->bed?->room?->block?->hostel?->name,
                    'hostel_category' => $allocation->bed?->room?->block?->hostel?->category,
                    'bed_label' => $allocation->bed?->label,
                    'room_number' => $allocation->bed?->room?->number,
                ];
            });
    }

    private function formatHostel(Hostel $hostel): array
    {
        $totalBeds = 0;
        $availableBeds = 0;

        foreach ($hostel->blocks as $block) {
            foreach ($block->rooms as $room) {
                foreach ($room->beds as $bed) {
                    $totalBeds++;
                    if ($bed->status === 'available') {
                        $availableBeds++;
                    }
                }
            }
        }

        return [
            ...$hostel->toArray(),
            'total_beds' => $totalBeds,
            'available_beds' => $availableBeds,
            'occupied_beds' => $totalBeds - $availableBeds,
        ];
    }
}
