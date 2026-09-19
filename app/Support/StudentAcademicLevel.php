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
 *
 * Postgraduate uses current_level=1,2,… while catalog rows are often "Year 1"
 * / "Year 2" with empty codes — resolve must bridge that gap for fees & labels.
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

            $year = self::progressionYear($code);
            if ($year !== null) {
                $yearMatched = self::matchYearNamedLevel(clone $query, $year);
                if ($yearMatched) {
                    return $yearMatched;
                }

                // PG Year 1/2 catalog rows often have empty codes and sort_order
                // that is not the year index — pick the Nth level in track order.
                if ($studyLevel === StudyLevel::POSTGRADUATE) {
                    $ordered = (clone $query)->orderBy('sort_order')->orderBy('id')->get();
                    $index = $year - 1;
                    if ($ordered->has($index)) {
                        return $ordered->get($index);
                    }
                }
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

            $year = self::progressionYear($n);
            if ($year !== null) {
                foreach (self::yearAliases($year) as $alias) {
                    $codes[] = $alias;
                }
            }
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
     * Whether an invoice / fee level_code is the student's current band
     * (e.g. current_level 1 vs catalog "Year 1", or 100 vs "Year 1" / "100 Level").
     */
    public static function matchesFeeLevelCode(Student $student, ?string $levelCode): bool
    {
        if ($levelCode === null) {
            return false;
        }
        $needle = strtolower(trim($levelCode));
        if ($needle === '' || $needle === 'all') {
            return false;
        }

        foreach (self::feeLevelCodes($student) as $code) {
            if (strtolower(trim($code)) === $needle) {
                return true;
            }
        }

        $resolved = self::resolve($student);
        if ($resolved) {
            foreach ([trim((string) ($resolved->code ?: '')), trim((string) ($resolved->name ?: ''))] as $candidate) {
                if ($candidate !== '' && strtolower($candidate) === $needle) {
                    return true;
                }
            }
        }

        $invoiceYear = self::yearFromLabel($levelCode);
        $studentRaw = $student->current_level !== null ? (string) $student->current_level : '';
        $studentYear = $studentRaw !== '' ? self::yearFromLabel($studentRaw) : null;
        if ($invoiceYear !== null && $studentYear !== null && $invoiceYear === $studentYear) {
            return true;
        }

        // UG catalogue sometimes uses "Year 1" while students store 100/200 bands.
        $studentBand = self::undergraduateBand($studentRaw);
        if ($invoiceYear !== null && $studentBand !== null && $studentBand === $invoiceYear * 100) {
            return true;
        }
        $invoiceBand = self::undergraduateBand($levelCode);
        if ($invoiceBand !== null && $studentYear !== null && $invoiceBand === $studentYear * 100) {
            return true;
        }
        if ($invoiceBand !== null && $studentBand !== null && $invoiceBand === $studentBand) {
            return true;
        }

        return false;
    }

    /**
     * 100/200/… undergraduate band from a level code, or null.
     */
    public static function undergraduateBand(?string $code): ?int
    {
        if ($code === null || trim($code) === '') {
            return null;
        }
        $text = strtolower(trim($code));
        if (preg_match('/^(\d{3})\s*l(?:evel)?$/', $text, $match)) {
            $band = (int) $match[1];

            return $band >= 100 && $band % 100 === 0 ? $band : null;
        }
        if (preg_match('/^\d+$/', $text)) {
            $n = (int) $text;
            if ($n >= 100 && $n % 100 === 0 && $n < 1000) {
                return $n;
            }
        }

        return null;
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

    /**
     * PG (and similar) progression year from current_level. UG bands 100–500
     * are not years — return null so they keep numeric-band matching.
     */
    private static function progressionYear(string $code): ?int
    {
        return self::yearFromLabel($code);
    }

    /**
     * Extract year index from labels like Year 1, year1, Year One, 1st Year.
     */
    public static function yearFromLabel(string $text): ?int
    {
        $text = strtolower(trim(preg_replace('/\s+/', ' ', $text) ?? ''));
        if ($text === '') {
            return null;
        }

        // Plain 1–20 (PG progression), not UG 100/200 bands.
        if (preg_match('/^\d+$/', $text)) {
            $n = (int) $text;
            if ($n <= 0 || $n >= 100) {
                return null;
            }

            return $n <= 20 ? $n : null;
        }

        $words = self::yearWordMap();

        // year 1 | year1 | year-1 | year_1
        if (preg_match('/^year[\s\-_]?(\d+)(?:\s*l(?:evel)?)?$/', $text, $match)) {
            $year = (int) $match[1];

            return $year > 0 && $year <= 20 ? $year : null;
        }

        // year one | year-one | year_one
        if (preg_match('/^year[\s\-_]([a-z]+)(?:\s*l(?:evel)?)?$/', $text, $match)) {
            $word = $match[1];
            if (isset($words[$word])) {
                return $words[$word];
            }
        }

        // 1st year | first year
        if (preg_match('/^(\d+)(?:st|nd|rd|th)?\s*year(?:\s*l(?:evel)?)?$/', $text, $match)) {
            $year = (int) $match[1];

            return $year > 0 && $year <= 20 ? $year : null;
        }
        if (preg_match('/^([a-z]+)\s*year(?:\s*l(?:evel)?)?$/', $text, $match) && isset($words[$match[1]])) {
            return $words[$match[1]];
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private static function yearAliases(int $year): array
    {
        $word = array_flip(self::yearWordMap())[$year] ?? null;
        $ordinal = match ($year) {
            1 => '1st',
            2 => '2nd',
            3 => '3rd',
            default => $year.'th',
        };
        $aliases = [
            'Year '.$year,
            'Year '.$year.' Level',
            'year'.$year,
            'Year'.$year,
            'Y'.$year,
            $ordinal.' Year',
            (string) $year,
        ];
        if ($word !== null) {
            $title = ucfirst($word);
            $aliases[] = 'Year '.$title;
            $aliases[] = 'Year '.$word;
            $aliases[] = 'year '.$word;
            $aliases[] = 'year'.$word;
            $aliases[] = $title.' Year';
            $aliases[] = $word.' year';
        }

        return $aliases;
    }

    /**
     * @return array<string, int>
     */
    private static function yearWordMap(): array
    {
        return [
            'one' => 1,
            'two' => 2,
            'three' => 3,
            'four' => 4,
            'five' => 5,
            'six' => 6,
            'seven' => 7,
            'eight' => 8,
            'nine' => 9,
            'ten' => 10,
            'first' => 1,
            'second' => 2,
            'third' => 3,
            'fourth' => 4,
            'fifth' => 5,
        ];
    }

    private static function matchYearNamedLevel($query, int $year): ?AcademicLevel
    {
        $levels = $query->orderBy('sort_order')->orderBy('id')->get();

        foreach ($levels as $level) {
            foreach ([trim((string) ($level->name ?: '')), trim((string) ($level->code ?: ''))] as $label) {
                if ($label !== '' && self::yearFromLabel($label) === $year) {
                    return $level;
                }
            }
        }

        return null;
    }
}
