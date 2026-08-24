<?php

namespace App\Support;

use App\Models\Program;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ApplicationFormSteps
{
    public const CLASSIFICATIONS = [
        'first', 'second_upper', 'second_lower', 'third', 'pass', 'distinction', 'merit', 'other',
    ];

    public const NYSC = ['completed', 'exempted', 'not_applicable'];

    public const AWARD_LEVELS = ['bachelor', 'pgd', 'masters', 'other'];

    public const DE_QUALIFICATION_TYPES = [
        'nd', 'hnd', 'nce', 'ijmb', 'a_level', 'first_degree', 'other',
    ];

    public const DE_CLASSIFICATIONS = [
        'first', 'second_upper', 'second_lower', 'third', 'pass',
        'distinction', 'upper_credit', 'lower_credit', 'merit', 'other',
    ];

    public const DE_ENTRY_LEVELS = ['200', '300'];

    public const TRANSFER_ENTRY_LEVELS = ['200', '300', '400'];

    public const CREDIT_DECISIONS = ['accept', 'accept_with_conditions', 'reject'];

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function validateUtme(Request $request, array $payload, bool $required = true): array
    {
        $utme = is_array($payload['utme'] ?? null) ? $payload['utme'] : null;
        if (! $required && self::utmeIsEmpty($utme)) {
            $payload['utme'] = null;

            return $payload;
        }

        $request->merge(['payload' => $payload]);
        $payload = $request->validate([
            'payload.utme' => ($required ? 'required' : 'nullable').'|array',
            'payload.utme.aggregate' => ($required ? 'required' : 'nullable').'|string|max:20',
            'payload.utme.course_choice' => ($required ? 'required' : 'nullable').'|string|max:190',
            'payload.utme.exam_year' => ($required ? 'required' : 'nullable').'|string|max:10',
            'payload.utme.english_score' => 'nullable|numeric|min:0|max:400',
            'payload.utme.subjects' => ($required ? 'required' : 'nullable').'|array'.($required ? '|min:4' : ''),
            'payload.utme.subjects.*.subject' => ($required ? 'required' : 'nullable').'|string|max:120',
            'payload.utme.subjects.*.score' => ($required ? 'required' : 'nullable').'|numeric|min:0|max:400',
            'payload.utme.institution_choices' => ($required ? 'required' : 'nullable').'|array|max:4',
            'payload.utme.institution_choices.*.choice_order' => 'nullable|integer|min:1|max:4',
            'payload.utme.institution_choices.*.institution_name' => 'nullable|string|max:190',
            'payload.utme.institution_choices.*.programme_name' => 'nullable|string|max:190',
        ])['payload'] + $payload;

        if ($required) {
            $subjects = collect($payload['utme']['subjects'] ?? [])
                ->filter(fn ($row) => filled($row['subject'] ?? null) && filled($row['score'] ?? null));
            if ($subjects->count() < 4) {
                throw ValidationException::withMessages([
                    'payload.utme.subjects' => ['Enter all four UTME subject scores.'],
                ]);
            }
            $choices = collect($payload['utme']['institution_choices'] ?? [])
                ->filter(fn ($row) => filled($row['institution_name'] ?? null));
            if ($choices->isEmpty()) {
                throw ValidationException::withMessages([
                    'payload.utme.institution_choices' => ['Provide at least your first JAMB institution choice.'],
                ]);
            }
        }

        if (is_array($payload['utme'] ?? null)) {
            $payload['utme']['institution_choices'] = collect($payload['utme']['institution_choices'] ?? [])
                ->values()
                ->map(function ($row, $index) {
                    return [
                        'choice_order' => (int) ($row['choice_order'] ?? ($index + 1)),
                        'institution_name' => $row['institution_name'] ?? '',
                        'programme_name' => $row['programme_name'] ?? '',
                    ];
                })
                ->all();
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function validateDirectEntry(Request $request, array $payload): array
    {
        $request->merge(['payload' => $payload]);
        $payload = $request->validate([
            'payload.jamb_de_number' => 'nullable|string|max:20',
            'payload.previous_institution' => 'required|string|max:190',
            'payload.qualification_type' => 'required|string|in:'.implode(',', self::DE_QUALIFICATION_TYPES),
            'payload.qualification_title' => 'required|string|max:150',
            'payload.qualification_class' => 'required|string|in:'.implode(',', self::DE_CLASSIFICATIONS),
            'payload.qualification_year' => 'required|string|max:10',
            'payload.programme' => 'required|string|max:190',
            'payload.requested_entry_level' => 'required|string|in:'.implode(',', self::DE_ENTRY_LEVELS),
        ])['payload'] + $payload;

        if (! empty($payload['jamb_de_number'])) {
            $payload['jamb_de_number'] = strtoupper(str_replace(' ', '', (string) $payload['jamb_de_number']));
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function validateTransferBackground(Request $request, array $payload): array
    {
        $request->merge(['payload' => $payload]);

        return $request->validate([
            'payload.previous_university' => 'required|string|max:190',
            'payload.previous_programme' => 'required|string|max:190',
            'payload.previous_student_id' => 'required|string|max:80',
            'payload.credits_earned' => 'required|numeric|min:0|max:400',
            'payload.cgpa' => 'required|numeric|min:0|max:5',
            'payload.reason_for_transfer' => 'required|string|max:2000',
            'payload.requested_entry_level' => 'required|string|in:'.implode(',', self::TRANSFER_ENTRY_LEVELS),
            'payload.has_transfer_approval' => 'required|boolean',
            'payload.approval_reference' => 'nullable|string|max:120',
        ])['payload'] + $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function validateCreditAssessment(Request $request, array $payload): array
    {
        $request->merge(['payload' => $payload]);
        $payload = $request->validate([
            'payload.decision' => 'required|string|in:'.implode(',', self::CREDIT_DECISIONS),
            'payload.approved_entry_level' => 'nullable|string|in:'.implode(',', self::TRANSFER_ENTRY_LEVELS),
            'payload.credits_accepted' => 'nullable|numeric|min:0|max:400',
            'payload.credits_waived' => 'nullable|numeric|min:0|max:400',
            'payload.assessor_notes' => 'nullable|string|max:4000',
            'payload.course_mappings' => 'nullable|array',
            'payload.course_mappings.*.previous_course' => 'nullable|string|max:190',
            'payload.course_mappings.*.equivalent_course' => 'nullable|string|max:190',
            'payload.course_mappings.*.credits' => 'nullable|numeric|min:0|max:30',
            'payload.course_mappings.*.decision' => 'nullable|string|in:accept,reject',
        ])['payload'] + $payload;

        $decision = $payload['decision'] ?? null;
        if (in_array($decision, ['accept', 'accept_with_conditions'], true) && blank($payload['approved_entry_level'] ?? null)) {
            throw ValidationException::withMessages([
                'payload.approved_entry_level' => ['Set the approved entry level before accepting a transfer.'],
            ]);
        }

        $payload['course_mappings'] = collect($payload['course_mappings'] ?? [])
            ->filter(fn ($row) => filled($row['previous_course'] ?? null) || filled($row['equivalent_course'] ?? null))
            ->values()
            ->all();
        $payload['assessed_at'] = now()->toIso8601String();

        return $payload;
    }

    /**
     * @param  array<string, mixed>|null  $utme
     */
    public static function utmeIsEmpty(?array $utme): bool
    {
        if (! $utme) {
            return true;
        }
        $subjects = collect($utme['subjects'] ?? [])->filter(fn ($row) => filled($row['subject'] ?? null) || filled($row['score'] ?? null));
        $choices = collect($utme['institution_choices'] ?? [])->filter(fn ($row) => filled($row['institution_name'] ?? null));

        return blank($utme['aggregate'] ?? null)
            && blank($utme['course_choice'] ?? null)
            && blank($utme['exam_year'] ?? null)
            && $subjects->isEmpty()
            && $choices->isEmpty();
    }

    public static function assessmentAcceptsTransfer(?array $payload): bool
    {
        $decision = $payload['decision'] ?? null;

        return in_array($decision, ['accept', 'accept_with_conditions'], true)
            && filled($payload['approved_entry_level'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function validatePgBackground(Request $request, array $payload): array
    {
        $request->merge(['payload' => $payload]);
        $payload = $request->validate([
            'payload.prior_degrees' => 'required|array|min:1',
            'payload.prior_degrees.*.degree_title' => 'required|string|max:150',
            'payload.prior_degrees.*.institution' => 'required|string|max:190',
            'payload.prior_degrees.*.field_of_study' => 'nullable|string|max:150',
            'payload.prior_degrees.*.class' => 'required|string|in:'.implode(',', self::CLASSIFICATIONS),
            'payload.prior_degrees.*.award_level' => 'nullable|string|in:'.implode(',', self::AWARD_LEVELS),
            'payload.prior_degrees.*.year_awarded' => 'required|string|max:10',
            'payload.prior_degrees.*.country' => 'nullable|string|max:80',
            'payload.nysc_status' => 'required|string|in:'.implode(',', self::NYSC),
            'payload.nysc_number' => 'nullable|string|max:80',
            'payload.nysc_year' => 'nullable|string|max:10',
            'payload.nysc_exemption_reason' => 'nullable|string|max:500',
            'payload.professional_qualifications' => 'nullable|array',
            'payload.professional_qualifications.*.body' => 'nullable|string|max:150',
            'payload.professional_qualifications.*.qualification' => 'nullable|string|max:150',
            'payload.professional_qualifications.*.year' => 'nullable|string|max:10',
            'payload.professional_qualifications.*.membership_no' => 'nullable|string|max:80',
            'payload.other_qualifications' => 'nullable|string|max:2000',
        ])['payload'] + $payload;

        if (in_array($payload['nysc_status'], ['completed', 'exempted'], true)
            && blank($payload['nysc_number'] ?? null)
            && blank($payload['nysc_exemption_reason'] ?? null)) {
            throw ValidationException::withMessages([
                'payload.nysc_number' => ['Provide the NYSC number or exemption details.'],
            ]);
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function validatePgResearch(Request $request, array $payload, ?Program $program): array
    {
        $research = (bool) ($program?->is_research_degree);
        $request->merge(['payload' => $payload]);
        $payload = $request->validate([
            'payload.research_interest' => ($research ? 'required' : 'nullable').'|string|max:2000',
            'payload.proposed_area' => ($research ? 'required' : 'nullable').'|string|max:500',
            'payload.statement_of_purpose' => 'required|string|max:8000',
            'payload.publications' => 'nullable|array',
            'payload.publications.*.title' => 'nullable|string|max:250',
            'payload.publications.*.year' => 'nullable|string|max:10',
            'payload.publications.*.venue' => 'nullable|string|max:190',
            'payload.supervisor_preferences' => ($research ? 'required' : 'nullable').'|array',
            'payload.supervisor_preferences.*' => 'nullable|integer|exists:staff,id',
        ])['payload'] + $payload;

        $prefs = collect($payload['supervisor_preferences'] ?? [])->filter()->values()->all();
        $payload['supervisor_preferences'] = $prefs;
        if ($research && $prefs === []) {
            throw ValidationException::withMessages([
                'payload.supervisor_preferences' => ['Select at least one preferred supervisor.'],
            ]);
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function validatePgReferees(Request $request, array $payload): array
    {
        $request->merge(['payload' => $payload]);
        $payload = $request->validate([
            'payload.referees' => 'required|array|min:2|max:3',
            'payload.referees.*.name' => 'required|string|max:120',
            'payload.referees.*.email' => 'required|email|max:190',
            'payload.referees.*.institution' => 'required|string|max:190',
            'payload.referees.*.position' => 'required|string|max:120',
            'payload.referees.*.phone' => 'nullable|string|max:30',
        ])['payload'] + $payload;

        return $payload;
    }
}
