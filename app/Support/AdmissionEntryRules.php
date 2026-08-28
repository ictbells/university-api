<?php

namespace App\Support;

use App\Models\Application;

class AdmissionEntryRules
{
    /** @var list<string> */
    public const ENTRY_MODE_ORDER = ['utme', 'de', 'jupeb', 'transfer', 'pg'];

    /** @var list<string> */
    public const JAMB_ENTRY_MODES = ['utme', 'de'];

    /**
     * Required application upload checklist by entry mode.
     * Keys are document types stored on application_documents.doc_type.
     *
     * @return list<array{key: string, label: string, required: bool, description?: string}>
     */
    public static function requiredDocuments(string $entryMode, ?Application $application = null): array
    {
        if ($entryMode === 'jupeb') {
            return [
                [
                    'key' => 'passport',
                    'label' => 'Passport',
                    'required' => true,
                    'description' => 'Passport photograph (usually captured from NIN verification).',
                ],
                [
                    'key' => 'olevel_first_sitting',
                    'label' => "O'Level Result (1st sitting)",
                    'required' => true,
                    'description' => "Scan or clear photo of your first sitting O'Level result.",
                ],
                [
                    'key' => 'olevel_second_sitting',
                    'label' => "O'Level Result (2nd sitting)",
                    'required' => false,
                    'description' => 'Optional — upload if you have a second sitting.',
                ],
            ];
        }

        if ($entryMode === 'utme') {
            return [
                [
                    'key' => 'passport',
                    'label' => 'Passport',
                    'required' => true,
                    'description' => 'Passport photograph (usually captured from NIN verification).',
                ],
                [
                    'key' => 'birth_certificate',
                    'label' => 'Birth Certificate',
                    'required' => true,
                    'description' => 'Birth certificate or sworn age declaration.',
                ],
                [
                    'key' => 'jamb_result',
                    'label' => 'JAMB Result',
                    'required' => true,
                    'description' => 'UTME / JAMB result slip.',
                ],
                [
                    'key' => 'olevel_first_sitting',
                    'label' => "O'Level Result (1st sitting)",
                    'required' => true,
                    'description' => "Scan or clear photo of your first sitting O'Level result.",
                ],
                [
                    'key' => 'olevel_second_sitting',
                    'label' => "O'Level Result (2nd sitting)",
                    'required' => false,
                    'description' => 'Optional — upload if you have a second sitting.',
                ],
            ];
        }

        if ($entryMode === 'de') {
            return [
                [
                    'key' => 'passport',
                    'label' => 'Passport',
                    'required' => true,
                    'description' => 'Passport photograph (usually captured from NIN verification).',
                ],
                [
                    'key' => 'birth_certificate',
                    'label' => 'Birth Certificate',
                    'required' => true,
                    'description' => 'Birth certificate or sworn age declaration.',
                ],
                [
                    'key' => 'olevel_first_sitting',
                    'label' => "O'Level Result (1st sitting)",
                    'required' => true,
                    'description' => "Scan or clear photo of your first sitting O'Level result.",
                ],
                [
                    'key' => 'de_qualification',
                    'label' => 'Direct Entry qualification',
                    'required' => true,
                    'description' => 'A-Level, diploma, JUPEB, NCE, or equivalent certificate.',
                ],
                [
                    'key' => 'de_transcript',
                    'label' => 'Direct Entry transcript',
                    'required' => true,
                    'description' => 'Official transcript or statement of result for the qualifying award.',
                ],
                [
                    'key' => 'supporting',
                    'label' => 'Supporting document',
                    'required' => false,
                    'description' => 'Any additional supporting document.',
                ],
            ];
        }

        if ($entryMode === 'transfer') {
            return [
                [
                    'key' => 'passport',
                    'label' => 'Passport',
                    'required' => true,
                    'description' => 'Passport photograph (usually captured from NIN verification).',
                ],
                [
                    'key' => 'birth_certificate',
                    'label' => 'Birth Certificate',
                    'required' => true,
                    'description' => 'Birth certificate or sworn age declaration.',
                ],
                [
                    'key' => 'olevel_first_sitting',
                    'label' => "O'Level Result (1st sitting)",
                    'required' => true,
                    'description' => "Scan or clear photo of your first sitting O'Level result.",
                ],
                [
                    'key' => 'previous_transcript',
                    'label' => 'Previous institution transcript',
                    'required' => true,
                    'description' => 'Official transcript or result from the previous institution.',
                ],
                [
                    'key' => 'transfer_approval',
                    'label' => 'Transfer approval letter',
                    'required' => false,
                    'description' => 'Optional approval or release letter from the previous institution.',
                ],
                [
                    'key' => 'supporting',
                    'label' => 'Supporting document',
                    'required' => false,
                    'description' => 'Any additional supporting document.',
                ],
            ];
        }

        if ($entryMode === 'pg') {
            $nyscStatus = $application
                ? (ProgrammeEligibility::step($application, 'pg_background')['nysc_status'] ?? null)
                : null;
            $nyscRequired = $nyscStatus !== 'not_applicable';

            return [
                [
                    'key' => 'passport',
                    'label' => 'Passport',
                    'required' => true,
                    'description' => 'Passport photograph (usually captured from NIN verification).',
                ],
                [
                    'key' => 'degree_certificate',
                    'label' => 'Degree certificate',
                    'required' => true,
                    'description' => 'First degree or equivalent certificate.',
                ],
                [
                    'key' => 'academic_transcript',
                    'label' => 'Academic transcript',
                    'required' => true,
                    'description' => 'Official transcript of the qualifying degree.',
                ],
                [
                    'key' => 'nysc_certificate',
                    'label' => 'NYSC certificate or exemption',
                    'required' => $nyscRequired,
                    'description' => 'NYSC discharge or exemption certificate. Not required if NYSC does not apply.',
                ],
                [
                    'key' => 'statement_of_purpose',
                    'label' => 'Statement of purpose (optional file)',
                    'required' => false,
                    'description' => 'Optional extra copy of your statement of purpose.',
                ],
                [
                    'key' => 'olevel_first_sitting',
                    'label' => "O'Level Result (1st sitting)",
                    'required' => true,
                    'description' => "Scan or clear photo of your first sitting O'Level result.",
                ],
                [
                    'key' => 'olevel_second_sitting',
                    'label' => "O'Level Result (2nd sitting)",
                    'required' => false,
                    'description' => 'Optional — upload if you have a second sitting.',
                ],
                [
                    'key' => 'supporting',
                    'label' => 'Supporting document',
                    'required' => false,
                    'description' => 'Any additional supporting document.',
                ],
            ];
        }

        return [
            [
                'key' => 'passport',
                'label' => 'Passport',
                'required' => true,
                'description' => 'Passport photograph (usually captured from NIN verification).',
            ],
            [
                'key' => 'olevel_first_sitting',
                'label' => "O'Level Result (1st sitting)",
                'required' => true,
                'description' => "Scan or clear photo of your first sitting O'Level result.",
            ],
            [
                'key' => 'olevel_second_sitting',
                'label' => "O'Level Result (2nd sitting)",
                'required' => false,
                'description' => 'Optional — upload if you have a second sitting.',
            ],
            [
                'key' => 'supporting',
                'label' => 'Supporting document',
                'required' => false,
                'description' => 'Any additional supporting document.',
            ],
        ];
    }

    /**
     * @return list<string> Human-readable missing required document labels
     */
    public static function missingRequiredDocuments(Application $application): array
    {
        $application->loadMissing(['documents', 'steps']);
        $uploaded = $application->documents->pluck('doc_type')->unique()->all();
        $biodata = $application->steps->firstWhere('step_key', 'biodata')?->payload ?? [];
        $missing = [];

        foreach (self::requiredDocuments((string) $application->entry_mode, $application) as $doc) {
            if (! ($doc['required'] ?? false)) {
                continue;
            }
            if ($doc['key'] === 'passport') {
                if (in_array('passport', $uploaded, true) || filled($biodata['photo_path'] ?? null)) {
                    continue;
                }
                $missing[] = $doc['label'];

                continue;
            }
            if (! in_array($doc['key'], $uploaded, true)) {
                $missing[] = $doc['label'];
            }
        }

        return $missing;
    }

    public static function requiresJambRegistration(string $entryMode): bool
    {
        return in_array($entryMode, self::JAMB_ENTRY_MODES, true);
    }

    public static function entryModeRank(string $entryMode): int
    {
        $index = array_search($entryMode, self::ENTRY_MODE_ORDER, true);

        return $index === false ? PHP_INT_MAX : $index;
    }

    public static function allowsSecondProgramme(string $entryMode): bool
    {
        return $entryMode !== 'jupeb';
    }

    /**
     * @param  array<string, mixed>|null  $sitting
     */
    public static function sittingIsNabteb(?array $sitting): bool
    {
        return strtoupper(trim((string) ($sitting['exam_type'] ?? ''))) === 'NABTEB';
    }

    /**
     * @param  array<string, mixed>|null  $sitting
     */
    public static function sittingHasContent(?array $sitting): bool
    {
        if (! is_array($sitting)) {
            return false;
        }
        if (filled($sitting['exam_type'] ?? null)
            || filled($sitting['exam_center'] ?? null)
            || filled($sitting['exam_year'] ?? null)
            || filled($sitting['exam_number'] ?? null)) {
            return true;
        }

        return collect($sitting['results'] ?? [])->contains(
            fn ($row) => filled($row['subject_id'] ?? null) || filled($row['grade'] ?? null)
        );
    }

    /**
     * NABTEB is a single-sitting exam and cannot be combined with another sitting.
     *
     * @param  array<string, mixed>|null  $first
     * @param  array<string, mixed>|null  $second
     */
    public static function nabtebCombinedWithSecondSitting(?array $first, ?array $second): bool
    {
        if (! self::sittingHasContent($first) || ! self::sittingHasContent($second)) {
            return false;
        }

        return self::sittingIsNabteb($first) || self::sittingIsNabteb($second);
    }
}
