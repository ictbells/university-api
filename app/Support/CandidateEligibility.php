<?php

namespace App\Support;

use App\Models\AcademicTerm;
use App\Models\CandidateData;
use App\Models\Intake;
use Illuminate\Validation\ValidationException;

class CandidateEligibility
{
    public static function currentAcademicYear(): ?string
    {
        return AcademicTerm::query()
            ->where('is_current', true)
            ->value('session_label');
    }

    /**
     * @return list<string>
     */
    public static function openIntakeSessionLabels(): array
    {
        return Intake::query()
            ->with('term:id,session_label')
            ->get()
            ->filter(fn (Intake $intake) => $intake->isAcceptingApplications())
            ->map(fn (Intake $intake) => $intake->term?->session_label)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    public static function sessionLabelForIntake(?int $intakeId): ?string
    {
        if (! $intakeId) {
            return null;
        }

        $intake = Intake::query()->with('term:id,session_label')->find($intakeId);

        return $intake?->term?->session_label;
    }

    public static function enforcementEnabled(): bool
    {
        $sessions = self::openIntakeSessionLabels();
        if ($sessions !== []) {
            return CandidateData::query()->whereIn('academic_year', $sessions)->exists();
        }

        $year = self::currentAcademicYear();
        if (! $year) {
            return CandidateData::query()->exists();
        }

        return CandidateData::query()->where('academic_year', $year)->exists();
    }

    public static function findByJamb(string $jambRegistration, ?string $academicYear = null): ?CandidateData
    {
        $normalized = strtoupper(str_replace(' ', '', trim($jambRegistration)));
        if ($normalized === '') {
            return null;
        }

        $query = CandidateData::query()->where('rg_num', $normalized);

        if ($academicYear) {
            return $query->where('academic_year', $academicYear)->first();
        }

        $sessions = self::openIntakeSessionLabels();
        if ($sessions !== []) {
            $candidate = (clone $query)
                ->whereIn('academic_year', $sessions)
                ->latest('id')
                ->first();
            if ($candidate) {
                return $candidate;
            }
        }

        $current = self::currentAcademicYear();
        if ($current) {
            $candidate = (clone $query)->where('academic_year', $current)->first();
            if ($candidate) {
                return $candidate;
            }
        }

        return $query->latest('id')->first();
    }

    /**
     * @return array{first_name: string, last_name: string}
     */
    public static function splitCandidateName(?string $fullName): array
    {
        $fullName = trim((string) $fullName);
        if ($fullName === '') {
            return ['first_name' => '', 'last_name' => ''];
        }

        $parts = preg_split('/\s+/', $fullName) ?: [];
        if (count($parts) === 1) {
            return ['first_name' => $parts[0], 'last_name' => ''];
        }

        return [
            'first_name' => array_shift($parts),
            'last_name' => implode(' ', $parts),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function utmeQualificationPayload(CandidateData $candidate): array
    {
        return [
            'source' => 'candidate_upload',
            'aggregate' => $candidate->rg_aggr,
            'english_score' => $candidate->eng_score,
            'subjects' => array_values(array_filter([
                ['subject' => 'English Language', 'score' => $candidate->eng_score],
                $candidate->subject1 ? ['subject' => $candidate->subject1, 'score' => $candidate->rg_sub1scor] : null,
                $candidate->subject2 ? ['subject' => $candidate->subject2, 'score' => $candidate->rg_sub2scor] : null,
                $candidate->subject3 ? ['subject' => $candidate->subject3, 'score' => $candidate->rg_sub3scor] : null,
                $candidate->subj ? ['subject' => $candidate->subj, 'score' => null] : null,
            ])),
            'course_choice' => $candidate->co_name,
            'exam_year' => self::examYearFromAcademicYear($candidate->academic_year),
            'state' => $candidate->state_name,
            'lga' => $candidate->lga_name,
        ];
    }

    public static function examYearFromAcademicYear(?string $academicYear): ?string
    {
        if (! $academicYear) {
            return null;
        }
        if (preg_match('/(20\d{2}|19\d{2})/', $academicYear, $match)) {
            return $match[1];
        }

        return $academicYear;
    }

    public static function candidateListRequiredFor(Intake $intake): bool
    {
        if (! AdmissionEntryRules::requiresJambRegistration($intake->entry_mode)) {
            return false;
        }
        $year = $intake->term?->session_label ?: self::sessionLabelForIntake($intake->id);
        if (! $year) {
            return false;
        }

        return CandidateData::query()->where('academic_year', $year)->exists();
    }

    public static function assertQualifiedForIntake(Intake $intake, ?string $jambRegistration): void
    {
        if (! AdmissionEntryRules::requiresJambRegistration($intake->entry_mode)) {
            return;
        }

        $jamb = strtoupper(str_replace(' ', '', trim((string) $jambRegistration)));
        if ($jamb === '') {
            throw ValidationException::withMessages([
                'jamb_registration' => $intake->entry_mode === 'de'
                    ? 'JAMB Direct Entry number is required for this application session.'
                    : 'JAMB registration number is required for this application session.',
            ]);
        }

        $year = $intake->term?->session_label ?: self::sessionLabelForIntake($intake->id);
        if (self::candidateListRequiredFor($intake) && ! self::findByJamb($jamb, $year)) {
            throw ValidationException::withMessages([
                'jamb_registration' => 'This registration number is not on the candidate list for this application session.',
            ]);
        }
    }
}
