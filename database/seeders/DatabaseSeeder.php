<?php

namespace Database\Seeders;

use App\Models\AcademicLevel;
use App\Models\AcademicTerm;
use App\Models\Announcement;
use App\Models\Application;
use App\Models\AppNotification;
use App\Models\Campus;
use App\Models\Course;
use App\Models\CourseOffering;
use App\Models\Department;
use App\Models\Enrollment;
use App\Models\Faculty;
use App\Models\FeeItem;
use App\Models\Grade;
use App\Models\Hostel;
use App\Models\HostelAllocation;
use App\Models\HostelLevelWindow;
use App\Models\IntegrationEndpoint;
use App\Models\Intake;
use App\Models\OfficeDepartment;
use App\Models\OfficeSubunit;
use App\Models\OfficeUnit;
use App\Models\OlevelSubject;
use App\Models\Permission;
use App\Models\Program;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Staff;
use App\Models\User;
use App\Services\InvoiceService;
use App\Services\StudentCreationService;
use App\Support\PermissionCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        foreach (PermissionCatalog::all() as $perm) {
            Permission::query()->updateOrCreate(['key' => $perm['key']], $perm);
        }
        $allIds = Permission::query()->pluck('id');

        $roles = [
            'super-admin' => ['Super Admin', true, $allIds],
            'registrar' => ['Registrar', true, Permission::query()->whereIn('module', ['sis', 'academic', 'admissions', 'registrations', 'reports', 'institution'])->pluck('id')],
            'admissions' => ['Admissions', true, Permission::query()
                ->where('module', 'admissions')
                ->orWhereIn('key', [
                    'students.view_any',
                    'pg.view',
                    'academic.programmes.manage',
                    'academic.intakes.manage',
                    'academic.olevel.manage',
                    'registrations.view',
                ])
                ->pluck('id')],
            'finance' => ['Finance', true, Permission::query()->whereIn('module', ['fees', 'payments', 'wallet'])->pluck('id')],
            'medical' => ['Medical', true, Permission::query()->where('module', 'medical')->pluck('id')],
            'faculty' => ['Faculty', true, Permission::query()->whereIn('key', ['students.view_any'])->pluck('id')],
            'pg-coordinator' => ['PG Coordinator', true, Permission::query()->where('module', 'postgraduate')->orWhereIn('key', ['admissions.view', 'students.view_any'])->pluck('id')],
            'hostel-officer' => ['Hostel Officer', true, Permission::query()->where('module', 'hostel')->pluck('id')],
            'student' => ['Student', true, Permission::query()->whereIn('key', [
                'students.view_own', 'wallet.view_own', 'medical.view_own', 'documents.view_own', 'admissions.apply',
            ])->pluck('id')],
            'applicant' => ['Applicant', true, Permission::query()->whereIn('key', ['admissions.apply', 'documents.view_own'])->pluck('id')],
        ];
        foreach ($roles as $slug => [$name, $system, $ids]) {
            $role = Role::query()->updateOrCreate(
                ['slug' => $slug],
                ['name' => $name, 'description' => $name, 'is_system' => $system, 'is_active' => true]
            );
            $role->permissions()->sync($ids);
        }

        Setting::setValue('university_name', 'Bells University of Technology');
        Setting::setValue('university_motto', 'Chords of Knowledge');
        Setting::setValue('maintenance', '0');
        Setting::setValue('security.two_factor_enabled', '0');
        Setting::setValue('security.password_rotation_days', '0');
        Setting::setValue('security.inactivity_logout_minutes', '0');

        $campus = Campus::query()->firstOrCreate(['code' => 'OTA'], ['name' => 'Main Campus', 'city' => 'Ota', 'address' => 'Km 8, Idiroko Road, Ota']);
        $eng = Faculty::query()->firstOrCreate(['code' => 'COE'], ['campus_id' => $campus->id, 'name' => 'College of Engineering']);
        $nat = Faculty::query()->firstOrCreate(['code' => 'COLNAS'], ['campus_id' => $campus->id, 'name' => 'College of Natural and Applied Sciences']);
        $cse = Department::query()->firstOrCreate(['code' => 'CPE'], ['faculty_id' => $eng->id, 'name' => 'Computer Engineering']);
        $csc = Department::query()->firstOrCreate(['code' => 'CSC'], ['faculty_id' => $nat->id, 'name' => 'Computer Science']);
        $bsc = Program::query()->firstOrCreate(['code' => 'CPE'], [
            'department_id' => $cse->id,
            'name' => 'B.Eng Computer Engineering',
            'award_type' => 'B.Eng',
            'study_level' => 'undergraduate',
            'entry_modes' => ['utme', 'de', 'jupeb', 'transfer'],
            'duration_years' => 5,
        ]);
        $msc = Program::query()->firstOrCreate(['code' => 'CSC-MSC'], [
            'department_id' => $csc->id,
            'name' => 'M.Sc Computer Science',
            'award_type' => 'M.Sc',
            'study_level' => 'postgraduate',
            'entry_modes' => ['pg'],
            'duration_years' => 2,
        ]);

        $term = AcademicTerm::query()->firstOrCreate(
            ['session_label' => '2025/2026', 'name' => 'Harmattan 2025/2026'],
            ['starts_on' => '2025-09-01', 'ends_on' => '2026-01-31', 'is_current' => true]
        );
        Setting::setValue('current_term_id', $term->id);

        foreach (
            [
                ['name' => '100 Level', 'code' => '100', 'study_level' => 'undergraduate', 'sort_order' => 1],
                ['name' => '200 Level', 'code' => '200', 'study_level' => 'undergraduate', 'sort_order' => 2],
                ['name' => '300 Level', 'code' => '300', 'study_level' => 'undergraduate', 'sort_order' => 3],
                ['name' => '400 Level', 'code' => '400', 'study_level' => 'undergraduate', 'sort_order' => 4],
                ['name' => 'Year 1', 'code' => 'Y1', 'study_level' => 'postgraduate', 'sort_order' => 1],
                ['name' => 'Year 2', 'code' => 'Y2', 'study_level' => 'postgraduate', 'sort_order' => 2],
            ] as $level
        ) {
            AcademicLevel::query()->firstOrCreate(
                ['code' => $level['code'], 'study_level' => $level['study_level']],
                $level
            );
        }

        foreach (
            [
                ['name' => 'English Language', 'code' => 'ENG'],
                ['name' => 'Mathematics', 'code' => 'MTH'],
                ['name' => 'Physics', 'code' => 'PHY'],
                ['name' => 'Chemistry', 'code' => 'CHM'],
                ['name' => 'Biology', 'code' => 'BIO'],
                ['name' => 'Economics', 'code' => 'ECO'],
                ['name' => 'Government', 'code' => 'GOV'],
                ['name' => 'Literature in English', 'code' => 'LIT'],
                ['name' => 'Further Mathematics', 'code' => 'FMT'],
                ['name' => 'Geography', 'code' => 'GEO'],
            ] as $subject
        ) {
            OlevelSubject::query()->firstOrCreate(['code' => $subject['code']], $subject);
        }

        $registry = OfficeDepartment::query()->firstOrCreate(['code' => 'REG'], ['name' => 'Registry', 'description' => 'Student records and academic administration']);
        $ict = OfficeDepartment::query()->firstOrCreate(['code' => 'ICT'], ['name' => 'ICT Services', 'description' => 'Information and communication technology']);
        $records = OfficeUnit::query()->firstOrCreate(
            ['office_department_id' => $registry->id, 'code' => 'SR'],
            ['name' => 'Student Records', 'description' => 'Matriculation and student files']
        );
        OfficeUnit::query()->firstOrCreate(
            ['office_department_id' => $ict->id, 'code' => 'INF'],
            ['name' => 'Infrastructure', 'description' => 'Networks, servers, and systems']
        );
        OfficeSubunit::query()->firstOrCreate(
            ['office_unit_id' => $records->id, 'code' => 'TR'],
            ['name' => 'Transcript Desk', 'description' => 'Transcript requests and issuance']
        );

        foreach (['utme', 'de', 'jupeb', 'transfer', 'pg'] as $mode) {
            Intake::query()->firstOrCreate(
                ['entry_mode' => $mode, 'academic_term_id' => $term->id],
                ['name' => strtoupper($mode).' 2025/2026', 'is_open' => true]
            );
            FeeItem::query()->firstOrCreate(
                ['category' => 'application_fee', 'entry_mode' => $mode],
                ['name' => strtoupper($mode).' Application Fee', 'amount' => $mode === 'pg' ? 25000 : 15000, 'wallet_allowed' => false]
            );
        }
        FeeItem::query()->firstOrCreate(['category' => 'acceptance_fee'], ['name' => 'Acceptance Fee', 'amount' => 50000, 'wallet_allowed' => false]);
        FeeItem::query()->firstOrCreate(['category' => 'tuition'], ['name' => 'Tuition', 'amount' => 450000, 'wallet_allowed' => true]);
        FeeItem::query()->firstOrCreate(['category' => 'hostel'], ['name' => 'Hostel Fee', 'amount' => 120000, 'wallet_allowed' => true]);
        FeeItem::query()->firstOrCreate(['category' => 'medical'], ['name' => 'Clinic charge', 'amount' => 5000, 'wallet_allowed' => true]);

        $course = Course::query()->firstOrCreate(['code' => 'CPE 201'], ['department_id' => $cse->id, 'title' => 'Digital Systems', 'units' => 3]);
        $bsc->courses()->syncWithoutDetaching([$course->id]);
        $password = 'Password1!';

        $make = function (string $email, string $name, string $roleSlug, bool $staff = true, ?string $phone = null) use ($password, $cse) {
            $user = User::query()->firstOrCreate(
                ['email' => $email],
                ['name' => $name, 'password' => $password, 'status' => 'active', 'phone' => $phone]
            );
            $user->update(array_filter(['phone' => $phone], fn ($v) => $v !== null));
            $user->roles()->sync([Role::query()->where('slug', $roleSlug)->value('id')]);
            if ($staff && ! $user->staff) {
                Staff::query()->create([
                    'user_id' => $user->id,
                    'department_id' => $cse->id,
                    'staff_number' => 'STF-'.strtoupper(substr($roleSlug, 0, 3)).'-'.$user->id,
                    'title' => $name,
                ]);
            }

            return $user->fresh('staff');
        };

        $make('admin@bellsuniversity.edu.ng', 'Platform Admin', 'super-admin', true, '08030000001');
        $make('registrar@bellsuniversity.edu.ng', 'University Registrar', 'registrar', true, '08030000002');
        $make('admissions@bellsuniversity.edu.ng', 'Admissions Officer', 'admissions', true, '08030000003');
        $make('finance@bellsuniversity.edu.ng', 'Finance Officer', 'finance', true, '08030000004');
        $make('medical@bellsuniversity.edu.ng', 'Clinic Officer', 'medical', true, '08030000005');
        $faculty = $make('faculty@bellsuniversity.edu.ng', 'Dr. Faculty Member', 'faculty', true, '08030000006');
        $make('pg@bellsuniversity.edu.ng', 'PG Coordinator', 'pg-coordinator', true, '08030000007');
        $make('hostel@bellsuniversity.edu.ng', 'Hostel Officer', 'hostel-officer', true, '08030000008');

        $offering = CourseOffering::query()->firstOrCreate(
            ['course_id' => $course->id, 'academic_term_id' => $term->id, 'section' => 'A'],
            ['faculty_staff_id' => $faculty->staff->id]
        );

        $invoices = app(InvoiceService::class);
        $creator = app(StudentCreationService::class);

        $applicant = User::query()->firstOrCreate(
            ['email' => 'applicant@bellsuniversity.edu.ng'],
            ['name' => 'Mid Form Applicant', 'password' => $password, 'status' => 'active', 'jamb_registration' => '20261234AB']
        );
        $applicant->update(['jamb_registration' => '20261234AB']);
        $applicant->roles()->sync([Role::query()->where('slug', 'applicant')->value('id')]);
        $app = $this->startPaidApplication($applicant, 'utme', $bsc, $invoices);
        $app->update(['stage' => 'form_in_progress', 'current_step' => 'biodata']);
        $app->steps()->where('step_key', 'application_form')->update(['status' => 'saved', 'payload' => ['phone' => '08011112222', 'declaration' => true]]);

        $unpaid = User::query()->firstOrCreate(
            ['email' => 'unpaid.applicant@bellsuniversity.edu.ng'],
            ['name' => 'Unpaid Applicant', 'password' => $password, 'status' => 'active', 'jamb_registration' => '20261234CD']
        );
        $unpaid->update(['jamb_registration' => '20261234CD']);
        $unpaid->roles()->sync([Role::query()->where('slug', 'applicant')->value('id')]);
        $this->startUnpaidApplication($unpaid, 'utme', $bsc, $invoices);

        $offered = User::query()->firstOrCreate(
            ['email' => 'offered@bellsuniversity.edu.ng'],
            ['name' => 'Offered Applicant', 'password' => $password, 'status' => 'active', 'jamb_registration' => '20261234EF']
        );
        $offered->update(['jamb_registration' => '20261234EF']);
        $offered->roles()->sync([Role::query()->where('slug', 'applicant')->value('id')]);
        $offerApp = $this->startPaidApplication($offered, 'utme', $bsc, $invoices);
        $offerApp->steps()->update(['status' => 'saved', 'payload' => ['complete' => true]]);
        $offerApp->update(['stage' => 'awaiting_acceptance_fee', 'submitted_at' => now(), 'offer_reference' => 'OFF-2026-SEED']);
        $acc = FeeItem::query()->where('category', 'acceptance_fee')->first();
        $accInv = $invoices->createForFee($offered, $acc, $offerApp->id);
        $offerApp->update(['acceptance_fee_invoice_id' => $accInv->id]);

        $studentUser = User::query()->firstOrCreate(
            ['email' => 'student@bellsuniversity.edu.ng'],
            ['name' => 'Adaeze Okoye', 'password' => $password, 'status' => 'active', 'jamb_registration' => '20261234GH']
        );
        $studentUser->update(['jamb_registration' => '20261234GH']);
        $studentUser->roles()->sync([Role::query()->where('slug', 'applicant')->value('id')]);
        $studentApp = $this->startPaidApplication($studentUser, 'utme', $bsc, $invoices);
        $studentApp->steps()->where('step_key', 'biodata')->update([
            'status' => 'complete',
            'payload' => [
                'nin' => '12345678901',
                'first_name' => 'Adaeze',
                'middle_name' => 'Chioma',
                'last_name' => 'Okoye',
                'date_of_birth' => '2004-03-18',
                'gender' => 'Female',
                'next_of_kin' => 'Ngozi Okoye',
                'next_of_kin_phone' => '08020000000',
            ],
        ]);
        $studentApp->update(['stage' => 'acceptance_paid']);
        $student = $studentUser->student ?: $creator->createFromApplication($studentApp->fresh());
        $enroll = Enrollment::query()->firstOrCreate(
            ['student_id' => $student->id, 'course_offering_id' => $offering->id],
            ['status' => 'enrolled']
        );
        Grade::query()->firstOrCreate(['enrollment_id' => $enroll->id], ['letter' => 'A', 'points' => 5, 'score' => 82]);
        $tuition = FeeItem::query()->where('category', 'tuition')->first();
        $tuitionInvoice = $invoices->createForFee($studentUser, $tuition, $studentApp->id, $student->id);
        $tuitionInvoice->update(['status' => 'paid', 'balance' => 0]);

        $pgUser = User::query()->firstOrCreate(
            ['email' => 'pgstudent@bellsuniversity.edu.ng'],
            ['name' => 'Chinedu Bello', 'password' => $password, 'status' => 'active', 'jamb_registration' => '20261234IJ']
        );
        $pgUser->update(['jamb_registration' => '20261234IJ']);
        $pgUser->roles()->sync([Role::query()->where('slug', 'applicant')->value('id')]);
        $pgApp = $this->startPaidApplication($pgUser, 'pg', $msc, $invoices);
        $pgApp->steps()->where('step_key', 'biodata')->update([
            'status' => 'complete',
            'payload' => ['nin' => '10987654321', 'first_name' => 'Chinedu', 'last_name' => 'Bello', 'date_of_birth' => '1996-07-02', 'gender' => 'Male'],
        ]);
        $pgApp->update(['stage' => 'acceptance_paid']);
        $pgStudent = $pgUser->student ?: $creator->createFromApplication($pgApp->fresh());
        $pgStudent->pgRecord?->update(['supervisor_staff_id' => $faculty->staff->id, 'topic' => 'Trustworthy campus identity systems']);

        $hostel = Hostel::query()->firstOrCreate(
            ['name' => 'Queen Hall'],
            ['campus_id' => $campus->id, 'gender' => 'female', 'category' => 'undergraduate', 'is_active' => true],
        );
        $hostel->update(['category' => 'undergraduate', 'is_active' => true]);
        $jupebHostel = Hostel::query()->firstOrCreate(
            ['name' => 'JUPEB Residence'],
            ['campus_id' => $campus->id, 'gender' => 'mixed', 'category' => 'jupeb', 'is_active' => true],
        );
        $jupebHostel->update(['category' => 'jupeb', 'is_active' => true]);
        $block = $hostel->blocks()->create(['name' => 'Block A']);
        $room = $block->rooms()->create(['number' => 'A12', 'capacity' => 4, 'gender' => 'female']);
        $bed = $room->beds()->create(['label' => '1', 'status' => 'occupied']);
        $room->beds()->create(['label' => '2', 'status' => 'available']);
        HostelAllocation::query()->create([
            'student_id' => $student->id,
            'hostel_bed_id' => $bed->id,
            'academic_term_id' => $term->id,
            'status' => 'allocated',
            'allocated_at' => now(),
        ]);

        $level100 = AcademicLevel::query()->where('code', '100')->first();
        if ($level100) {
            foreach (['undergraduate', 'jupeb'] as $category) {
                HostelLevelWindow::query()->updateOrCreate(
                    [
                        'category' => $category,
                        'academic_level_id' => $level100->id,
                        'academic_term_id' => $term->id,
                    ],
                    ['is_active' => true],
                );
            }
        }

        Announcement::query()->create([
            'title' => 'Welcome to the 2025/2026 session',
            'body' => 'Complete outstanding fees and register your courses.',
            'audience' => 'all',
            'published_at' => now(),
        ]);
        AppNotification::query()->create([
            'user_id' => $studentUser->id,
            'type' => 'welcome',
            'title' => 'Your campus wallet is ready',
            'body' => 'Fund your wallet with Paystack to pay tuition.',
            'module' => 'wallet',
        ]);
        foreach (['paystack', 'prembly', 'email'] as $type) {
            IntegrationEndpoint::query()->create(['name' => ucfirst($type), 'type' => $type, 'enabled' => true]);
        }
    }

    private function startPaidApplication(User $user, string $mode, Program $program, InvoiceService $invoices): Application
    {
        $app = $this->startUnpaidApplication($user, $mode, $program, $invoices);
        $app->applicationFeeInvoice->update(['status' => 'paid', 'balance' => 0]);
        $app->update(['stage' => 'fee_paid', 'current_step' => 'application_form']);
        foreach (Application::FORM_STEPS as $step) {
            $app->steps()->firstOrCreate(['step_key' => $step], ['status' => 'pending', 'payload' => []]);
        }

        return $app->fresh(['applicationFeeInvoice', 'steps']);
    }

    private function startUnpaidApplication(User $user, string $mode, Program $program, InvoiceService $invoices): Application
    {
        $existing = Application::query()->where('user_id', $user->id)->where('entry_mode', $mode)->first();
        if ($existing) {
            return $existing->fresh(['applicationFeeInvoice', 'steps']);
        }
        $intake = Intake::query()->where('entry_mode', $mode)->first();
        $app = Application::query()->create([
            'user_id' => $user->id,
            'intake_id' => $intake->id,
            'program_id' => $program->id,
            'entry_mode' => $mode,
            'stage' => 'awaiting_application_fee',
        ]);
        foreach (Application::FORM_STEPS as $step) {
            $app->steps()->create(['step_key' => $step, 'status' => 'pending', 'payload' => []]);
        }
        $fee = FeeItem::query()->where('category', 'application_fee')->where('entry_mode', $mode)->first();
        $invoice = $invoices->createForFee($user, $fee, $app->id);
        $app->update(['application_fee_invoice_id' => $invoice->id]);

        return $app->fresh(['applicationFeeInvoice']);
    }
}
