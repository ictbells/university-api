<?php

namespace App\Services;

use App\Models\Application;
use App\Models\MedicalProfile;
use App\Models\PgRecord;
use App\Models\Role;
use App\Models\Student;
use App\Models\Wallet;
use App\Support\ProgrammeEligibility;
use App\Support\StudyLevel;
use Illuminate\Support\Facades\DB;

class StudentCreationService
{
    public function __construct(
        private AuditWriter $audit,
        private Notifier $notifier,
        private WorkflowEngine $workflows,
    ) {}

    public function createFromApplication(Application $application): Student
    {
        return DB::transaction(function () use ($application) {
            $application->load(['user', 'program', 'steps']);
            $biodata = $application->mergedProfilePayload();
            $contact = $application->steps()->where('step_key', 'application_form')->first()?->payload ?? [];
            $count = Student::query()->count() + 1;
            $year = now()->format('Y');

            $student = Student::query()->create([
                'user_id' => $application->user_id,
                'application_id' => $application->id,
                'program_id' => $application->program_id,
                'student_number' => 'BUT/'.$year.'/'.str_pad((string) $count, 4, '0', STR_PAD_LEFT),
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

            $matric = 'BUT/'.$year.'/M/'.str_pad((string) $count, 4, '0', STR_PAD_LEFT);
            $student->update(['matric_number' => $matric]);

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
            $this->notifier->send($application->user, 'student_created', 'Welcome to Bells University', 'Your student record and wallet are now active. Matric number: '.$matric, 'sis', $student->id);

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

    public function createFromImport(
        Application $application,
        string $matricNumber,
        int $currentLevel,
        ?string $studentNumber = null,
    ): Student {
        return DB::transaction(function () use ($application, $matricNumber, $currentLevel, $studentNumber) {
            $application->load(['user', 'program', 'steps']);
            $biodata = $application->mergedProfilePayload();
            $contact = $application->steps()->where('step_key', 'application_form')->first()?->payload ?? [];
            $count = Student::query()->count() + 1;
            $year = now()->format('Y');
            $number = $studentNumber ?: ('BUT/'.$year.'/'.str_pad((string) $count, 4, '0', STR_PAD_LEFT));

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
                'nin_locked' => true,
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
