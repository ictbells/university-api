<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Student;
use App\Models\User;

class ReturningApplicantPrefill
{
    public const COPY_STEPS = [
        'personal_details',
        'health_information',
        'next_of_kin',
        'sponsor',
        'application_form',
        'academic_qualifications',
    ];

    public function apply(User $user, Application $application): void
    {
        $user->loadMissing(['student.medicalProfile']);
        $source = Application::query()
            ->where('user_id', $user->id)
            ->where('id', '!=', $application->id)
            ->with('steps')
            ->latest('id')
            ->first();

        if ($source) {
            foreach (self::COPY_STEPS as $key) {
                $from = $source->steps->firstWhere('step_key', $key);
                $payload = is_array($from?->payload) ? $from->payload : [];
                if ($payload === []) {
                    continue;
                }
                $step = $application->steps()->firstOrNew(['step_key' => $key]);
                $current = is_array($step->payload) ? $step->payload : [];
                $step->payload = $current === [] ? $payload : array_merge($payload, $current);
                $step->status = 'saved';
                $step->save();
            }
        }

        $this->overlayStudent($user->student, $application);
    }

    private function overlayStudent(?Student $student, Application $application): void
    {
        if (! $student) {
            return;
        }

        $this->fillStep($application, 'biodata', [
            'nin' => $student->nin,
            'first_name' => $student->first_name,
            'middle_name' => $student->middle_name,
            'last_name' => $student->last_name,
            'date_of_birth' => $student->date_of_birth?->format('Y-m-d'),
            'gender' => $student->gender,
            'photo_path' => $student->photo_path,
            'nin_locked' => true,
        ], true);

        $this->fillStep($application, 'personal_details', [
            'marital_status' => $student->marital_status,
            'religion' => $student->religion,
            'country' => $student->country,
            'state' => $student->state,
            'lga' => $student->lga,
        ]);

        $medical = $student->medicalProfile;
        $this->fillStep($application, 'health_information', [
            'blood_group' => $medical?->blood_type,
            'genotype' => $medical?->genotype,
            'has_medical_condition' => $medical?->has_medical_condition,
            'medical_condition_details' => $medical?->conditions,
        ]);

        $this->fillStep($application, 'next_of_kin', [
            'next_of_kin' => $student->next_of_kin,
            'next_of_kin_relationship' => $student->next_of_kin_relationship,
            'next_of_kin_phone' => $student->next_of_kin_phone,
            'next_of_kin_email' => $student->next_of_kin_email,
            'next_of_kin_address' => $student->next_of_kin_address,
        ]);

        $this->fillStep($application, 'sponsor', [
            'sponsor_name' => $student->sponsor_name,
            'sponsor_relationship' => $student->sponsor_relationship,
            'sponsor_phone' => $student->sponsor_phone,
            'sponsor_email' => $student->sponsor_email,
            'sponsor_address' => $student->sponsor_address,
        ]);

        $this->fillStep($application, 'application_form', [
            'phone' => $student->phone,
            'alternate_phone' => $student->alternate_phone,
            'address' => $student->address,
        ]);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function fillStep(Application $application, string $stepKey, array $values, bool $forceIdentityLock = false): void
    {
        $values = array_filter($values, fn ($value) => $value !== null && $value !== '');
        if ($values === [] && ! $forceIdentityLock) {
            return;
        }

        $step = $application->steps()->firstOrNew(['step_key' => $stepKey]);
        $payload = is_array($step->payload) ? $step->payload : [];
        foreach ($values as $key => $value) {
            if (blank($payload[$key] ?? null)) {
                $payload[$key] = $value;
            }
        }
        if ($forceIdentityLock) {
            $payload['nin_locked'] = true;
        }
        if ($payload === (is_array($step->payload) ? $step->payload : [])) {
            return;
        }
        $step->payload = $payload;
        if ($step->status === 'pending' || ! $step->exists) {
            $step->status = 'saved';
        }
        $step->save();
    }
}
