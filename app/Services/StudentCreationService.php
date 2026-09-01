<?php

namespace App\Services;

use App\Models\Application;
use App\Models\MedicalProfile;
use App\Models\PgRecord;
use App\Models\Program;
use App\Models\Role;
use App\Models\Student;
use App\Models\StudentProgrammeChange;
use App\Models\Wallet;
use App\Support\ProgrammeEligibility;
use App\Support\StudyLevel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class StudentCreationService
{
    public function __construct(
        private AuditWriter $audit,
        private Notifier $notifier,
        private WorkflowEngine $workflows,
        private MatricSequence $matrics,
    ) {}

    public function createFromApplication(Application $application): Student
    {
        return DB::transaction(function () use ($application) {
            $application->load(['user', 'program', 'steps', 'academicSession', 'intake.term.session']);
            $biodata = $application->mergedProfilePayload();
            $contact = $application->steps()->where('step_key', 'application_form')->first()?->payload ?? [];
            $isJupeb = $application->entry_mode === 'jupeb';
            $matric = $isJupeb ? null : $this->matrics->allocate($application);

            $student = Student::query()->create([
                'user_id' => $application->user_id,
                'application_id' => $application->id,
                'program_id' => $application->program_id,
                'student_number' => $matric,
                'matric_number' => $matric,
                'first_name' => $biodata['first_name'] ?? $application->user->name,
                'middle_name' => $biodata['middle_name'] ?? null,
                'last_name' => $biodata['last_name'] ?? '',
                'date_of_birth' => $biodata['date_of_birth'] ?? null,
                'gender' => $biodata['gender'] ?? null,
                'marital_status' => $biodata['marital_status'] ?? null,
                'religion' => $biodata['religion'] ?? null,
                'country' => $biodata['country'] ?? null,
                'state' => $biodata['state'] ?? null,
                'lga' => $biodata['lga'] ?? null,
                'nin' => $biodata['nin'] ?? null,
                'photo_path' => $biodata['photo_path'] ?? null,
                'phone' => $contact['phone'] ?? $biodata['phone'] ?? null,
                'alternate_phone' => $contact['alternate_phone'] ?? $application->user?->alternate_phone ?? null,
                'address' => $contact['address'] ?? $biodata['address'] ?? null,
                'next_of_kin' => $biodata['next_of_kin'] ?? null,
                'next_of_kin_phone' => $biodata['next_of_kin_phone'] ?? null,
                'next_of_kin_relationship' => $biodata['next_of_kin_relationship'] ?? null,
                'next_of_kin_email' => $biodata['next_of_kin_email'] ?? null,
                'next_of_kin_address' => $biodata['next_of_kin_address'] ?? null,
                'sponsor_name' => $biodata['sponsor_name'] ?? null,
                'sponsor_relationship' => $biodata['sponsor_relationship'] ?? null,
                'sponsor_phone' => $biodata['sponsor_phone'] ?? null,
                'sponsor_email' => $biodata['sponsor_email'] ?? null,
                'sponsor_address' => $biodata['sponsor_address'] ?? null,
                'study_level' => StudyLevel::fromEntryMode($application->entry_mode),
                'current_level' => $this->entryLevelFor($application),
                'status' => 'active',
                'nin_locked' => true,
            ]);

            $wallet = Wallet::query()->create([
                'student_id' => $student->id,
                'balance' => 0,
                'status' => 'active',
            ]);

            MedicalProfile::query()->create([
                'student_id' => $student->id,
                'blood_type' => $biodata['blood_group'] ?? null,
                'genotype' => $biodata['genotype'] ?? null,
                'has_medical_condition' => (bool) ($biodata['has_medical_condition'] ?? false),
                'conditions' => $biodata['medical_condition_details'] ?? null,
            ]);

            if ($application->entry_mode === 'pg') {
                $research = ProgrammeEligibility::step($application, 'pg_research');
                $prefs = collect($research['supervisor_preferences'] ?? [])->filter()->values();
                PgRecord::query()->create([
                    'student_id' => $student->id,
                    'supervisor_staff_id' => $prefs->first() ?: null,
                    'topic' => $research['proposed_area'] ?? $research['research_interest'] ?? null,
                    'proposal_status' => 'not_started',
                    'thesis_status' => 'not_started',
                ]);
            }

            $studentRole = Role::query()->where('slug', 'student')->first();
            $applicantRole = Role::query()->where('slug', 'applicant')->first();
            if ($applicantRole) {
                $application->user->roles()->detach($applicantRole->id);
            }
            if ($studentRole) {
                $application->user->roles()->syncWithoutDetaching([$studentRole->id]);
            }

            if ($isJupeb) {
                $year = $this->matrics->year($application);
                $student->update([
                    'student_number' => 'J/'.$year.'/'.str_pad((string) $student->id, 6, '0', STR_PAD_LEFT),
                ]);
            }

            $application->update([
                'stage' => 'matriculated',
                'student_id' => $student->id,
            ]);

            $this->audit->record(
                'student.created',
                'Student created after acceptance fee',
                'admissions',
                'student',
                $student->id,
                null,
                $student,
                'Acceptance fee paid'
            );
            $welcome = $isJupeb
                ? 'Your student record and wallet are now active. Your JUPEB matric number will be issued by the university.'
                : 'Your student record and wallet are now active. Matric number: '.$matric;
            $this->notifier->send($application->user, 'student_created', 'Welcome to Bells University', $welcome, 'sis', $student->id);

            $this->workflows->startEnrolment($student->fresh(), $application);
            if ($application->entry_mode === 'pg') {
                $record = PgRecord::query()->where('student_id', $student->id)->first();
                if ($record) {
                    $this->workflows->startResearch($record, $application);
                }
            }

            return $student->fresh(['wallet', 'program', 'user']);
        });
    }

    public function attachExistingStudent(Application $application, Student $student): Student
    {
        return DB::transaction(function () use ($application, $student) {
            $application->load(['user', 'program', 'steps']);
            $biodata = $application->mergedProfilePayload();
            $contact = $application->steps()->where('step_key', 'application_form')->first()?->payload ?? [];
            $fromProgramId = $student->program_id ? (int) $student->program_id : null;
            $toProgramId = $application->program_id ? (int) $application->program_id : $fromProgramId;
            $fromLevel = (int) $student->current_level;
            $toLevel = $this->entryLevelFor($application);

            $student->update([
                'program_id' => $toProgramId ?: $student->program_id,
                'study_level' => StudyLevel::fromEntryMode($application->entry_mode),
                'current_level' => $toLevel,
                'status' => 'active',
                'marital_status' => $biodata['marital_status'] ?? $student->marital_status,
                'religion' => $biodata['religion'] ?? $student->religion,
                'country' => $biodata['country'] ?? $student->country,
                'state' => $biodata['state'] ?? $student->state,
                'lga' => $biodata['lga'] ?? $student->lga,
                'phone' => $contact['phone'] ?? $biodata['phone'] ?? $student->phone,
                'alternate_phone' => $contact['alternate_phone'] ?? $application->user?->alternate_phone ?? $student->alternate_phone,
                'address' => $contact['address'] ?? $biodata['address'] ?? $student->address,
                'next_of_kin' => $biodata['next_of_kin'] ?? $student->next_of_kin,
                'next_of_kin_phone' => $biodata['next_of_kin_phone'] ?? $student->next_of_kin_phone,
                'next_of_kin_relationship' => $biodata['next_of_kin_relationship'] ?? $student->next_of_kin_relationship,
                'next_of_kin_email' => $biodata['next_of_kin_email'] ?? $student->next_of_kin_email,
                'next_of_kin_address' => $biodata['next_of_kin_address'] ?? $student->next_of_kin_address,
                'sponsor_name' => $biodata['sponsor_name'] ?? $student->sponsor_name,
                'sponsor_relationship' => $biodata['sponsor_relationship'] ?? $student->sponsor_relationship,
                'sponsor_phone' => $biodata['sponsor_phone'] ?? $student->sponsor_phone,
                'sponsor_email' => $biodata['sponsor_email'] ?? $student->sponsor_email,
                'sponsor_address' => $biodata['sponsor_address'] ?? $student->sponsor_address,
            ]);

            if ($fromProgramId && $toProgramId && $fromProgramId !== $toProgramId) {
                $this->recordSubsequentAdmission(
                    $student,
                    $fromProgramId,
                    $toProgramId,
                    $fromLevel,
                    $toLevel,
                    $application->id,
                );
            }

            $student->loadMissing('pgRecord');
            if ($application->entry_mode === 'pg' && ! $student->pgRecord) {
                $research = ProgrammeEligibility::step($application, 'pg_research');
                $prefs = collect($research['supervisor_preferences'] ?? [])->filter()->values();
                PgRecord::query()->create([
                    'student_id' => $student->id,
                    'supervisor_staff_id' => $prefs->first() ?: null,
                    'topic' => $research['proposed_area'] ?? $research['research_interest'] ?? null,
                    'proposal_status' => 'not_started',
                    'thesis_status' => 'not_started',
                ]);
            }

            $studentRole = Role::query()->where('slug', 'student')->first();
            $applicantRole = Role::query()->where('slug', 'applicant')->first();
            if ($applicantRole) {
                $application->user->roles()->detach($applicantRole->id);
            }
            if ($studentRole) {
                $application->user->roles()->syncWithoutDetaching([$studentRole->id]);
            }

            $application->update([
                'stage' => 'matriculated',
                'student_id' => $student->id,
            ]);

            $this->audit->record(
                'student.reattached',
                'Existing student record reused after acceptance fee; matric unchanged',
                'admissions',
                'student',
                $student->id,
                null,
                $student->fresh(),
                'Acceptance fee paid on a later application'
            );
            $this->notifier->send(
                $application->user,
                'student_created',
                'Admission completed',
                'Your existing student record was linked to this programme. Matric number: '.($student->matric_number ?: $student->student_number),
                'sis',
                $student->id,
            );

            try {
                $this->workflows->startEnrolment($student->fresh(), $application);
                if ($application->entry_mode === 'pg') {
                    $record = PgRecord::query()->where('student_id', $student->id)->first();
                    if ($record) {
                        $this->workflows->startResearch($record, $application);
                    }
                }
            } catch (\Throwable) {
                // Programme may not have an enrolment workflow yet.
            }

            return $student->fresh(['wallet', 'program', 'user']);
        });
    }

    public function createFromImport(
        Application $application,
        string $matricNumber,
        int $currentLevel,
        ?string $studentNumber = null,
    ): Student {
        return DB::transaction(function () use ($application, $matricNumber, $currentLevel, $studentNumber) {
            $application->load(['user', 'program', 'steps', 'academicSession', 'intake.term.session']);
            $biodata = $application->mergedProfilePayload();
            $contact = $application->steps()->where('step_key', 'application_form')->first()?->payload ?? [];
            $number = $studentNumber ?: $matricNumber;
            $this->matrics->noteIssued($matricNumber);
            if ($number !== $matricNumber) {
                $this->matrics->noteIssued($number);
            }

            $student = Student::query()->create([
                'user_id' => $application->user_id,
                'application_id' => $application->id,
                'program_id' => $application->program_id,
                'student_number' => $number,
                'matric_number' => $matricNumber,
                'first_name' => $biodata['first_name'] ?? $application->user->name,
                'middle_name' => $biodata['middle_name'] ?? null,
                'last_name' => $biodata['last_name'] ?? '',
                'date_of_birth' => $biodata['date_of_birth'] ?? null,
                'gender' => $biodata['gender'] ?? null,
                'marital_status' => $biodata['marital_status'] ?? null,
                'religion' => $biodata['religion'] ?? null,
                'country' => $biodata['country'] ?? null,
                'state' => $biodata['state'] ?? null,
                'lga' => $biodata['lga'] ?? null,
                'nin' => $biodata['nin'] ?? null,
                'photo_path' => $biodata['photo_path'] ?? null,
                'phone' => $contact['phone'] ?? $biodata['phone'] ?? null,
                'alternate_phone' => $contact['alternate_phone'] ?? $application->user?->alternate_phone ?? null,
                'address' => $contact['address'] ?? $biodata['address'] ?? null,
                'next_of_kin' => $biodata['next_of_kin'] ?? null,
                'next_of_kin_phone' => $biodata['next_of_kin_phone'] ?? null,
                'next_of_kin_relationship' => $biodata['next_of_kin_relationship'] ?? null,
                'next_of_kin_email' => $biodata['next_of_kin_email'] ?? null,
                'next_of_kin_address' => $biodata['next_of_kin_address'] ?? null,
                'sponsor_name' => $biodata['sponsor_name'] ?? null,
                'sponsor_relationship' => $biodata['sponsor_relationship'] ?? null,
                'sponsor_phone' => $biodata['sponsor_phone'] ?? null,
                'sponsor_email' => $biodata['sponsor_email'] ?? null,
                'sponsor_address' => $biodata['sponsor_address'] ?? null,
                'study_level' => StudyLevel::fromEntryMode($application->entry_mode),
                'current_level' => $currentLevel,
                'status' => 'active',
                'nin_locked' => $application->ninVerified(),
            ]);

            Wallet::query()->create([
                'student_id' => $student->id,
                'balance' => 0,
                'status' => 'active',
            ]);

            MedicalProfile::query()->create([
                'student_id' => $student->id,
                'blood_type' => $biodata['blood_group'] ?? null,
                'genotype' => $biodata['genotype'] ?? null,
                'has_medical_condition' => (bool) ($biodata['has_medical_condition'] ?? false),
                'conditions' => $biodata['medical_condition_details'] ?? null,
            ]);

            if ($application->entry_mode === 'pg') {
                $research = ProgrammeEligibility::step($application, 'pg_research');
                $prefs = collect($research['supervisor_preferences'] ?? [])->filter()->values();
                PgRecord::query()->create([
                    'student_id' => $student->id,
                    'supervisor_staff_id' => $prefs->first() ?: null,
                    'topic' => $research['proposed_area'] ?? $research['research_interest'] ?? null,
                    'proposal_status' => 'not_started',
                    'thesis_status' => 'not_started',
                ]);
            }

            $studentRole = Role::query()->firstOrCreate(
                ['slug' => 'student'],
                ['name' => 'Student', 'is_system' => true, 'is_active' => true],
            );
            $applicantRole = Role::query()->where('slug', 'applicant')->first();
            if ($applicantRole) {
                $application->user->roles()->detach($applicantRole->id);
            }
            $application->user->roles()->syncWithoutDetaching([$studentRole->id]);

            $application->update([
                'stage' => 'matriculated',
                'student_id' => $student->id,
            ]);

            $this->audit->record(
                'student.imported',
                'Student imported with supplied matric number',
                'admissions',
                'student',
                $student->id,
                null,
                $student,
                'Legacy student import'
            );
            $this->notifier->send(
                $application->user,
                'student_created',
                'Welcome to Bells University',
                'Your student record and wallet are now active. Sign in with matric number: '.$matricNumber,
                'sis',
                $student->id,
            );

            try {
                $this->workflows->startEnrolment($student->fresh(), $application);
                if ($application->entry_mode === 'pg') {
                    $record = PgRecord::query()->where('student_id', $student->id)->first();
                    if ($record) {
                        $this->workflows->startResearch($record, $application);
                    }
                }
            } catch (\Throwable) {
                // Programme may not have an enrolment workflow yet.
            }

            return $student->fresh(['wallet', 'program', 'user']);
        });
    }

    private function recordSubsequentAdmission(
        Student $student,
        int $fromProgramId,
        int $toProgramId,
        int $fromLevel,
        int $toLevel,
        ?int $applicationId,
    ): void {
        StudentProgrammeChange::query()->create([
            'student_id' => $student->id,
            'from_program_id' => $fromProgramId,
            'to_program_id' => $toProgramId,
            'from_level' => $fromLevel,
            'to_level' => $toLevel,
            'same_college' => $this->programmesShareCollege($fromProgramId, $toProgramId),
            'kind' => StudentProgrammeChange::KIND_SUBSEQUENT_ADMISSION,
            'application_id' => $applicationId,
            'created_by' => Auth::id(),
        ]);
    }

    private function programmesShareCollege(int $fromProgramId, int $toProgramId): bool
    {
        $from = Program::query()->with('department')->find($fromProgramId);
        $to = Program::query()->with('department')->find($toProgramId);
        $fromFaculty = (int) ($from?->department?->faculty_id ?? 0);
        $toFaculty = (int) ($to?->department?->faculty_id ?? 0);

        return $fromFaculty > 0 && $fromFaculty === $toFaculty;
    }

    private function entryLevelFor(Application $application): int
    {
        if ($application->entry_mode === 'pg') {
            return 1;
        }
        if ($application->entry_mode === 'transfer') {
            $assessed = ProgrammeEligibility::step($application, 'credit_assessment')['approved_entry_level'] ?? null;

            return $this->normalizeUgLevel($assessed) ?: 200;
        }
        if ($application->entry_mode === 'de') {
            $requested = ProgrammeEligibility::step($application, 'direct_entry')['requested_entry_level'] ?? null;

            return $this->normalizeUgLevel($requested) ?: 200;
        }

        return 100;
    }

    private function normalizeUgLevel(mixed $value): ?int
    {
        $n = (int) $value;
        if ($n <= 0) {
            return null;
        }

        return $n < 100 ? $n * 100 : $n;
    }
}
