<?php

namespace App\Support;

use App\Models\Program;
use App\Models\Student;
use Illuminate\Validation\ValidationException;

class StudyLevel
{
    public const UNDERGRADUATE = 'undergraduate';

    public const JUPEB = 'jupeb';

    public const POSTGRADUATE = 'postgraduate';

    public const ALL = [
        self::UNDERGRADUATE,
        self::JUPEB,
        self::POSTGRADUATE,
    ];

    public const UNDERGRADUATE_ENTRY_MODES = ['utme', 'de', 'transfer'];

    public static function rule(): string
    {
        return 'in:'.implode(',', self::ALL);
    }

    public static function fromEntryMode(?string $mode): string
    {
        return match (strtolower(trim((string) $mode))) {
            'pg' => self::POSTGRADUATE,
            'jupeb' => self::JUPEB,
            default => self::UNDERGRADUATE,
        };
    }

    /**
     * @param  list<string>  $modes
     */
    public static function fromEntryModes(array $modes): ?string
    {
        $tracks = self::tracksInModes($modes);

        return count($tracks) === 1 ? $tracks[0] : null;
    }

    public static function ofProgram(Program $program): string
    {
        $fromModes = self::fromEntryModes($program->entryModeList());
        if ($fromModes) {
            return $fromModes;
        }

        $level = strtolower((string) $program->study_level);

        return in_array($level, self::ALL, true) ? $level : self::UNDERGRADUATE;
    }

    public static function ofStudent(Student $student): string
    {
        $stored = strtolower((string) $student->study_level);
        $stored = in_array($stored, self::ALL, true) ? $stored : null;

        $fromApplication = $student->application?->entry_mode
            ? self::fromEntryMode($student->application->entry_mode)
            : null;

        $fromProgram = $student->program ? self::ofProgram($student->program) : null;

        $inferredJupeb = self::looksLikeJupebProgram($student->program)
            || self::looksLikeJupebMatric($student->matric_number)
            || self::looksLikeJupebMatric($student->student_number);

        $candidates = array_values(array_filter([
            $fromApplication,
            $fromProgram,
            $inferredJupeb ? self::JUPEB : null,
            $stored,
        ]));

        // A JUPEB/PG programme, application, or J/ matric beats a default
        // undergraduate stamp left on students.study_level.
        if (in_array(self::JUPEB, $candidates, true)) {
            return self::JUPEB;
        }
        if (in_array(self::POSTGRADUATE, $candidates, true)) {
            return self::POSTGRADUATE;
        }

        return $stored ?? $fromApplication ?? $fromProgram ?? self::UNDERGRADUATE;
    }

    public static function looksLikeJupebProgram(?Program $program): bool
    {
        if (! $program) {
            return false;
        }

        $haystack = strtoupper(trim(implode(' ', array_filter([
            $program->name,
            $program->code,
            $program->award_type,
        ]))));

        return $haystack !== '' && str_contains($haystack, 'JUPEB');
    }

    public static function looksLikeJupebMatric(?string $matric): bool
    {
        $value = strtoupper(str_replace(' ', '', (string) $matric));

        return $value !== '' && str_starts_with($value, 'J/');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function applyToProgramPayload(array $data, ?Program $existing = null): array
    {
        $modes = $data['entry_modes'] ?? $existing?->entryModeList() ?? [];
        if (! is_array($modes)) {
            $modes = [];
        }
        $modes = array_values(array_filter(array_map(
            fn ($mode) => strtolower(trim((string) $mode)),
            $modes,
        )));
        $studyLevel = strtolower((string) ($data['study_level'] ?? $existing?->study_level ?? ''));
        $studyLevel = in_array($studyLevel, self::ALL, true) ? $studyLevel : null;

        self::assertCompatible($studyLevel, $modes);

        $data['study_level'] = self::fromEntryModes($modes) ?? $studyLevel ?? self::UNDERGRADUATE;

        return $data;
    }

    /**
     * @param  list<string>  $modes
     */
    public static function assertCompatible(?string $studyLevel, array $modes): void
    {
        $tracks = self::tracksInModes($modes);
        if (count($tracks) > 1) {
            throw ValidationException::withMessages([
                'entry_modes' => 'JUPEB cannot share a programme with undergraduate or postgraduate. Create a separate JUPEB programme with its own levels and courses.',
            ]);
        }

        $fromModes = $tracks[0] ?? null;
        if ($studyLevel && $fromModes && $studyLevel !== $fromModes) {
            throw ValidationException::withMessages([
                'study_level' => 'Degree type must match the admission category. JUPEB programmes use the JUPEB track, not undergraduate.',
            ]);
        }
    }

    /**
     * @param  list<string>  $modes
     * @return list<string>
     */
    private static function tracksInModes(array $modes): array
    {
        $tracks = [];
        if (array_intersect($modes, self::UNDERGRADUATE_ENTRY_MODES) !== []) {
            $tracks[] = self::UNDERGRADUATE;
        }
        if (in_array('jupeb', $modes, true)) {
            $tracks[] = self::JUPEB;
        }
        if (in_array('pg', $modes, true)) {
            $tracks[] = self::POSTGRADUATE;
        }

        return $tracks;
    }
}
