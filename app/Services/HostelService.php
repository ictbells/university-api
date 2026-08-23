<?php

namespace App\Services;

use App\Models\AcademicLevel;
use App\Models\AcademicTerm;
use App\Models\Hostel;
use App\Models\HostelAllocation;
use App\Models\HostelBed;
use App\Models\HostelLevelWindow;
use App\Models\Setting;
use App\Models\Student;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class HostelService
{
    public function __construct(private HostelRoomService $rooms) {}
    public function categories(): array
    {
        return [
            ['key' => Hostel::CATEGORY_UNDERGRADUATE, 'label' => 'Undergraduate'],
            ['key' => Hostel::CATEGORY_JUPEB, 'label' => 'JUPEB'],
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

        return $student->application?->entry_mode === 'jupeb'
            ? Hostel::CATEGORY_JUPEB
            : Hostel::CATEGORY_UNDERGRADUATE;
    }

    public function levelWindows(string $category, ?int $termId = null): Collection
    {
        $termId ??= $this->currentTermId();

        $levels = AcademicLevel::query()
            ->where('study_level', 'undergraduate')
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
            $window = $windows->get($level->id)?->sortByDesc(fn (HostelLevelWindow $row) => $row->academic_term_id ? 1 : 0)->first();

            return [
                'academic_level_id' => $level->id,
                'level_name' => $level->name,
                'level_code' => $level->code,
                'sort_order' => $level->sort_order,
                'category' => $category,
                'is_active' => (bool) ($window?->is_active ?? false),
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
            HostelLevelWindow::query()->updateOrCreate(
                [
                    'category' => $category,
                    'academic_level_id' => $row['academic_level_id'],
                    'academic_term_id' => $termId,
                ],
                [
                    'is_active' => (bool) ($row['is_active'] ?? false),
                    'opens_at' => $row['opens_at'] ?? null,
                    'closes_at' => $row['closes_at'] ?? null,
                ],
            );
        }

        return $this->levelWindows($category, $termId);
    }

    public function isLevelOpen(string $category, Student $student, ?int $termId = null): bool
    {
        $termId ??= $this->currentTermId();
        $level = AcademicLevel::query()
            ->where('study_level', 'undergraduate')
            ->where('code', (string) $student->current_level)
            ->first();

        if (! $level) {
            return false;
        }

        $window = HostelLevelWindow::query()
            ->where('category', $category)
            ->where('academic_level_id', $level->id)
            ->where('is_active', true)
            ->where(function (Builder $query) use ($termId) {
                $query->whereNull('academic_term_id');
                if ($termId) {
                    $query->orWhere('academic_term_id', $termId);
                }
            })
            ->first();

        if (! $window) {
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

    public function eligibleStudentsQuery(string $category, ?int $termId = null): Builder
    {
        $termId ??= $this->currentTermId();

        $activeLevelIds = HostelLevelWindow::query()
            ->where('category', $category)
            ->where('is_active', true)
            ->where(function (Builder $query) use ($termId) {
                $query->whereNull('academic_term_id');
                if ($termId) {
                    $query->orWhere('academic_term_id', $termId);
                }
            })
            ->pluck('academic_level_id');

        $activeLevelCodes = AcademicLevel::query()
            ->whereIn('id', $activeLevelIds)
            ->pluck('code')
            ->map(fn ($code) => (int) $code)
            ->all();

        $query = Student::query()
            ->with(['application', 'program', 'activeHostelAllocation'])
            ->where('status', 'active')
            ->whereDoesntHave('hostelAllocations', fn (Builder $inner) => $inner->where('status', 'allocated'))
            ->when($category === Hostel::CATEGORY_JUPEB, function (Builder $inner) {
                $inner->whereHas('application', fn (Builder $app) => $app->where('entry_mode', 'jupeb'));
            }, function (Builder $inner) {
                $inner->where(function (Builder $scope) {
                    $scope->whereHas('application', fn (Builder $app) => $app->where('entry_mode', '!=', 'jupeb'))
                        ->orWhereDoesntHave('application');
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

        if ($student->activeHostelAllocation()->exists()) {
            throw ValidationException::withMessages(['student_id' => 'Student already has an active hostel allocation.']);
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

    public function hostelStats(): array
    {
        $hostels = Hostel::query()->with('blocks.rooms.beds')->get();
        $totalBeds = 0;
        $availableBeds = 0;
        $byCategory = [];

        foreach ($hostels as $hostel) {
            $category = $hostel->category ?? Hostel::CATEGORY_UNDERGRADUATE;
            $byCategory[$category] ??= ['hostels' => 0, 'beds' => 0, 'available' => 0];
            $byCategory[$category]['hostels']++;

            foreach ($hostel->blocks as $block) {
                foreach ($block->rooms as $room) {
                    foreach ($room->beds as $bed) {
                        $totalBeds++;
                        $byCategory[$category]['beds']++;
                        if ($bed->status === 'available') {
                            $availableBeds++;
                            $byCategory[$category]['available']++;
                        }
                    }
                }
            }
        }

        return [
            'hostels' => $hostels->count(),
            'total_beds' => $totalBeds,
            'available_beds' => $availableBeds,
            'occupied_beds' => $totalBeds - $availableBeds,
            'by_category' => $byCategory,
        ];
    }
}
