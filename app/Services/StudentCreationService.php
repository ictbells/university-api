<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Document;
use App\Models\MedicalProfile;
use App\Models\PgRecord;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Student;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;

class StudentCreationService
{
    public function __construct(private AuditWriter $audit, private Notifier $notifier) {}

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
                'study_level' => $application->entry_mode === 'pg' ? 'postgraduate' : 'undergraduate',
                'current_level' => $application->entry_mode === 'pg' ? 1 : 100,
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
                PgRecord::query()->create([
                    'student_id' => $student->id,
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

            $html = $this->idHtml($student->fresh());
            $doc = Document::query()->create([
                'student_id' => $student->id,
                'user_id' => $application->user_id,
                'type' => 'id_card',
                'title' => 'Student Digital ID',
                'html_body' => $html,
                'status' => 'issued',
            ]);
            $wallet->credentials()->create([
                'type' => 'id_card',
                'document_id' => $doc->id,
                'title' => 'Campus Digital ID',
                'payload' => $student->student_number,
                'issued_at' => now(),
            ]);

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

            return $student->fresh(['wallet', 'program', 'user']);
        });
    }

    private function idHtml(Student $student): string
    {
        $name = Setting::getValue('university_name', 'Bells University of Technology');
        $motto = Setting::getValue('university_motto', 'Chords of Knowledge');

        return '<div style="font-family:sans-serif;border:2px solid #0EA5E9;padding:16px;max-width:420px">'
            .'<h2 style="color:#0EA5E9;margin:0">'.$name.'</h2>'
            .'<p style="margin:4px 0;color:#166534">'.$motto.'</p>'
            .'<p><strong>'.$student->first_name.' '.$student->last_name.'</strong></p>'
            .'<p>Student No: '.$student->student_number.'<br>Matric: '.$student->matric_number.'</p>'
            .'<p>QR: '.$student->student_number.'</p></div>';
    }
}
