<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Program;
use App\Models\Student;
use App\Models\StudentProgrammeChange;
use App\Models\User;
use App\Support\AdmissionEntryRules;
use App\Support\ApplicationFormSteps;
use App\Support\CandidateEligibility;
use App\Support\PhoneNumber;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApplicationStaffUpdateService
{
    public function __construct(private AuditWriter $audit) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Application $application, array $data): Application
    {
        $application->load(['user', 'steps', 'student', 'program']);
        $before = $application->toArray();
        $user = $application->user;
        abort_unless($user, 422, 'This application has no applicant account.');

        $student = $this->resolveStudent($application);
        $originalProgramId = $application->program_id
            ? (int) $application->program_id
            : ($student?->program_id ? (int) $student->program_id : null);
        $nextProgramId = isset($data['first_choice_program_id'])
            ? (int) $data['first_choice_program_id']
            : $originalProgramId;

        $jamb = $this->normalizeJamb($data['jamb_registration'] ?? $application->jamb_registration);
        if (AdmissionEntryRules::requiresJambRegistration((string) $application->entry_mode) && ! $jamb) {
            throw ValidationException::withMessages([
                'jamb_registration' => 'JAMB registration is required for this admission category.',
            ]);
        }

        $this->assertUniqueEmail($user, (string) $data['email']);
        if ($jamb) {
            $this->assertUniqueJamb($user, $application, $jamb);
        }

        if (($application->entry_mode ?? '') === 'utme' && array_key_exists('utme', $data)) {
            $nested = Request::create('/', 'POST', ['payload' => ['utme' => $data['utme']]]);
            $validated = ApplicationFormSteps::validateUtme($nested, ['utme' => $data['utme']], false);
            $data['utme'] = $validated['utme'] ?? null;
        }

        if (($application->entry_mode ?? '') === 'transfer' && is_array($data['credit_assessment'] ?? null)
            && filled($data['credit_assessment']['decision'] ?? null)) {
            $nested = Request::create('/', 'POST', ['payload' => $data['credit_assessment']]);
            $data['credit_assessment'] = ApplicationFormSteps::validateCreditAssessment($nested, $data['credit_assessment']);
        }

        $jambStatus = $jamb ? $this->jambStatusFor($jamb) : null;
        $levelNote = null;

        DB::transaction(function () use (
            $application,
            $user,
            $student,
            $data,
            $jamb,
            $jambStatus,
            $originalProgramId,
            $nextProgramId,
            &$levelNote,
        ) {
            $this->validateProgrammeChoices($application, $data);

            if ($student && $nextProgramId && $originalProgramId && $nextProgramId !== $originalProgramId) {
                $fromProgramId = (int) $student->program_id ?: $originalProgramId;
                $levelNote = $this->applyProgrammeChange($student, $fromProgramId, $nextProgramId, $application->id);
            } elseif ($student && $nextProgramId && (int) $student->program_id !== $nextProgramId) {
                $student->update(['program_id' => $nextProgramId]);
            }

            $user->update([
                'email' => $data['email'],
                'jamb_registration' => $jamb,
                ...$this->alternatePhoneAttributes($data),
            ]);

            $application->update([
                'jamb_registration' => $jamb,
                'jamb_status' => $jambStatus,
                'program_id' => $nextProgramId,
            ]);

            $this->writeSteps($application, $data, $jamb);
            $this->syncStudentProfile($student, $data, $nextProgramId);
        });

        $this->audit->record(
            'application.staff_updated',
            'Staff updated application file'.($levelNote ? ' ('.$levelNote.')' : ''),
            'admissions',
            'application',
            $application->id,
            $before,
            $application->fresh(),
        );

        return $this->freshFile($application);
    }

    public function refreshJambStatus(Application $application): Application
    {
        $jamb = $this->normalizeJamb($application->jamb_registration ?: $application->user?->jamb_registration);
        if (! $jamb) {
            if ($application->jamb_status !== null) {
                $application->update(['jamb_status' => null]);
            }

            return $application;
        }

        $status = $this->jambStatusFor($jamb);
        if ($application->jamb_status !== $status) {
            $application->update(['jamb_status' => $status]);
        }

        return $application;
    }

    public function freshFile(Application $application): Application
    {
        $application = $application->fresh()->load([
            'user',
            'program.department.faculty',
            'intake.term',
            'steps',
            'documents',
            'reviews.reviewer',
            'latestReview',
            'applicationFeeInvoice',
            'acceptanceFeeInvoice',
            'student',
        ]);
        $student = $this->resolveStudent($application);
        if ($student && ! $application->relationLoaded('student')) {
            $application->setRelation('student', $student);
        } elseif ($student) {
            $application->setRelation('student', $student);
        }

        return $application;
    }

    private function resolveStudent(Application $application): ?Student
    {
        $application->loadMissing(['student', 'user.student']);

        return $application->student
            ?? Student::query()->where('application_id', $application->id)->first()
            ?? $application->user?->student;
    }

    private function normalizeJamb(mixed $value): ?string
    {
        $jamb = strtoupper(str_replace(' ', '', trim((string) $value)));

        return $jamb === '' ? null : $jamb;
    }

    private function jambStatusFor(string $jamb): string
    {
        return CandidateEligibility::findByJamb($jamb) ? 'validated' : 'pending';
    }

    private function assertUniqueEmail(User $user, string $email): void
    {
        $taken = User::query()
            ->where('email', $email)
            ->where('id', '!=', $user->id)
            ->exists();
        if ($taken) {
            throw ValidationException::withMessages([
                'email' => 'This email is already in use by another account.',
            ]);
        }
    }

    private function assertUniqueJamb(User $user, Application $application, string $jamb): void
    {
        $userTaken = User::query()
            ->where('jamb_registration', $jamb)
            ->where('id', '!=', $user->id)
            ->exists();
        $appTaken = Application::query()
            ->where('jamb_registration', $jamb)
            ->where('id', '!=', $application->id)
            ->exists();
        if ($userTaken || $appTaken) {
            throw ValidationException::withMessages([
                'jamb_registration' => 'This JAMB number is already assigned to another applicant.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function validateProgrammeChoices(Application $application, array $data): void
    {
        $firstId = isset($data['first_choice_program_id']) ? (int) $data['first_choice_program_id'] : null;
        $secondId = isset($data['second_choice_program_id']) ? (int) $data['second_choice_program_id'] : null;
        if (! $firstId) {
            throw ValidationException::withMessages([
                'first_choice_program_id' => 'Select a first-choice programme.',
            ]);
        }
        if (! AdmissionEntryRules::allowsSecondProgramme((string) $application->entry_mode) && $secondId) {
            throw ValidationException::withMessages([
                'second_choice_program_id' => 'JUPEB applicants may select only one programme.',
            ]);
        }
        if ($secondId && $secondId === $firstId) {
            throw ValidationException::withMessages([
                'second_choice_program_id' => 'Second choice must differ from first choice.',
            ]);
        }

        foreach (['first_choice_program_id' => $firstId, 'second_choice_program_id' => $secondId] as $field => $id) {
            if (! $id) {
                continue;
            }
            $program = Program::query()->with('department.faculty')->find($id);
            abort_unless(
                $program && $program->isOffered() && $program->acceptsEntryMode($application->entry_mode),
                422,
                'The selected programme is not available for this admission category.',
            );
            if ($application->entry_mode === 'jupeb' && ! $program->isOfferedAtJupebCentre()) {
                abort(422, 'JUPEB applicants can only choose a programme offered at a JUPEB centre.');
            }
        }
    }

    private function applyProgrammeChange(Student $student, int $fromProgramId, int $toProgramId, ?int $applicationId = null): string
    {
        $stored = (int) $student->current_level;
        $band = $stored >= 100 ? $stored : $stored * 100;
        if ($band < 100 || $band > 300) {
            throw ValidationException::withMessages([
                'first_choice_program_id' => 'Change of programme is only allowed for 100L to 300L students.',
            ]);
        }

        $sameCollege = $this->programmesShareCollege($fromProgramId, $toProgramId);
        $nextLevel = ($sameCollege || $band === 100)
            ? $stored
            : ($stored >= 100 ? $stored - 100 : max(1, $stored - 1));

        $student->update([
            'program_id' => $toProgramId,
            'current_level' => $nextLevel,
        ]);

        StudentProgrammeChange::query()->create([
            'student_id' => $student->id,
            'from_program_id' => $fromProgramId,
            'to_program_id' => $toProgramId,
            'from_level' => $stored,
            'to_level' => $nextLevel,
            'same_college' => $sameCollege,
            'kind' => StudentProgrammeChange::KIND_CHANGE_OF_PROGRAMME,
            'application_id' => $applicationId,
            'created_by' => Auth::id(),
        ]);

        if ($sameCollege) {
            return 'programme changed within the same college; level stays '.$stored;
        }

        return 'programme changed; level '.$stored.' to '.$nextLevel;
    }

    private function programmesShareCollege(int $fromProgramId, int $toProgramId): bool
    {
        $from = Program::query()->with('department')->find($fromProgramId);
        $to = Program::query()->with('department')->find($toProgramId);
        $fromFaculty = (int) ($from?->department?->faculty_id ?? 0);
        $toFaculty = (int) ($to?->department?->faculty_id ?? 0);

        return $fromFaculty > 0 && $fromFaculty === $toFaculty;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function writeSteps(Application $application, array $data, ?string $jamb): void
    {
        $this->mergeStep($application, 'biodata', [
            'phone' => $application->user?->phone,
        ], ['nin', 'photo_path', 'nin_locked', 'first_name', 'middle_name', 'last_name', 'date_of_birth', 'gender']);

        $this->mergeStep($application, 'personal_details', [
            'marital_status' => $data['marital_status'] ?? null,
            'religion' => $data['religion'] ?? null,
            'country' => $data['country'] ?? null,
            'state' => $data['state'] ?? null,
            'state_id' => $data['state_id'] ?? null,
            'lga' => $data['lga'] ?? null,
            'lga_id' => $data['lga_id'] ?? null,
        ], ['first_name', 'middle_name', 'last_name', 'date_of_birth', 'gender']);

        $this->mergeStep($application, 'health_information', [
            'blood_group' => $data['blood_group'] ?? null,
            'genotype' => $data['genotype'] ?? null,
            'has_medical_condition' => $data['has_medical_condition'] ?? false,
            'medical_condition_details' => $data['medical_condition_details'] ?? null,
        ]);

        $this->mergeStep($application, 'next_of_kin', [
            'next_of_kin' => $data['next_of_kin'] ?? null,
            'next_of_kin_relationship' => $data['next_of_kin_relationship'] ?? null,
            'next_of_kin_phone' => $data['next_of_kin_phone'] ?? null,
            'next_of_kin_email' => $data['next_of_kin_email'] ?? null,
            'next_of_kin_address' => $data['next_of_kin_address'] ?? null,
        ]);

        $this->mergeStep($application, 'sponsor', [
            'sponsor_name' => $data['sponsor_name'] ?? null,
            'sponsor_relationship' => $data['sponsor_relationship'] ?? null,
            'sponsor_phone' => $data['sponsor_phone'] ?? null,
            'sponsor_email' => $data['sponsor_email'] ?? null,
            'sponsor_address' => $data['sponsor_address'] ?? null,
        ]);

        $this->mergeStep($application, 'application_form', [
            'phone' => $application->user?->phone,
            'alternate_phone' => $this->normalizedAlternatePhone($data) ?? $this->existingStepValue($application, 'application_form', 'alternate_phone'),
            'address' => $data['address'] ?? null,
            'email' => $data['email'] ?? null,
        ]);

        $academic = [
            'first_sitting' => $data['first_sitting'] ?? null,
            'second_sitting' => $data['second_sitting'] ?? null,
            'other_qualifications' => $data['other_qualifications'] ?? null,
        ];
        if ($jamb) {
            $academic['jamb_registration'] = $jamb;
        }
        $this->mergeStep($application, 'academic_qualifications', $academic);

        if (($application->entry_mode ?? '') === 'utme' && array_key_exists('utme', $data)) {
            $this->mergeStep($application, 'utme', ['utme' => $data['utme']]);
        }

        if (($application->entry_mode ?? '') === 'de' && is_array($data['direct_entry'] ?? null)) {
            $this->mergeStep($application, 'direct_entry', $data['direct_entry']);
        }
        if (($application->entry_mode ?? '') === 'transfer') {
            if (is_array($data['transfer_background'] ?? null)) {
                $this->mergeStep($application, 'transfer_background', $data['transfer_background']);
            }
            if (is_array($data['credit_assessment'] ?? null)) {
                $this->mergeStep($application, 'credit_assessment', $data['credit_assessment']);
            }
        }

        if (($application->entry_mode ?? '') === 'pg') {
            $this->mergeStep($application, 'pg_background', [
                'prior_degrees' => $data['prior_degrees'] ?? null,
                'nysc_status' => $data['nysc_status'] ?? null,
                'nysc_number' => $data['nysc_number'] ?? null,
                'nysc_year' => $data['nysc_year'] ?? null,
                'nysc_exemption_reason' => $data['nysc_exemption_reason'] ?? null,
                'professional_qualifications' => $data['professional_qualifications'] ?? null,
                'other_qualifications' => $data['other_qualifications'] ?? null,
            ]);
            $this->mergeStep($application, 'pg_research', [
                'research_interest' => $data['research_interest'] ?? null,
                'proposed_area' => $data['proposed_area'] ?? null,
                'statement_of_purpose' => $data['statement_of_purpose'] ?? null,
                'publications' => $data['publications'] ?? null,
                'supervisor_preferences' => $data['supervisor_preferences'] ?? null,
            ]);
            if (array_key_exists('referees', $data)) {
                $this->mergeStep($application, 'pg_referees', [
                    'referees' => $data['referees'],
                ]);
            }
        }

        $firstProgram = isset($data['first_choice_program_id'])
            ? Program::query()->with('department')->find($data['first_choice_program_id'])
            : null;
        $secondProgram = AdmissionEntryRules::allowsSecondProgramme((string) $application->entry_mode)
            && ! empty($data['second_choice_program_id'])
            ? Program::query()->with('department')->find($data['second_choice_program_id'])
            : null;

        $this->mergeStep($application, 'programme_selection', [
            'first_choice_college_id' => $firstProgram?->department?->faculty_id,
            'first_choice_department_id' => $firstProgram?->department_id,
            'first_choice_program_id' => $firstProgram?->id,
            'second_choice_college_id' => $secondProgram?->department?->faculty_id,
            'second_choice_department_id' => $secondProgram?->department_id,
            'second_choice_program_id' => $secondProgram?->id,
            'program_id' => $firstProgram?->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $updates
     * @param  list<string>  $preserve
     */
    private function mergeStep(Application $application, string $stepKey, array $updates, array $preserve = []): void
    {
        $step = $application->steps()->where('step_key', $stepKey)->first();
        if (! $step) {
            $step = $application->steps()->create([
                'step_key' => $stepKey,
                'status' => 'saved',
                'payload' => [],
            ]);
        }
        $payload = is_array($step->payload) ? $step->payload : [];
        foreach ($preserve as $field) {
            if (array_key_exists($field, $payload)) {
                $updates[$field] = $payload[$field];
            }
        }
        $step->update([
            'payload' => array_merge($payload, $updates),
            'status' => $step->status === 'pending' ? 'saved' : $step->status,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncStudentProfile(?Student $student, array $data, ?int $programId): void
    {
        if (! $student) {
            return;
        }
        $student->loadMissing('medicalProfile');

        $student->update([
            'marital_status' => $data['marital_status'] ?? $student->marital_status,
            'religion' => $data['religion'] ?? $student->religion,
            'country' => $data['country'] ?? $student->country,
            'state' => $data['state'] ?? $student->state,
            'lga' => $data['lga'] ?? $student->lga,
            'phone' => $student->phone,
            'alternate_phone' => $this->normalizedAlternatePhone($data) ?? $student->alternate_phone,
            'address' => $data['address'] ?? $student->address,
            'next_of_kin' => $data['next_of_kin'] ?? $student->next_of_kin,
            'next_of_kin_relationship' => $data['next_of_kin_relationship'] ?? $student->next_of_kin_relationship,
            'next_of_kin_phone' => $data['next_of_kin_phone'] ?? $student->next_of_kin_phone,
            'next_of_kin_email' => $data['next_of_kin_email'] ?? $student->next_of_kin_email,
            'next_of_kin_address' => $data['next_of_kin_address'] ?? $student->next_of_kin_address,
            'sponsor_name' => $data['sponsor_name'] ?? $student->sponsor_name,
            'sponsor_relationship' => $data['sponsor_relationship'] ?? $student->sponsor_relationship,
            'sponsor_phone' => $data['sponsor_phone'] ?? $student->sponsor_phone,
            'sponsor_email' => $data['sponsor_email'] ?? $student->sponsor_email,
            'sponsor_address' => $data['sponsor_address'] ?? $student->sponsor_address,
            'program_id' => $programId ?: $student->program_id,
        ]);

        $medical = [
            'blood_type' => $data['blood_group'] ?? $student->medicalProfile?->blood_type,
            'genotype' => $data['genotype'] ?? $student->medicalProfile?->genotype,
            'has_medical_condition' => (bool) ($data['has_medical_condition'] ?? $student->medicalProfile?->has_medical_condition),
            'conditions' => $data['medical_condition_details'] ?? $student->medicalProfile?->conditions,
        ];
        if ($student->medicalProfile) {
            $student->medicalProfile->update($medical);
        } elseif (($data['blood_group'] ?? null) || ($data['genotype'] ?? null) || ($data['has_medical_condition'] ?? false) || ($data['medical_condition_details'] ?? null)) {
            $student->medicalProfile()->create($medical);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    private function alternatePhoneAttributes(array $data): array
    {
        $normalized = $this->normalizedAlternatePhone($data);
        if ($normalized !== null) {
            return ['alternate_phone' => $normalized];
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function normalizedAlternatePhone(array $data): ?string
    {
        if (! array_key_exists('alternate_phone', $data) || ! filled($data['alternate_phone'])) {
            return null;
        }

        return PhoneNumber::normalize((string) $data['alternate_phone']);
    }

    private function existingStepValue(Application $application, string $stepKey, string $field): mixed
    {
        $payload = $application->steps()->where('step_key', $stepKey)->first()?->payload;

        return is_array($payload) ? ($payload[$field] ?? null) : null;
    }
}
