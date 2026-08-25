<?php

namespace App\Services;

use App\Models\AcademicLevel;
use App\Models\AcademicTerm;
use App\Models\FeeItem;
use App\Models\Hostel;
use App\Models\HostelAllocation;
use App\Models\HostelBed;
use App\Models\HostelBlock;
use App\Models\HostelLevelWindow;
use App\Models\HostelRoom;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\Student;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class HostelService
{
    public function __construct(
        private HostelRoomService $rooms,
        private InvoiceService $invoices,
    ) {}

    public function categories(): array
    {
        return [
            ['key' => Hostel::CATEGORY_UNDERGRADUATE, 'label' => 'Undergraduate'],
            ['key' => Hostel::CATEGORY_JUPEB, 'label' => 'JUPEB'],
            ['key' => Hostel::CATEGORY_POSTGRADUATE, 'label' => 'Postgraduate'],
        ];
    }

    public function currentTermId(): ?int
    {
        $value = Setting::getValue('current_term_id');

        return $value ? (int) $value : AcademicTerm::query()->where('is_current', true)->value('id');
    }

    public function studentCategory(Student $student): string
    {
        $student->loadMissing('application');

        if ($student->study_level === 'postgraduate' || $student->application?->entry_mode === 'pg') {
            return Hostel::CATEGORY_POSTGRADUATE;
        }

        return $student->application?->entry_mode === 'jupeb'
            ? Hostel::CATEGORY_JUPEB
            : Hostel::CATEGORY_UNDERGRADUATE;
    }

    public function levelWindows(string $category, ?int $termId = null): Collection
    {
        $termId ??= $this->currentTermId();

        $levels = AcademicLevel::query()
            ->where('study_level', $this->studyLevelForCategory($category))
            ->orderBy('sort_order')
            ->get();

        $windows = HostelLevelWindow::query()
            ->where('category', $category)
            ->where(function (Builder $query) use ($termId) {
                $query->whereNull('academic_term_id');
                if ($termId) {
                    $query->orWhere('academic_term_id', $termId);
                }
            })
            ->with('academicLevel')
            ->get()
            ->groupBy('academic_level_id');

        return $levels->map(function (AcademicLevel $level) use ($windows, $category, $termId) {
            $window = $this->preferTermWindow($windows->get($level->id), $termId);

            return [
                'academic_level_id' => $level->id,
                'level_name' => $level->name,
                'level_code' => $level->code,
                'sort_order' => $level->sort_order,
                'category' => $category,
                'is_active' => (bool) ($window?->is_active ?? false),
                'is_open' => $this->windowIsOpen($window),
                'opens_at' => $window?->opens_at,
                'closes_at' => $window?->closes_at,
                'window_id' => $window?->id,
                'academic_term_id' => $termId,
            ];
        });
    }

    public function syncLevelWindows(string $category, array $levels, ?int $termId = null): Collection
    {
        $termId ??= $this->currentTermId();

        foreach ($levels as $row) {
            $active = (bool) ($row['is_active'] ?? false);
            $payload = ['is_active' => $active];
            if (array_key_exists('opens_at', $row) || array_key_exists('closes_at', $row)) {
                $payload['opens_at'] = $row['opens_at'] ?? null;
                $payload['closes_at'] = $row['closes_at'] ?? null;
            } elseif ($active) {
                $payload['opens_at'] = null;
                $payload['closes_at'] = null;
            }

            HostelLevelWindow::query()->updateOrCreate(
                [
                    'category' => $category,
                    'academic_level_id' => $row['academic_level_id'],
                    'academic_term_id' => $termId,
                ],
                $payload,
            );
        }

        return $this->levelWindows($category, $termId);
    }

    public function isLevelOpen(string $category, Student $student, ?int $termId = null): bool
    {
        $termId ??= $this->currentTermId();
        $level = $this->academicLevelForStudent($category, $student);

        if (! $level) {
            return false;
        }

        return $this->windowIsOpen($this->resolvedLevelWindow($category, $level->id, $termId));
    }

    public function eligibleStudentsQuery(string $category, ?int $termId = null): Builder
    {
        $termId ??= $this->currentTermId();

        $activeLevelCodes = AcademicLevel::query()
            ->where('study_level', $this->studyLevelForCategory($category))
            ->get()
            ->filter(fn (AcademicLevel $level) => $this->windowIsOpen($this->resolvedLevelWindow($category, $level->id, $termId)))
            ->map(function (AcademicLevel $level) {
                $code = trim((string) $level->code);

                return is_numeric($code) ? (int) $code : $code;
            })
            ->filter(fn ($code) => $code !== '' && $code !== 0)
            ->values()
            ->all();

        $query = Student::query()
            ->with(['application', 'program', 'activeHostelAllocation'])
            ->where('status', 'active')
            ->whereDoesntHave('hostelAllocations', fn (Builder $inner) => $inner->whereIn('status', ['allocated', 'pending']))
            ->when($category === Hostel::CATEGORY_JUPEB, function (Builder $inner) {
                $inner->whereHas('application', fn (Builder $app) => $app->where('entry_mode', 'jupeb'));
            })
            ->when($category === Hostel::CATEGORY_POSTGRADUATE, function (Builder $inner) {
                $inner->where(function (Builder $scope) {
                    $scope->where('study_level', 'postgraduate')
                        ->orWhereHas('application', fn (Builder $app) => $app->where('entry_mode', 'pg'));
                });
            })
            ->when($category === Hostel::CATEGORY_UNDERGRADUATE, function (Builder $inner) {
                $inner->where(function (Builder $scope) {
                    $scope->where(function (Builder $level) {
                        $level->whereNull('study_level')->orWhere('study_level', '!=', 'postgraduate');
                    })->where(function (Builder $entry) {
                        $entry->whereHas('application', fn (Builder $app) => $app->whereNotIn('entry_mode', ['jupeb', 'pg']))
                            ->orWhereDoesntHave('application');
                    });
                });
            });

        if ($activeLevelCodes === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->whereIn('current_level', $activeLevelCodes)
            ->orderBy('current_level')
            ->orderBy('id');
    }

    public function priorityLabel(Student $student): string
    {
        return match ((int) $student->current_level) {
            100 => 'Highest (100L)',
            200 => '200L',
            300 => '300L',
            400 => '400L',
            default => (string) $student->current_level.'L',
        };
    }

    public function validateAllocation(Student $student, HostelBed $bed, ?int $termId = null): void
    {
        $bed->loadMissing('room.block.hostel');
        $hostel = $bed->room?->block?->hostel;
        $room = $bed->room;

        if (! $hostel || ! $hostel->is_active) {
            throw ValidationException::withMessages(['hostel_bed_id' => 'Hostel is not available.']);
        }

        if (! $room || ! $room->is_active) {
            throw ValidationException::withMessages(['hostel_bed_id' => 'This room is disabled.']);
        }

        if ($room->is_reserved) {
            throw ValidationException::withMessages(['hostel_bed_id' => 'This room is reserved and cannot take new allocations.']);
        }

        if ($bed->status !== 'available') {
            throw ValidationException::withMessages(['hostel_bed_id' => 'Bed is not available.']);
        }

        $category = $hostel->category ?? Hostel::CATEGORY_UNDERGRADUATE;
        if ($this->studentCategory($student) !== $category) {
            throw ValidationException::withMessages([
                'student_id' => 'Student category does not match this hostel ('.strtoupper($category).').',
            ]);
        }

        if ($student->hostelAllocations()->whereIn('status', ['allocated', 'pending'])->exists()) {
            throw ValidationException::withMessages(['student_id' => 'Student already has a hostel bed request or allocation.']);
        }

        if (! $this->isLevelOpen($category, $student, $termId)) {
            throw ValidationException::withMessages([
                'student_id' => 'Hostel allocation is not open for this student\'s level ('.$student->current_level.'L).',
            ]);
        }

        $studentGender = strtolower((string) $student->gender);
        $hostelGender = strtolower((string) $hostel->gender);
        if ($hostelGender !== 'mixed' && $studentGender && $hostelGender !== $studentGender) {
            throw ValidationException::withMessages(['student_id' => 'Student gender does not match hostel gender policy.']);
        }

        $this->rooms->assertRoomGenderMatch($student, $room, $hostel);
    }

    public function hostelBedCounts(): Collection
    {
        return HostelBed::query()
            ->join('hostel_rooms', 'hostel_rooms.id', '=', 'hostel_beds.hostel_room_id')
            ->join('hostel_blocks', 'hostel_blocks.id', '=', 'hostel_rooms.hostel_block_id')
            ->whereNull('hostel_rooms.deleted_at')
            ->whereNull('hostel_blocks.deleted_at')
            ->groupBy('hostel_blocks.hostel_id')
            ->selectRaw("
                hostel_blocks.hostel_id as hostel_id,
                COUNT(*) as total_beds,
                SUM(CASE WHEN hostel_beds.status = 'available' THEN 1 ELSE 0 END) as available_beds
            ")
            ->get()
            ->keyBy('hostel_id');
    }

    public function hostelStats(): array
    {
        $hostelCount = Hostel::query()->count();
        $byCategory = Hostel::query()
            ->selectRaw('category, COUNT(*) as hostels')
            ->groupBy('category')
            ->get()
            ->mapWithKeys(fn ($row) => [
                $row->category => ['hostels' => (int) $row->hostels, 'beds' => 0, 'available' => 0],
            ])
            ->all();

        $bedRows = HostelBed::query()
            ->join('hostel_rooms', 'hostel_rooms.id', '=', 'hostel_beds.hostel_room_id')
            ->join('hostel_blocks', 'hostel_blocks.id', '=', 'hostel_rooms.hostel_block_id')
            ->join('hostels', 'hostels.id', '=', 'hostel_blocks.hostel_id')
            ->whereNull('hostel_rooms.deleted_at')
            ->whereNull('hostel_blocks.deleted_at')
            ->whereNull('hostels.deleted_at')
            ->groupBy('hostels.category')
            ->selectRaw("
                hostels.category as category,
                COUNT(*) as beds,
                SUM(CASE WHEN hostel_beds.status = 'available' THEN 1 ELSE 0 END) as available
            ")
            ->get();

        $totalBeds = 0;
        $availableBeds = 0;
        foreach ($bedRows as $row) {
            $category = $row->category ?: Hostel::CATEGORY_UNDERGRADUATE;
            $byCategory[$category] ??= ['hostels' => 0, 'beds' => 0, 'available' => 0];
            $byCategory[$category]['beds'] = (int) $row->beds;
            $byCategory[$category]['available'] = (int) $row->available;
            $totalBeds += (int) $row->beds;
            $availableBeds += (int) $row->available;
        }

        return [
            'hostels' => $hostelCount,
            'total_beds' => $totalBeds,
            'available_beds' => $availableBeds,
            'occupied_beds' => $totalBeds - $availableBeds,
            'by_category' => $byCategory,
        ];
    }

    public function allocateBed(Student $student, HostelBed $bed, ?int $termId = null): HostelAllocation
    {
        $student->loadMissing(['user', 'application']);

        return DB::transaction(function () use ($student, $bed, $termId) {
            $this->cancelPendingRequests($student);
            $bed = HostelBed::query()->lockForUpdate()->with('room.block.hostel')->findOrFail($bed->id);
            $termId ??= $this->currentTermId();
            $this->validateAllocation($student, $bed, $termId);

            $allocation = HostelAllocation::query()->create([
                'student_id' => $student->id,
                'hostel_bed_id' => $bed->id,
                'academic_term_id' => $termId,
                'status' => 'allocated',
                'allocated_at' => now(),
            ]);
            $bed->update(['status' => 'occupied']);
            $this->rooms->lockRoomGenderFromStudent($bed->room, $bed->room->block->hostel, $student);
            $this->raiseHostelDueIfRequired($student, $bed->room->block->hostel);

            return $allocation->load(['bed.room.block.hostel', 'student.application', 'academicTerm']);
        });
    }

    public function requestBed(Student $student, HostelBed $bed, ?int $termId = null): HostelAllocation
    {
        $student->loadMissing(['user', 'application']);

        return DB::transaction(function () use ($student, $bed, $termId) {
            $bed = HostelBed::query()->lockForUpdate()->with('room.block.hostel')->findOrFail($bed->id);
            $termId ??= $this->currentTermId();
            $this->validateAllocation($student, $bed, $termId);

            $allocation = HostelAllocation::query()->create([
                'student_id' => $student->id,
                'hostel_bed_id' => $bed->id,
                'academic_term_id' => $termId,
                'status' => 'pending',
                'allocated_at' => now(),
            ]);
            $bed->update(['status' => 'reserved']);
            $this->rooms->lockRoomGenderFromStudent($bed->room, $bed->room->block->hostel, $student);

            return $allocation->load(['bed.room.block.hostel', 'student.application', 'academicTerm']);
        });
    }

    public function approveAllocation(HostelAllocation $allocation): HostelAllocation
    {
        return DB::transaction(function () use ($allocation) {
            $allocation = HostelAllocation::query()->lockForUpdate()->with(['bed.room.block.hostel', 'student.user', 'student.application'])->findOrFail($allocation->id);
            if ($allocation->status !== 'pending') {
                throw ValidationException::withMessages(['allocation' => 'Only pending bed requests can be approved.']);
            }

            $bed = HostelBed::query()->lockForUpdate()->findOrFail($allocation->hostel_bed_id);
            if (! in_array($bed->status, ['reserved', 'available'], true)) {
                throw ValidationException::withMessages(['allocation' => 'That bed is no longer available to approve.']);
            }

            $allocation->update([
                'status' => 'allocated',
                'allocated_at' => now(),
            ]);
            $bed->update(['status' => 'occupied']);
            if ($bed->room && $bed->room->block?->hostel && $allocation->student) {
                $this->rooms->lockRoomGenderFromStudent($bed->room, $bed->room->block->hostel, $allocation->student);
                $this->raiseHostelDueIfRequired($allocation->student, $bed->room->block->hostel);
            }

            return $allocation->fresh(['bed.room.block.hostel', 'student.application', 'academicTerm']);
        });
    }

    public function rejectAllocation(HostelAllocation $allocation): HostelAllocation
    {
        return DB::transaction(function () use ($allocation) {
            $allocation = HostelAllocation::query()->lockForUpdate()->with(['bed.room.block.hostel', 'student'])->findOrFail($allocation->id);
            if ($allocation->status !== 'pending') {
                throw ValidationException::withMessages(['allocation' => 'Only pending bed requests can be rejected.']);
            }

            $allocation->update([
                'status' => 'rejected',
                'vacated_at' => now(),
            ]);
            if ($allocation->bed && $allocation->bed->status === 'reserved') {
                $allocation->bed->update(['status' => 'available']);
            }
            if ($allocation->bed?->room && $allocation->bed->room->block?->hostel) {
                $this->rooms->resetRoomGenderIfEmpty($allocation->bed->room, $allocation->bed->room->block->hostel);
                $this->rooms->applyRoomAvailability($allocation->bed->room->fresh('beds'));
            }

            return $allocation->fresh(['bed.room.block.hostel', 'student']);
        });
    }

    public function formatAllocation(HostelAllocation $allocation): array
    {
        $allocation->loadMissing(['bed.room.block.hostel', 'academicTerm']);
        $bed = $allocation->bed;
        $room = $bed?->room;
        $hostel = $room?->block?->hostel;

        return [
            'id' => $allocation->id,
            'status' => $allocation->status,
            'allocated_at' => $allocation->allocated_at,
            'vacated_at' => $allocation->vacated_at,
            'hostel_name' => $hostel?->name,
            'hostel_gender' => $hostel?->gender,
            'hostel_category' => $hostel?->category,
            'block_name' => $room?->block?->name,
            'room_number' => $room?->number,
            'bed_label' => $bed?->label,
            'term' => $allocation->academicTerm?->name,
            'session' => $allocation->academicTerm?->session_label,
            'due_required' => (bool) ($hostel?->due_required),
            'due_amount' => $hostel && $hostel->chargesDue() ? (float) $hostel->due_amount : null,
        ];
    }

    public function windowForStudent(Student $student, ?int $termId = null): ?array
    {
        $category = $this->studentCategory($student);

        return $this->levelWindows($category, $termId)
            ->first(function (array $row) use ($student, $category) {
                $level = $this->academicLevelForStudent($category, $student);

                return $level && (int) $row['academic_level_id'] === (int) $level->id;
            });
    }

    public function selectionHostelsForStudent(Student $student): array
    {
        $category = $this->studentCategory($student);
        $gender = strtolower((string) $student->gender);

        $hostels = Hostel::query()
            ->with(['blocks.rooms.beds'])
            ->where('is_active', true)
            ->where('category', $category)
            ->when($gender !== '', function (Builder $query) use ($gender) {
                $query->where(function (Builder $scope) use ($gender) {
                    $scope->where('gender', 'mixed')->orWhere('gender', $gender);
                });
            })
            ->orderBy('name')
            ->get();

        return $hostels->map(function (Hostel $hostel) use ($student) {
            $blocks = $hostel->blocks->sortBy(function (HostelBlock $block) {
                if (preg_match('/\b([A-Za-z])\b/', $block->name, $match)) {
                    return strtoupper($match[1]);
                }

                return strtoupper($block->name);
            })->values()->map(function (HostelBlock $block) use ($student, $hostel) {
                $rooms = $block->rooms->map(function (HostelRoom $room) use ($student, $hostel, $block) {
                    $formatted = $this->rooms->formatRoom($room);
                    $genderOk = true;
                    try {
                        $this->rooms->assertRoomGenderMatch($student, $room, $hostel);
                    } catch (ValidationException) {
                        $genderOk = false;
                    }

                    $occupied = (int) ($formatted['available_beds'] ?? 0) === 0;
                    $reserved = (bool) $room->is_reserved;
                    $inactive = ! (bool) $room->is_active;
                    $selectable = $genderOk && ! $occupied && ! $reserved && ! $inactive;
                    $disabledReason = null;
                    if ($inactive) {
                        $disabledReason = 'Disabled';
                    } elseif ($reserved) {
                        $disabledReason = 'Reserved';
                    } elseif (! $genderOk) {
                        $disabledReason = 'Not available for your gender';
                    } elseif ($occupied) {
                        $disabledReason = 'Occupied';
                    }

                    return [
                        ...$formatted,
                        'block_id' => $block->id,
                        'code' => $this->roomCode($block->name, (string) $room->number),
                        'occupied' => $occupied,
                        'selectable' => $selectable,
                        'disabled_reason' => $disabledReason,
                    ];
                })->sortBy(function (array $room) {
                    if (preg_match('/(\d+)/', (string) ($room['code'] ?? $room['number'] ?? ''), $match)) {
                        return (int) $match[1];
                    }

                    return $room['code'] ?? $room['number'] ?? '';
                })->values();

                return [
                    'id' => $block->id,
                    'name' => $this->blockLabel($block->name),
                    'room_count' => $rooms->count(),
                    'available_rooms' => $rooms->where('selectable', true)->count(),
                    'rooms' => $rooms->all(),
                ];
            });

            return [
                'id' => $hostel->id,
                'name' => $hostel->name,
                'gender' => $hostel->gender,
                'category' => $hostel->category,
                'due_required' => (bool) $hostel->due_required,
                'due_amount' => $hostel->chargesDue() ? (float) $hostel->due_amount : null,
                'blocks' => $blocks->all(),
            ];
        })->values()->all();
    }

    public function studentSnapshot(Student $student): array
    {
        $student->loadMissing(['application', 'activeHostelAllocation.bed.room.block.hostel']);
        $category = $this->studentCategory($student);
        $window = $this->windowForStudent($student);
        $active = $student->activeHostelAllocation;
        $pending = HostelAllocation::query()
            ->with(['bed.room.block.hostel', 'academicTerm'])
            ->where('student_id', $student->id)
            ->where('status', 'pending')
            ->latest()
            ->first();
        $allocation = $active ?: $pending;
        $open = $this->isLevelOpen($category, $student);
        $canSelect = $open && ! $active && ! $pending;
        $allocatedHostel = $active?->bed?->room?->block?->hostel;
        $dueRequired = $active && ($allocatedHostel?->chargesDue() ?? false);

        return [
            'category' => $category,
            'level' => $student->current_level,
            'gender' => $student->gender,
            'window' => $window,
            'window_open' => $open,
            'can_select' => $canSelect,
            'allocation' => $allocation ? $this->formatAllocation($allocation) : null,
            'history' => HostelAllocation::query()
                ->with(['bed.room.block.hostel', 'academicTerm'])
                ->where('student_id', $student->id)
                ->latest()
                ->get()
                ->map(fn (HostelAllocation $row) => $this->formatAllocation($row))
                ->values(),
            'hostels' => $canSelect ? $this->selectionHostelsForStudent($student) : [],
            'invoices' => Invoice::query()
                ->where('student_id', $student->id)
                ->where('category', 'hostel')
                ->latest()
                ->get(),
            'fee' => $dueRequired ? [
                'name' => 'Hostel due — '.$allocatedHostel->name,
                'amount' => (float) $allocatedHostel->due_amount,
            ] : null,
        ];
    }

    /**
     * @param  array{search?: string, hostel_id?: int, category?: string, status?: string}  $filters
     */
    public function staffAllocationQuery(array $filters): Builder
    {
        $query = HostelAllocation::query()
            ->with([
                'student:id,first_name,last_name,current_level,matric_number,student_number,program_id',
                'student.program:id,name',
                'bed:id,label,hostel_room_id',
                'bed.room:id,number,hostel_block_id',
                'bed.room.block:id,name,hostel_id',
                'bed.room.block.hostel:id,name,category',
            ]);

        if (! empty($filters['hostel_id'])) {
            $query->whereHas('bed.room.block', fn (Builder $q) => $q->where('hostel_id', $filters['hostel_id']));
        }
        if (! empty($filters['category'])) {
            $query->whereHas('bed.room.block.hostel', fn (Builder $q) => $q->where('category', $filters['category']));
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['search'])) {
            $term = '%'.trim((string) $filters['search']).'%';
            $query->where(function (Builder $q) use ($term) {
                $q->whereHas('student', function (Builder $student) use ($term) {
                    $student->where('first_name', 'like', $term)
                        ->orWhere('last_name', 'like', $term)
                        ->orWhere('matric_number', 'like', $term)
                        ->orWhere('student_number', 'like', $term);
                })
                    ->orWhereHas('bed.room', fn (Builder $room) => $room->where('number', 'like', $term))
                    ->orWhereHas('bed.room.block.hostel', fn (Builder $hostel) => $hostel->where('name', 'like', $term));
            });
        }

        return $query
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 WHEN status = 'allocated' THEN 1 ELSE 2 END")
            ->latest();
    }

    public function formatStaffAllocation(HostelAllocation $allocation): array
    {
        $student = $allocation->student;
        $hostel = $allocation->bed?->room?->block?->hostel;
        $matric = $student?->matric_number ?: $student?->student_number;

        return [
            'id' => $allocation->id,
            'status' => $allocation->status,
            'allocated_at' => $allocation->allocated_at,
            'vacated_at' => $allocation->vacated_at,
            'student_name' => $student ? trim("{$student->first_name} {$student->last_name}") : null,
            'matric_number' => $matric,
            'student_level' => $student?->current_level,
            'program' => $student?->program?->name,
            'hostel_id' => $hostel?->id,
            'hostel_name' => $hostel?->name,
            'hostel_category' => $hostel?->category,
            'block_name' => $allocation->bed?->room?->block?->name,
            'bed_label' => $allocation->bed?->label,
            'room_number' => $allocation->bed?->room?->number,
        ];
    }

    /**
     * @return list<string>
     */
    public function allocationFilterSummary(array $filters): array
    {
        $summary = [];
        if (! empty($filters['search'])) {
            $summary[] = 'Search: '.$filters['search'];
        }
        if (! empty($filters['hostel_id'])) {
            $name = Hostel::query()->withTrashed()->find($filters['hostel_id'])?->name;
            $summary[] = 'Hostel: '.($name ?: '#'.$filters['hostel_id']);
        }
        if (! empty($filters['category'])) {
            $summary[] = 'Category: '.match ($filters['category']) {
                'jupeb' => 'JUPEB',
                'postgraduate' => 'Postgraduate',
                default => 'Undergraduate',
            };
        }
        if (! empty($filters['status'])) {
            $summary[] = 'Status: '.$filters['status'];
        }

        return $summary;
    }

    private function raiseHostelDueIfRequired(Student $student, ?Hostel $hostel): void
    {
        if (! $hostel?->chargesDue() || ! $student->user) {
            return;
        }

        $amount = (float) $hostel->due_amount;
        $description = 'Hostel due — '.$hostel->name;
        $fee = FeeItem::query()->where('category', 'hostel')->where('is_active', true)->first();
        if ($fee) {
            $this->invoices->createForFee($student->user, $fee, $student->application_id, $student->id, $amount, $description);

            return;
        }

        $this->invoices->createForCharge(
            $student->user,
            'hostel',
            $amount,
            $description,
            $student->application_id,
            $student->id,
        );
    }

    private function cancelPendingRequests(Student $student): void
    {
        $pending = HostelAllocation::query()
            ->with('bed.room.block.hostel')
            ->where('student_id', $student->id)
            ->where('status', 'pending')
            ->get();

        foreach ($pending as $row) {
            $row->update([
                'status' => 'rejected',
                'vacated_at' => now(),
            ]);
            if ($row->bed?->status === 'reserved') {
                $row->bed->update(['status' => 'available']);
            }
            if ($row->bed?->room && $row->bed->room->block?->hostel) {
                $this->rooms->resetRoomGenderIfEmpty($row->bed->room, $row->bed->room->block->hostel);
                $this->rooms->applyRoomAvailability($row->bed->room->fresh('beds'));
            }
        }
    }

    private function studyLevelForCategory(string $category): string
    {
        return $category === Hostel::CATEGORY_POSTGRADUATE ? 'postgraduate' : 'undergraduate';
    }

    private function resolvedLevelWindow(string $category, int $academicLevelId, ?int $termId = null): ?HostelLevelWindow
    {
        $termId ??= $this->currentTermId();
        $windows = HostelLevelWindow::query()
            ->where('category', $category)
            ->where('academic_level_id', $academicLevelId)
            ->where(function (Builder $query) use ($termId) {
                $query->whereNull('academic_term_id');
                if ($termId) {
                    $query->orWhere('academic_term_id', $termId);
                }
            })
            ->get();

        return $this->preferTermWindow($windows, $termId);
    }

    private function preferTermWindow(?Collection $windows, ?int $termId): ?HostelLevelWindow
    {
        if (! $windows || $windows->isEmpty()) {
            return null;
        }
        if ($termId) {
            $forTerm = $windows->firstWhere('academic_term_id', $termId);
            if ($forTerm) {
                return $forTerm;
            }
        }

        return $windows->firstWhere('academic_term_id', null) ?? $windows->first();
    }

    private function windowIsOpen(?HostelLevelWindow $window): bool
    {
        if (! $window || ! $window->is_active) {
            return false;
        }

        $now = now();
        if ($window->opens_at && $now->lt($window->opens_at)) {
            return false;
        }
        if ($window->closes_at && $now->gt($window->closes_at)) {
            return false;
        }

        return true;
    }

    private function academicLevelForStudent(string $category, Student $student): ?AcademicLevel
    {
        $code = (string) $student->current_level;

        return AcademicLevel::query()
            ->where('study_level', $this->studyLevelForCategory($category))
            ->where(function (Builder $query) use ($code, $student) {
                $query->where('code', $code)
                    ->orWhere('code', 'Y'.$code)
                    ->orWhere('sort_order', (int) $student->current_level);
            })
            ->orderByRaw('CASE WHEN code = ? THEN 0 WHEN code = ? THEN 1 ELSE 2 END', [$code, 'Y'.$code])
            ->first();
    }

    private function blockLabel(string $name): string
    {
        $name = trim($name);
        if (preg_match('/^block\s+(.+)$/i', $name, $match)) {
            return 'Block '.trim($match[1]);
        }

        return 'Block '.$name;
    }

    private function blockLetter(string $name): string
    {
        if (preg_match('/\b([A-Za-z])\b/', $name, $match)) {
            return strtoupper($match[1]);
        }

        if (preg_match('/([A-Za-z])(?!.*[A-Za-z])/', $name, $match)) {
            return strtoupper($match[1]);
        }

        return strtoupper(substr($name, 0, 1) ?: 'R');
    }

    private function roomCode(string $blockName, string $number): string
    {
        $number = strtoupper(trim($number));
        if ($number !== '' && preg_match('/^[A-Z]/', $number)) {
            return $number;
        }

        return $this->blockLetter($blockName).$number;
    }
}
