<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesOfficeApprovals;
use App\Models\Hostel;
use App\Models\HostelAllocation;
use App\Models\HostelBed;
use App\Models\HostelBlock;
use App\Models\HostelRoom;
use App\Models\Student;
use App\Services\AuditWriter;
use App\Services\HostelAllocationExportService;
use App\Services\HostelRoomService;
use App\Services\HostelService;
use App\Services\InvoiceService;
use App\Services\Notifier;
use App\Services\OfficeApprovalService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class HostelController extends Controller
{
    use AuthorizesOfficeApprovals;
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
        $counts = $this->hostels->hostelBedCounts();

        return Hostel::query()
            ->with(['blocks' => fn ($query) => $query->withCount('rooms')->orderBy('name')])
            ->orderBy('category')
            ->orderBy('name')
            ->get()
            ->map(fn (Hostel $hostel) => $this->formatHostel($hostel, $counts->get($hostel->id)));
    }

    public function store(Request $request)
    {
        $data = $this->validatedHostel($request, true);

        return $this->officeGate('hostel.store', null, $data, 'Create hostel '.$data['name'], function () use ($data) {
            $hostel = Hostel::query()->create($data);
            $this->audit->record('hostel.created', 'Hostel created', 'hostel', 'hostel', $hostel->id, null, $hostel);

            return $this->formatHostel($hostel->load(['blocks' => fn ($query) => $query->withCount('rooms')->orderBy('name')]));
        });
    }

    public function update(Request $request, Hostel $hostel)
    {
        $data = $this->validatedHostel($request, false);

        return $this->officeGate('hostel.update', $hostel, ['hostel_id' => $hostel->id, ...$data], 'Update hostel '.$hostel->name, function () use ($data, $hostel) {
            $before = $hostel->toArray();
            $hostel->update($data);
            $this->audit->record('hostel.updated', 'Hostel updated', 'hostel', 'hostel', $hostel->id, $before, $hostel->fresh());
            $hostel = $hostel->fresh(['blocks' => fn ($query) => $query->withCount('rooms')->orderBy('name')]);

            return $this->formatHostel($hostel, $this->hostels->hostelBedCounts()->get($hostel->id));
        });
    }

    public function destroy(Hostel $hostel)
    {
        return $this->officeGate('hostel.destroy', $hostel, ['hostel_id' => $hostel->id], 'Delete hostel '.$hostel->name, function () use ($hostel) {
            $before = $hostel->toArray();
            $this->rooms->deleteHostel($hostel);
            $this->audit->record('hostel.deleted', 'Hostel deleted', 'hostel', 'hostel', $hostel->id, $before, null);

            return response()->noContent();
        });
    }

    public function levelWindows(Request $request)
    {
        $data = $request->validate([
            'category' => 'required|in:undergraduate,jupeb,postgraduate',
            'academic_term_id' => 'nullable|exists:academic_terms,id',
        ]);

        return $this->hostels->levelWindows($data['category'], $data['academic_term_id'] ?? null);
    }

    public function syncLevelWindows(Request $request)
    {
        $data = $request->validate([
            'category' => 'required|in:undergraduate,jupeb,postgraduate',
            'academic_term_id' => 'nullable|exists:academic_terms,id',
            'levels' => 'required|array|min:1',
            'levels.*.academic_level_id' => 'required|exists:academic_levels,id',
            'levels.*.is_active' => 'boolean',
            'levels.*.opens_at' => 'nullable|date',
            'levels.*.closes_at' => 'nullable|date',
        ]);

        return $this->officeGate('hostel.sync_level_windows', null, $data, 'Update hostel level windows', function () use ($data) {
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
        });
    }

    public function queue(Request $request)
    {
        $data = $request->validate([
            'category' => 'required|in:undergraduate,jupeb,postgraduate',
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
        $student = Student::query()->with(['application', 'user'])->findOrFail($data['student_id']);
        $termId = $data['academic_term_id'] ?? $this->hostels->currentTermId();

        return $this->officeGate(
            'hostel.allocate',
            $student,
            $data,
            'Allocate hostel bed',
            function () use ($student, $bed, $termId) {
                $allocation = $this->hostels->allocateBed($student, $bed, $termId);
                $this->audit->record('hostel.allocate', 'Bed allocated', 'hostel', 'hostel_allocation', $allocation->id, null, $allocation);
                $this->notifier->send($student->user, 'hostel', 'Hostel allocated', 'A bed has been assigned to you.', 'hostel', $allocation->id);

                return $allocation;
            },
        );
    }

    public function autoAllocate(Request $request)
    {
        $data = $request->validate([
            'category' => 'required|in:undergraduate,jupeb,postgraduate',
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
        abort_unless($allocation->status === 'allocated', 422, 'Only allocated beds can be vacated.');

        return $this->officeGate('hostel.vacate', $allocation, ['allocation_id' => $allocation->id], 'Vacate hostel bed', function () use ($allocation) {
            $allocation->load('bed.room.block.hostel');
            $allocation->update(['status' => 'vacated', 'vacated_at' => now()]);
            $allocation->bed->update(['status' => 'available']);
            if ($allocation->bed?->room && $allocation->bed->room->block?->hostel) {
                $this->rooms->resetRoomGenderIfEmpty($allocation->bed->room, $allocation->bed->room->block->hostel);
                $this->rooms->applyRoomAvailability($allocation->bed->room->fresh('beds'));
            }
            $this->audit->record('hostel.vacate', 'Bed vacated', 'hostel', 'hostel_allocation', $allocation->id, null, $allocation);

            return $allocation->load(['bed.room.block.hostel', 'student']);
        });
    }

    public function approve(HostelAllocation $allocation)
    {
        return $this->officeGate('hostel.approve', $allocation, ['allocation_id' => $allocation->id], 'Approve hostel bed request', function () use ($allocation) {
            $allocation = $this->hostels->approveAllocation($allocation);
            $student = $allocation->student;
            $hostel = $allocation->bed?->room?->block?->hostel;

            $this->audit->record('hostel.approve', 'Hostel bed request approved', 'hostel', 'hostel_allocation', $allocation->id, null, $allocation);
            if ($student?->user) {
                $this->notifier->send(
                    $student->user,
                    'hostel',
                    'Hostel bed approved',
                    $hostel?->chargesDue()
                        ? 'Your hostel bed request has been approved. A hostel due invoice has been raised. Pay it from your wallet.'
                        : 'Your hostel bed request has been approved.',
                    'hostel',
                    $allocation->id
                );
            }

            return $this->hostels->formatStaffAllocation($allocation);
        });
    }

    public function reject(HostelAllocation $allocation)
    {
        return $this->officeGate('hostel.reject', $allocation, ['allocation_id' => $allocation->id], 'Reject hostel bed request', function () use ($allocation) {
            $allocation = $this->hostels->rejectAllocation($allocation);
            $student = $allocation->student;

            $this->audit->record('hostel.reject', 'Hostel bed request rejected', 'hostel', 'hostel_allocation', $allocation->id, null, $allocation);
            if ($student?->user) {
                $this->notifier->send(
                    $student->user,
                    'hostel',
                    'Hostel bed request rejected',
                    'Your hostel bed request was not approved. You may select another bed while the window is open.',
                    'hostel',
                    $allocation->id
                );
            }

            return $this->hostels->formatStaffAllocation($allocation);
        });
    }

    public function rooms(Request $request)
    {
        $filters = $request->validate([
            'hostel_id' => 'nullable|exists:hostels,id',
            'category' => 'nullable|in:undergraduate,jupeb,postgraduate',
        ]);

        return HostelRoom::query()
            ->with(['block.hostel'])
            ->withCount([
                'beds as bed_count',
                'beds as occupied_beds' => fn ($query) => $query->where('status', 'occupied'),
                'beds as available_beds' => fn ($query) => $query->where('status', 'available'),
            ])
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
            ->map(fn (HostelRoom $room) => $this->rooms->formatRoom($room, false));
    }

    public function storeBlock(Request $request, Hostel $hostel)
    {
        $data = $request->validate(['name' => 'required|string|max:120']);

        return $this->officeGate('hostel.store_block', $hostel, ['hostel_id' => $hostel->id, ...$data], 'Create hostel block', function () use ($hostel, $data) {
            $block = $this->rooms->storeBlock($hostel, $data);
            $this->audit->record('hostel.block.created', 'Hostel block created', 'hostel', 'hostel_block', $block->id, null, $block);

            return $block;
        });
    }

    public function updateBlock(Request $request, HostelBlock $hostelBlock)
    {
        $data = $request->validate(['name' => 'required|string|max:120']);

        return $this->officeGate('hostel.update_block', $hostelBlock, ['hostel_block_id' => $hostelBlock->id, ...$data], 'Update hostel block', function () use ($hostelBlock, $data) {
            $before = $hostelBlock->toArray();
            $block = $this->rooms->updateBlock($hostelBlock, $data);
            $this->audit->record('hostel.block.updated', 'Hostel block updated', 'hostel', 'hostel_block', $block->id, $before, $block);

            return $block;
        });
    }

    public function destroyBlock(HostelBlock $hostelBlock)
    {
        return $this->officeGate('hostel.destroy_block', $hostelBlock, ['hostel_block_id' => $hostelBlock->id], 'Delete hostel block', function () use ($hostelBlock) {
            $before = $hostelBlock->toArray();
            $this->rooms->deleteBlock($hostelBlock);
            $this->audit->record('hostel.block.deleted', 'Hostel block deleted', 'hostel', 'hostel_block', $hostelBlock->id, $before, null);

            return response()->noContent();
        });
    }

    public function storeRoom(Request $request, HostelBlock $hostelBlock)
    {
        $data = $request->validate([
            'number' => 'required|string|max:50',
            'capacity' => 'required|integer|min:1|max:20',
            'gender' => 'nullable|in:male,female',
            'is_active' => 'boolean',
        ]);

        return $this->officeGate('hostel.store_room', $hostelBlock, ['hostel_block_id' => $hostelBlock->id, ...$data], 'Create hostel room', function () use ($hostelBlock, $data) {
            $room = $this->rooms->storeRoom($hostelBlock, $data);
            $this->audit->record('hostel.room.created', 'Hostel room created', 'hostel', 'hostel_room', $room->id, null, $room);

            return $this->rooms->formatRoom($room);
        });
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

        return $this->officeGate('hostel.update_room', $hostelRoom, ['hostel_room_id' => $hostelRoom->id, ...$data], 'Update hostel room', function () use ($hostelRoom, $data) {
            $before = $hostelRoom->toArray();
            $room = $this->rooms->updateRoom($hostelRoom, $data);
            $this->audit->record('hostel.room.updated', 'Hostel room updated', 'hostel', 'hostel_room', $room->id, $before, $room->toArray());

            return $this->rooms->formatRoom($room);
        });
    }

    public function destroyRoom(HostelRoom $hostelRoom)
    {
        return $this->officeGate('hostel.destroy_room', $hostelRoom, ['hostel_room_id' => $hostelRoom->id], 'Delete hostel room', function () use ($hostelRoom) {
            $before = $hostelRoom->toArray();
            $this->rooms->deleteRoom($hostelRoom);
            $this->audit->record('hostel.room.deleted', 'Hostel room deleted', 'hostel', 'hostel_room', $hostelRoom->id, $before, null);

            return response()->noContent();
        });
    }

    public function reserveRoom(Request $request, HostelRoom $hostelRoom)
    {
        $data = $request->validate(['reserve_note' => 'nullable|string|max:255']);

        return $this->officeGate('hostel.reserve_room', $hostelRoom, ['hostel_room_id' => $hostelRoom->id, ...$data], 'Reserve hostel room', function () use ($hostelRoom, $data) {
            $room = $this->rooms->reserveRoom($hostelRoom, $data['reserve_note'] ?? null);
            $this->audit->record('hostel.room.reserved', 'Hostel room reserved', 'hostel', 'hostel_room', $room->id, null, $room->toArray());

            return $this->rooms->formatRoom($room);
        });
    }

    public function releaseRoom(HostelRoom $hostelRoom)
    {
        return $this->officeGate('hostel.release_room', $hostelRoom, ['hostel_room_id' => $hostelRoom->id], 'Release hostel room reservation', function () use ($hostelRoom) {
            $room = $this->rooms->releaseRoom($hostelRoom);
            $this->audit->record('hostel.room.released', 'Hostel room reservation released', 'hostel', 'hostel_room', $room->id, null, $room->toArray());

            return $this->rooms->formatRoom($room);
        });
    }

    public function disableRoom(HostelRoom $hostelRoom)
    {
        return $this->officeGate('hostel.disable_room', $hostelRoom, ['hostel_room_id' => $hostelRoom->id], 'Disable hostel room', function () use ($hostelRoom) {
            $room = $this->rooms->setRoomActive($hostelRoom, false);
            $this->audit->record('hostel.room.disabled', 'Hostel room disabled', 'hostel', 'hostel_room', $room->id, null, $room->toArray());

            return $this->rooms->formatRoom($room);
        });
    }

    public function enableRoom(HostelRoom $hostelRoom)
    {
        return $this->officeGate('hostel.enable_room', $hostelRoom, ['hostel_room_id' => $hostelRoom->id], 'Enable hostel room', function () use ($hostelRoom) {
            $room = $this->rooms->setRoomActive($hostelRoom, true);
            $this->audit->record('hostel.room.enabled', 'Hostel room enabled', 'hostel', 'hostel_room', $room->id, null, $room->toArray());

            return $this->rooms->formatRoom($room);
        });
    }

    public function availableBeds(Request $request)
    {
        $filters = $request->validate([
            'category' => 'nullable|in:undergraduate,jupeb,postgraduate',
            'hostel_id' => 'nullable|exists:hostels,id',
        ]);

        return HostelBed::query()
            ->where('status', 'available')
            ->whereHas('room', function ($query) use ($filters) {
                $query->where('is_active', true)
                    ->where('is_reserved', false)
                    ->whereHas('block.hostel', function ($hostel) use ($filters) {
                        $hostel->where('is_active', true)
                            ->when($filters['category'] ?? null, fn ($inner, string $category) => $inner->where('category', $category))
                            ->when($filters['hostel_id'] ?? null, fn ($inner, int $hostelId) => $inner->where('id', $hostelId));
                    });
            })
            ->with(['room:id,number,hostel_block_id', 'room.block:id,hostel_id,name', 'room.block.hostel:id,name,category'])
            ->orderBy('id')
            ->get()
            ->map(fn (HostelBed $bed) => [
                'id' => $bed->id,
                'label' => $bed->label,
                'hostel' => $bed->room?->block?->hostel?->name,
                'category' => $bed->room?->block?->hostel?->category,
                'room' => $bed->room?->number,
            ])
            ->values();
    }

    public function allocations(Request $request)
    {
        $filters = $this->allocationFilters($request);

        $rows = $this->hostels->staffAllocationQuery($filters)
            ->limit(200)
            ->get();
        $approvals = app(OfficeApprovalService::class);
        $open = $approvals->openKeyedBySubject($rows);

        return $rows
            ->map(function (HostelAllocation $allocation) use ($approvals, $open) {
                $row = $this->hostels->formatStaffAllocation($allocation);
                $pending = $open->get($allocation->getKey());
                $row['open_office_approval'] = $pending ? $approvals->serialize($pending) : null;

                return $row;
            })
            ->values();
    }

    public function exportAllocations(Request $request, HostelAllocationExportService $exports)
    {
        $filters = $this->allocationFilters($request, true);
        $allocations = $this->hostels->staffAllocationQuery($filters)
            ->limit(HostelAllocationExportService::MAX_ROWS)
            ->get();

        $rows = $allocations->map(function (HostelAllocation $allocation) {
            $row = $this->hostels->formatStaffAllocation($allocation);
            $allocatedAt = $row['allocated_at']
                ? Carbon::parse($row['allocated_at'])->format('d M Y')
                : '—';

            return [
                'student' => $row['student_name'] ?: '—',
                'matric' => $row['matric_number'] ?: '—',
                'programme' => $row['program'] ?: '—',
                'level' => $row['student_level'] ? $row['student_level'].'L' : '—',
                'hostel' => $row['hostel_name'] ?: '—',
                'category' => match ($row['hostel_category']) {
                    'jupeb' => 'JUPEB',
                    'postgraduate' => 'Postgraduate',
                    default => 'Undergraduate',
                },
                'block' => $row['block_name'] ?: '—',
                'room' => $row['room_number'] ?: '—',
                'bed' => $row['bed_label'] ?: '—',
                'status' => $row['status'] ? ucfirst((string) $row['status']) : '—',
                'allocated_at' => $allocatedAt,
            ];
        })->values();

        return $exports->export(
            $filters['format'],
            $rows,
            'Hostel Allocations',
            $this->hostels->allocationFilterSummary($filters),
        );
    }

    private function formatHostel(Hostel $hostel, mixed $counts = null): array
    {
        $hostel->loadMissing(['blocks' => fn ($query) => $query->withCount('rooms')->orderBy('name')]);
        $totalBeds = (int) ($counts?->total_beds ?? 0);
        $availableBeds = (int) ($counts?->available_beds ?? 0);

        return [
            'id' => $hostel->id,
            'campus_id' => $hostel->campus_id,
            'name' => $hostel->name,
            'gender' => $hostel->gender,
            'category' => $hostel->category,
            'is_active' => (bool) $hostel->is_active,
            'due_required' => (bool) $hostel->due_required,
            'due_amount' => $hostel->due_amount !== null ? (float) $hostel->due_amount : null,
            'total_beds' => $totalBeds,
            'available_beds' => $availableBeds,
            'occupied_beds' => $totalBeds - $availableBeds,
            'blocks' => $hostel->blocks->map(fn (HostelBlock $block) => [
                'id' => $block->id,
                'name' => $block->name,
                'rooms_count' => (int) ($block->rooms_count ?? 0),
            ])->values(),
        ];
    }

    public function me(Request $request)
    {
        $student = $request->user()->student;
        abort_unless($student, 404, 'No student record.');

        return $this->hostels->studentSnapshot($student);
    }

    public function select(Request $request)
    {
        $student = $request->user()->student;
        abort_unless($student, 404, 'No student record.');

        $data = $request->validate([
            'hostel_bed_id' => 'required|exists:hostel_beds,id',
        ]);

        $bed = HostelBed::query()->findOrFail($data['hostel_bed_id']);
        $allocation = $this->hostels->requestBed($student->load(['application', 'user']), $bed);

        $this->audit->record(
            'hostel.student_select',
            'Student requested a hostel bed',
            'hostel',
            'hostel_allocation',
            $allocation->id,
            null,
            $allocation
        );
        $this->notifier->send(
            $student->user,
            'hostel',
            'Hostel bed request submitted',
            'Your hostel bed request is waiting for staff approval. You will be notified when it is approved or rejected.',
            'hostel',
            $allocation->id
        );

        return $this->hostels->studentSnapshot($student->fresh());
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedHostel(Request $request, bool $creating): array
    {
        $data = $request->validate([
            'campus_id' => 'nullable|exists:campuses,id',
            'name' => ($creating ? 'required' : 'sometimes').'|string|max:255',
            'gender' => 'nullable|in:male,female,mixed',
            'category' => ($creating ? 'required' : 'sometimes').'|in:undergraduate,jupeb,postgraduate',
            'is_active' => 'boolean',
            'due_required' => 'boolean',
            'due_amount' => 'nullable|numeric|min:0',
        ]);

        $data['due_required'] = $request->boolean('due_required');
        if ($data['due_required']) {
            $request->validate(['due_amount' => 'required|numeric|min:0.01']);
            $data['due_amount'] = $request->input('due_amount');
        } else {
            $data['due_amount'] = null;
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function allocationFilters(Request $request, bool $forExport = false): array
    {
        $rules = [
            'search' => 'nullable|string|max:120',
            'hostel_id' => 'nullable|exists:hostels,id',
            'category' => 'nullable|in:undergraduate,jupeb,postgraduate',
            'status' => 'nullable|in:allocated,vacated,pending,rejected',
        ];
        if ($forExport) {
            $rules['format'] = 'required|in:pdf,excel,word';
        }

        return $request->validate($rules);
    }
}
