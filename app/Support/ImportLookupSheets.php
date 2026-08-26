<?php

namespace App\Support;

use App\Models\AcademicLevel;
use App\Models\Campus;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\Hostel;
use App\Models\HostelBlock;
use App\Models\Lga;
use App\Models\OlevelSubject;
use App\Models\Program;
use App\Models\StateOfOrigin;

class ImportLookupSheets
{
    /**
     * @return list<array{title: string, headers: list<string>, rows: list<list<mixed>>}>
     */
    public static function forApplicants(string $entryMode): array
    {
        return [
            self::campuses(),
            self::colleges(),
            self::departments(),
            self::programmes($entryMode),
            self::levels(),
            self::states(),
            self::lgas(),
            self::olevelSubjects(),
        ];
    }

    /**
     * @return list<array{title: string, headers: list<string>, rows: list<list<mixed>>}>
     */
    public static function forStudents(): array
    {
        return [
            self::campuses(),
            self::colleges(),
            self::departments(),
            self::programmes(null),
            self::levels(),
        ];
    }

    /**
     * @return array{title: string, headers: list<string>, rows: list<list<mixed>>}
     */
    public static function campuses(): array
    {
        $headers = ['id', 'code', 'name'];
        $rows = Campus::query()
            ->orderBy('name')
            ->get(['id', 'code', 'name'])
            ->map(fn (Campus $campus) => [
                $campus->id,
                (string) ($campus->code ?? ''),
                (string) $campus->name,
            ])
            ->all();

        return ['title' => 'Campuses', 'headers' => $headers, 'rows' => $rows];
    }

    /**
     * @return array{title: string, headers: list<string>, rows: list<list<mixed>>}
     */
    public static function colleges(): array
    {
        $headers = ['id', 'code', 'name', 'campus_id', 'campus_code'];
        $rows = Faculty::query()
            ->with('campus:id,code,name')
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'campus_id'])
            ->map(fn (Faculty $college) => [
                $college->id,
                (string) ($college->code ?? ''),
                (string) $college->name,
                $college->campus_id,
                (string) ($college->campus?->code ?? ''),
            ])
            ->all();

        return ['title' => 'Colleges', 'headers' => $headers, 'rows' => $rows];
    }

    /**
     * @return array{title: string, headers: list<string>, rows: list<list<mixed>>}
     */
    public static function departments(): array
    {
        $headers = ['id', 'code', 'name', 'college_id', 'college_code'];
        $rows = Department::query()
            ->with('faculty:id,code,name')
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'faculty_id'])
            ->map(fn (Department $department) => [
                $department->id,
                (string) ($department->code ?? ''),
                (string) $department->name,
                $department->faculty_id,
                (string) ($department->faculty?->code ?? ''),
            ])
            ->all();

        return ['title' => 'Departments', 'headers' => $headers, 'rows' => $rows];
    }

    /**
     * @return array{title: string, headers: list<string>, rows: list<list<mixed>>}
     */
    public static function programmes(?string $entryMode = null): array
    {
        $headers = [
            'id', 'code', 'name', 'department_id', 'department_code',
            'college', 'campus', 'entry_modes', 'study_level', 'duration_years', 'is_active',
        ];

        $query = Program::query()
            ->with(['department:id,code,name,faculty_id', 'department.faculty:id,code,name,campus_id', 'department.faculty.campus:id,code,name'])
            ->orderBy('name');

        if (! $entryMode) {
            $query->where('is_active', true);
        }

        $programs = $query->get();
        if ($entryMode) {
            $programs = $programs->filter(function (Program $program) use ($entryMode) {
                $modes = $program->entry_modes;

                return is_array($modes) && in_array($entryMode, $modes, true);
            })->values();
        }

        $rows = $programs->map(function (Program $program) {
            $modes = $program->entry_modes;
            if (! is_array($modes)) {
                $modes = [];
            }

            return [
                $program->id,
                (string) ($program->code ?? ''),
                (string) $program->name,
                $program->department_id,
                (string) ($program->department?->code ?? ''),
                (string) ($program->department?->faculty?->name ?? ''),
                (string) ($program->department?->faculty?->campus?->name ?? ''),
                implode(',', $modes),
                (string) ($program->study_level ?? ''),
                $program->duration_years,
                $program->is_active ? 'yes' : 'no',
            ];
        })->all();

        return ['title' => 'Programmes', 'headers' => $headers, 'rows' => $rows];
    }

    /**
     * @return array{title: string, headers: list<string>, rows: list<list<mixed>>}
     */
    public static function levels(): array
    {
        $headers = ['id', 'code', 'name', 'study_level'];
        $rows = AcademicLevel::query()
            ->orderBy('sort_order')
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'study_level'])
            ->map(fn (AcademicLevel $level) => [
                $level->id,
                (string) ($level->code ?? ''),
                (string) $level->name,
                (string) ($level->study_level ?? ''),
            ])
            ->all();

        return ['title' => 'Levels', 'headers' => $headers, 'rows' => $rows];
    }

    /**
     * @return array{title: string, headers: list<string>, rows: list<list<mixed>>}
     */
    public static function states(): array
    {
        $headers = ['id', 'name'];
        $rows = StateOfOrigin::query()
            ->orderBy('state_title')
            ->get(['state_id', 'state_title'])
            ->map(fn (StateOfOrigin $state) => [
                $state->state_id,
                (string) $state->state_title,
            ])
            ->all();

        return ['title' => 'States', 'headers' => $headers, 'rows' => $rows];
    }

    /**
     * @return array{title: string, headers: list<string>, rows: list<list<mixed>>}
     */
    public static function lgas(): array
    {
        $headers = ['id', 'name', 'state_id', 'state_name'];
        $rows = Lga::query()
            ->with('state:state_id,state_title')
            ->orderBy('lga_title')
            ->get(['lga_id', 'lga_title', 'state_id'])
            ->map(fn (Lga $lga) => [
                $lga->lga_id,
                (string) $lga->lga_title,
                $lga->state_id,
                (string) ($lga->state?->state_title ?? ''),
            ])
            ->all();

        return ['title' => 'LGAs', 'headers' => $headers, 'rows' => $rows];
    }

    /**
     * @return array{title: string, headers: list<string>, rows: list<list<mixed>>}
     */
    public static function olevelSubjects(): array
    {
        $headers = ['id', 'code', 'name'];
        $rows = OlevelSubject::query()
            ->orderBy('name')
            ->get(['id', 'code', 'name'])
            ->map(fn (OlevelSubject $subject) => [
                $subject->id,
                (string) ($subject->code ?? ''),
                (string) $subject->name,
            ])
            ->all();

        return ['title' => 'O-level subjects', 'headers' => $headers, 'rows' => $rows];
    }

    /**
     * @return array{title: string, headers: list<string>, rows: list<list<mixed>>}
     */
    public static function hostels(): array
    {
        $headers = ['id', 'name', 'gender', 'category', 'is_active'];
        $rows = Hostel::query()
            ->orderBy('name')
            ->get(['id', 'name', 'gender', 'category', 'is_active'])
            ->map(fn (Hostel $hostel) => [
                $hostel->id,
                (string) $hostel->name,
                (string) ($hostel->gender ?? ''),
                (string) ($hostel->category ?? ''),
                $hostel->is_active ? 'yes' : 'no',
            ])
            ->all();

        return ['title' => 'Hostels', 'headers' => $headers, 'rows' => $rows];
    }

    /**
     * @return array{title: string, headers: list<string>, rows: list<list<mixed>>}
     */
    public static function hostelBlocks(): array
    {
        $headers = ['id', 'name', 'hostel_id', 'hostel_name'];
        $rows = HostelBlock::query()
            ->with('hostel:id,name')
            ->orderBy('name')
            ->get(['id', 'name', 'hostel_id'])
            ->map(fn (HostelBlock $block) => [
                $block->id,
                (string) $block->name,
                $block->hostel_id,
                (string) ($block->hostel?->name ?? ''),
            ])
            ->all();

        return ['title' => 'Blocks', 'headers' => $headers, 'rows' => $rows];
    }
}
