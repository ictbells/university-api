<?php

namespace App\Support;

class CatalogImportColumns
{
    public const TYPES = ['colleges', 'departments', 'programmes', 'olevel', 'courses'];

    public static function sheetTitle(string $type): string
    {
        return match ($type) {
            'colleges' => 'Colleges',
            'departments' => 'Departments',
            'programmes' => 'Programmes',
            'olevel' => 'Olevel',
            'courses' => 'Courses',
            default => throw new \InvalidArgumentException('Unknown catalogue import type.'),
        };
    }

    /**
     * @return list<string>
     */
    public static function all(string $type): array
    {
        return match ($type) {
            'colleges' => ['name', 'code', 'campus_code'],
            'departments' => ['name', 'code', 'college_code'],
            'programmes' => [
                'name',
                'code',
                'department_code',
                'award_type',
                'study_level',
                'duration_years',
                'entry_modes',
                'is_research_degree',
            ],
            'olevel' => ['name', 'code', 'is_active'],
            'courses' => ['code', 'title', 'units', 'course_type', 'department_code', 'programme_code', 'level_code'],
            default => throw new \InvalidArgumentException('Unknown catalogue import type.'),
        };
    }

    /**
     * @return list<string>
     */
    public static function required(string $type): array
    {
        return match ($type) {
            'colleges' => ['name', 'campus_code'],
            'departments' => ['name', 'college_code'],
            'programmes' => ['name', 'department_code', 'award_type', 'study_level', 'duration_years', 'entry_modes'],
            'olevel' => ['name'],
            'courses' => ['code', 'title', 'department_code'],
            default => throw new \InvalidArgumentException('Unknown catalogue import type.'),
        };
    }

    /**
     * @return array<string, string>
     */
    public static function sample(string $type): array
    {
        $row = array_fill_keys(self::all($type), '');

        return match ($type) {
            'colleges' => array_merge($row, [
                'name' => 'College of Natural and Applied Sciences',
                'code' => 'COLNAS',
                'campus_code' => 'MAIN',
            ]),
            'departments' => array_merge($row, [
                'name' => 'Computer Science',
                'code' => 'CSC',
                'college_code' => 'COLNAS',
            ]),
            'programmes' => array_merge($row, [
                'name' => 'B.Sc Computer Science',
                'code' => 'BSC-CS',
                'department_code' => 'CSC',
                'award_type' => 'B.Sc',
                'study_level' => 'undergraduate',
                'duration_years' => '4',
                'entry_modes' => 'utme,de',
                'is_research_degree' => 'no',
            ]),
            'olevel' => array_merge($row, [
                'name' => 'English Language',
                'code' => 'ENG',
                'is_active' => 'yes',
            ]),
            'courses' => array_merge($row, [
                'code' => 'CSC 101',
                'title' => 'Introduction to Computing',
                'units' => '3',
                'course_type' => 'departmental',
                'department_code' => 'CSC',
                'programme_code' => 'BSC-CS',
                'level_code' => '100',
            ]),
            default => $row,
        };
    }

    /**
     * @return list<string>
     */
    public static function instructions(string $type): array
    {
        $order = 'Import order: Colleges → Departments → Programmes → Courses. O’level is independent. Campuses must already exist.';
        $skip = 'Matching rows are skipped (same code, or same name under the same parent if code is blank). Existing records are not updated.';

        return match ($type) {
            'colleges' => [
                'Import colleges',
                '',
                $order,
                $skip,
                'Required: name, campus_code. Optional: code.',
                'Use the Campuses lookup sheet for campus_code values.',
            ],
            'departments' => [
                'Import departments',
                '',
                $order,
                $skip,
                'Required: name, college_code. Optional: code.',
                'Use the Colleges lookup sheet for college_code values.',
            ],
            'programmes' => [
                'Import programmes',
                '',
                $order,
                $skip,
                'Required: name, department_code, award_type, study_level, duration_years, entry_modes.',
                'study_level: undergraduate or postgraduate. entry_modes: comma-separated utme, de, jupeb, transfer, pg.',
                'Optional: code, is_research_degree (yes/no). Workflow is assigned from the programme defaults.',
            ],
            'olevel' => [
                'Import O’level subjects',
                '',
                $skip,
                'Required: name. Optional: code, is_active (yes/no, default yes).',
            ],
            'courses' => [
                'Import courses',
                '',
                $order,
                $skip,
                'Required: code, title, department_code.',
                'Optional: units (default 3), course_type (general, faculty, departmental), programme_code, level_code.',
                'Blank programme_code on a new general course attaches all active programmes.',
            ],
            default => [$order, $skip],
        };
    }

    public static function filename(string $type): string
    {
        return match ($type) {
            'colleges' => 'college-import-template.xlsx',
            'departments' => 'department-import-template.xlsx',
            'programmes' => 'programme-import-template.xlsx',
            'olevel' => 'olevel-import-template.xlsx',
            'courses' => 'course-catalogue-template.xlsx',
            default => 'catalog-import-template.xlsx',
        };
    }
}
