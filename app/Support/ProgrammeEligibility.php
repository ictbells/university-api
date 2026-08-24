<?php

namespace App\Support;

use App\Models\Application;
use App\Models\Program;

class ProgrammeEligibility
{
    /**
     * @return array{meets: bool, failed: list<array{rule: string, message: string}>, requirements: array<string, mixed>}
     */
    public static function forApplication(Application $application, ?Program $program = null): array
    {
        $application->loadMissing(['steps', 'documents', 'refereeInvites']);
        $program ??= $application->program
            ?? Program::query()->find(self::firstChoiceId($application));

        if (! $program) {
            return ['meets' => true, 'failed' => [], 'requirements' => []];
        }

        $result = self::evaluate($program, $application);
        $requiredLetters = max(2, (int) ($result['requirements']['min_referees'] ?? 2));
        $receivedLetters = $application->refereeInvites?->where('status', 'submitted')->count() ?? 0;
        if ($application->entry_mode === 'pg' && $receivedLetters < $requiredLetters) {
            $result['failed'][] = [
                'rule' => 'recommendation_letters',
                'message' => 'Waiting for '.$receivedLetters.' of '.$requiredLetters.' recommendation letters.',
            ];
            $result['meets'] = false;
        }

        return $result;
    }

    /**
     * @return array{meets: bool, failed: list<array{rule: string, message: string}>, requirements: array<string, mixed>}
     */
    public static function evaluate(Program $program, Application $application): array
    {
        $rules = is_array($program->eligibility) ? $program->eligibility : [];
        $failed = [];
        $background = self::step($application, 'pg_background');
        $degrees = collect($background['prior_degrees'] ?? [])->filter(fn ($row) => filled($row['degree_title'] ?? null));
        $highestClass = $degrees
            ->map(fn ($row) => DegreeClassification::rank($row['class'] ?? null))
            ->max() ?: 0;
        $highestClassKey = $degrees
            ->sortByDesc(fn ($row) => DegreeClassification::rank($row['class'] ?? null))
            ->value('class');
        $hasMasters = $degrees->contains(fn ($row) => ($row['award_level'] ?? '') === 'masters');
        $nysc = $background['nysc_status'] ?? null;
        $referees = collect(self::step($application, 'pg_referees')['referees'] ?? [])
            ->filter(fn ($row) => filled($row['email'] ?? null) || filled($row['name'] ?? null));

        $minClass = $rules['min_classification'] ?? null;
        if (filled($minClass) && $highestClass < DegreeClassification::rank($minClass)) {
            $failed[] = [
                'rule' => 'min_classification',
                'message' => 'This programme requires at least '.DegreeClassification::label($minClass).'. Your highest class is '.DegreeClassification::label($highestClassKey).'.',
            ];
        }

        if (! empty($rules['nysc_required']) && $nysc === 'not_applicable') {
            $failed[] = [
                'rule' => 'nysc_required',
                'message' => 'This programme requires NYSC discharge or exemption. You selected not applicable.',
            ];
        }

        $minAward = $rules['min_prior_award'] ?? null;
        if ($minAward === 'masters' && ! $hasMasters) {
            $failed[] = [
                'rule' => 'min_prior_award',
                'message' => 'This programme requires a Master’s degree. Your listed awards are Bachelor’s only.',
            ];
        }

        $minReferees = isset($rules['min_referees']) ? (int) $rules['min_referees'] : null;
        if ($minReferees !== null && $minReferees > 0 && $referees->count() < $minReferees) {
            $failed[] = [
                'rule' => 'min_referees',
                'message' => 'This programme requires at least '.$minReferees.' referees. You have '.$referees->count().'.',
            ];
        }

        return [
            'meets' => $failed === [],
            'failed' => $failed,
            'requirements' => [
                'min_classification' => $minClass,
                'min_classification_label' => $minClass ? DegreeClassification::label($minClass) : null,
                'nysc_required' => (bool) ($rules['nysc_required'] ?? false),
                'min_referees' => $minReferees,
                'min_prior_award' => $minAward,
                'qualifying_note' => $rules['qualifying_note'] ?? null,
                'notes' => $rules['notes'] ?? null,
            ],
        ];
    }

    public static function firstChoiceId(Application $application): ?int
    {
        $payload = self::step($application, 'programme_selection');
        $id = $payload['first_choice_program_id'] ?? $payload['program_id'] ?? $application->program_id;

        return $id ? (int) $id : null;
    }

    /**
     * @return array<string, mixed>
     */
    public static function step(Application $application, string $key): array
    {
        $application->loadMissing('steps');
        $payload = $application->steps->firstWhere('step_key', $key)?->payload;

        return is_array($payload) ? $payload : [];
    }
}
