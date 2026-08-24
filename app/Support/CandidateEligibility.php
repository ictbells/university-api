<?php

namespace App\Support;

use App\Models\AcademicTerm;
use App\Models\CandidateData;
use App\Models\Intake;

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
            'state' => $candidate->state_name,
            'lga' => $candidate->lga_name,
        ];
    }
}
