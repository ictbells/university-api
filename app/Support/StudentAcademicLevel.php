<?php

namespace App\Support;

use App\Models\AcademicLevel;
use App\Models\Student;

/**
 * Maps a student's numeric current_level + study track to catalog AcademicLevel
 * rows, fee level_code values, and human-readable labels.
 *
 * JUPEB keeps current_level=100 for progression, but fees/labels use the JUPEB
 * academic level (often named "JUPEB" with an empty or "100" code).
 */
class StudentAcademicLevel
{
    public static function resolve(Student $student): ?AcademicLevel
    {
        $student->loadMissing(['application', 'program']);
        $studyLevel = StudyLevel::ofStudent($student);
        $code = $student->current_level !== null ? (string) $student->current_level : '';

        $query = AcademicLevel::query()
            ->where('study_level', $studyLevel)
            ->where('is_active', true);

        // JUPEB students keep current_level=100 internally. Never resolve them to
        // a numeric 100-band row — use the JUPEB catalog level (name/code JUPEB).
        if ($studyLevel === StudyLevel::JUPEB) {
            $named = (clone $query)
                ->where(function ($builder) {
                    $builder->where('code', 'like', 'JUPEB%')
                        ->orWhere('name', 'like', 'JUPEB%');
                })
                ->orderBy('sort_order')
                ->orderBy('id')
                ->first();
            if ($named) {
                return $named;
            }

            return $query->orderBy('sort_order')->orderBy('id')->first();
        }

        if ($code !== '') {
            $matched = (clone $query)
                ->where(function ($builder) use ($code) {
                    $builder->where('code', $code)
                        ->orWhere('code', $code.'L')
                        ->orWhere('name', 'like', $code.'%');
                })
                ->orderBy('sort_order')
                ->first();
            if ($matched) {
                return $matched;
            }
        }

        return null;
    }

    /**
     * Fee schedule keys that may apply to this student.
     *
     * @return list<string>
     */
    public static function feeLevelCodes(Student $student): array
    {
        $codes = [];
        $level = self::resolve($student);
        if ($level) {
            if ($level->code !== null && trim((string) $level->code) !== '') {
                $codes[] = trim((string) $level->code);
            }
            if ($level->name !== null && trim((string) $level->name) !== '') {
                $codes[] = trim((string) $level->name);
            }
        }

        if ($student->current_level !== null && $student->current_level !== '') {
            $n = (string) $student->current_level;
            $codes[] = $n;
            $codes[] = $n.'L';
            $codes[] = $n.' Level';
        }

        $unique = [];
        foreach ($codes as $code) {
            $code = trim($code);
            if ($code === '') {
                continue;
            }
            $unique[strtolower($code)] = $code;
        }

        return array_values($unique);
    }

    /**
     * Preferred level_code to stamp on invoices / receipts.
     */
    public static function primaryFeeLevelCode(Student $student): ?string
    {
        $level = self::resolve($student);
        if ($level) {
            $code = trim((string) ($level->code ?: ''));
            if ($code !== '') {
                return $code;
            }
            $name = trim((string) ($level->name ?: ''));
            if ($name !== '') {
                return $name;
            }
        }

        if ($student->current_level !== null && $student->current_level !== '') {
            return (string) $student->current_level;
        }

        return null;
    }

    public static function label(Student $student): ?string
    {
        if (StudyLevel::ofStudent($student->loadMissing(['application', 'program'])) === StudyLevel::JUPEB) {
            $level = self::resolve($student);
            foreach ([trim((string) ($level?->name ?: '')), trim((string) ($level?->code ?: ''))] as $candidate) {
                if ($candidate !== '' && ! preg_match('/^\d{2,3}/', $candidate)) {
                    return $candidate;
                }
            }

            return 'JUPEB';
        }

        $level = self::resolve($student);
        if ($level) {
            $name = trim((string) ($level->name ?: ''));
            if ($name !== '') {
                return $name;
            }
            $code = trim((string) ($level->code ?: ''));
            if ($code !== '') {
                return self::formatNumericBand($code) ?? $code;
            }
        }

        if ($student->current_level !== null && $student->current_level !== '') {
            return self::formatNumericBand((string) $student->current_level);
        }

        return null;
    }

    public static function formatNumericBand(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            $n = (int) $value;
            if ($n <= 0) {
                return null;
            }
            if ($n < 100) {
                return (string) $n;
            }

            return $n.' Level';
        }

        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }
        if (preg_match('/^(\d{3})\s*L(?:evel)?$/i', $text, $match)) {
            return ((int) $match[1]).' Level';
        }
        if (preg_match('/^\d+$/', $text)) {
            return self::formatNumericBand((int) $text);
        }

        return $text;
    }
}
