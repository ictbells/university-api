<?php

namespace App\Support\Reports;

use App\Models\AcademicLevel;
use App\Models\AcademicSession;
use App\Models\Application;
use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\Campus;
use App\Models\CandidateData;
use App\Models\ClinicVisit;
use App\Models\Course;
use App\Models\Department;
use App\Models\Document;
use App\Models\Enrollment;
use App\Models\Faculty;
use App\Models\Grade;
use App\Models\HostelAllocation;
use App\Models\HostelBed;
use App\Models\Intake;
use App\Models\Invoice;
use App\Models\MedicalBill;
use App\Models\Payment;
use App\Models\Program;
use App\Models\Student;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Support\RegistrationCriteria;

class ReportDatasetCatalog
{
    /**
     * @return list<ReportDataset>
     */
    public static function all(): array
    {
        return [
            self::applications(),
            self::candidateData(),
            self::registrations(),
            self::students(),
            self::campuses(),
            self::colleges(),
            self::departments(),
            self::sessions(),
            self::programmes(),
            self::levels(),
            self::courses(),
            self::intakes(),
            self::enrollments(),
            self::grades(),
            self::attendance(),
            self::invoices(),
            self::payments(),
            self::wallets(),
            self::walletTransactions(),
            self::hostelAllocations(),
            self::hostelBeds(),
            self::clinicVisits(),
            self::medicalBills(),
            self::documents(),
            self::auditLogs(),
        ];
    }

    public static function get(string $key): ?ReportDataset
    {
        foreach (self::all() as $dataset) {
            if ($dataset->key === $key) {
                return $dataset;
            }
        }

        return null;
    }

    private static function applications(): ReportDataset
    {
        return new ReportDataset(
            key: 'applications',
            label: 'Applications',
            category: 'Admissions',
            description: 'Admissions pipeline records (excluding completed registrations).',
            permissions: ['admissions.view'],
            columns: [
                self::col('application_number', 'Application no.', 'string', 'applications.application_number'),
                self::col('jamb_registration', 'JAMB no.', 'string', 'applications.jamb_registration'),
                self::col('applicant_name', 'Applicant', 'string', 'users.name'),
                self::col('email', 'Email', 'string', 'users.email'),
                self::col('stage', 'Stage', 'enum', 'applications.stage', options: [
                    'started', 'submitted', 'screening', 'verification', 'shortlisting',
                    'recommended', 'approved', 'offer_issued', 'awaiting_acceptance_fee',
                    'acceptance_paid', 'matriculated', 'rejected',
                ]),
                self::col('entry_mode', 'Entry mode', 'enum', 'applications.entry_mode', options: ['utme', 'de', 'transfer', 'jupeb', 'pg']),
                self::col('programme', 'Programme', 'string', 'programs.name'),
                self::col('programme_code', 'Programme code', 'string', 'programs.code'),
                self::col('session', 'Session', 'string', 'academic_terms.session_label'),
                self::col('offer_reference', 'Offer ref.', 'string', 'applications.offer_reference'),
                self::col('submitted_at', 'Submitted', 'datetime', 'applications.submitted_at'),
                self::col('created_at', 'Created', 'datetime', 'applications.created_at'),
            ],
            query: function () {
                $query = Application::query();
                RegistrationCriteria::excludeRegisteredApplications($query);

                return $query
                    ->leftJoin('users', 'users.id', '=', 'applications.user_id')
                    ->leftJoin('programs', 'programs.id', '=', 'applications.program_id')
                    ->leftJoin('intakes', 'intakes.id', '=', 'applications.intake_id')
                    ->leftJoin('academic_terms', 'academic_terms.id', '=', 'intakes.academic_term_id');
            },
            countColumn: 'applications.id',
            defaultColumns: ['application_number', 'applicant_name', 'stage', 'entry_mode', 'programme', 'submitted_at'],
            defaultSort: [['field' => 'created_at', 'dir' => 'desc']],
        );
    }

    private static function candidateData(): ReportDataset
    {
        return new ReportDataset(
            key: 'candidate_data',
            label: 'Candidate data',
            category: 'Admissions',
            description: 'Imported JAMB candidate list rows.',
            permissions: ['admissions.import'],
            columns: [
                self::col('rg_num', 'JAMB no.', 'string', 'candidate_data.rg_num'),
                self::col('academic_year', 'Academic year', 'string', 'candidate_data.academic_year'),
                self::col('rg_candname', 'Name', 'string', 'candidate_data.rg_candname'),
                self::col('rg_sex', 'Sex', 'string', 'candidate_data.rg_sex'),
                self::col('state_name', 'State', 'string', 'candidate_data.state_name'),
                self::col('lga_name', 'LGA', 'string', 'candidate_data.lga_name'),
                self::col('co_name', 'Course', 'string', 'candidate_data.co_name'),
                self::col('rg_aggr', 'Aggregate', 'number', 'candidate_data.rg_aggr', aggregatable: true),
                self::col('eng_score', 'English', 'number', 'candidate_data.eng_score', aggregatable: true),
                self::col('created_at', 'Imported', 'datetime', 'candidate_data.created_at'),
            ],
            query: fn () => CandidateData::query(),
            countColumn: 'candidate_data.id',
            defaultColumns: ['rg_num', 'rg_candname', 'academic_year', 'co_name', 'rg_aggr'],
            defaultSort: [['field' => 'created_at', 'dir' => 'desc']],
        );
    }

    private static function registrations(): ReportDataset
    {
        return new ReportDataset(
            key: 'registrations',
            label: 'Registrations',
            category: 'Registrations',
            description: 'Matriculated students with at least 25% tuition paid.',
            permissions: ['registrations.view'],
            columns: [
                self::col('matric_number', 'Matric no.', 'string', 'students.matric_number'),
                self::col('student_number', 'Student no.', 'string', 'students.student_number'),
                self::col('first_name', 'First name', 'string', 'students.first_name'),
                self::col('last_name', 'Last name', 'string', 'students.last_name'),
                self::col('email', 'Email', 'string', 'users.email'),
                self::col('entry_mode', 'Entry mode', 'enum', 'applications.entry_mode', options: ['utme', 'de', 'transfer', 'jupeb', 'pg']),
                self::col('programme', 'Programme', 'string', 'programs.name'),
                self::col('programme_code', 'Programme code', 'string', 'programs.code'),
                self::col('session', 'Session', 'string', 'academic_terms.session_label'),
                self::col('study_level', 'Study level', 'string', 'students.study_level'),
                self::col('current_level', 'Level', 'number', 'students.current_level', aggregatable: true),
                self::col('status', 'Status', 'string', 'students.status'),
                self::col('created_at', 'Registered', 'datetime', 'students.created_at'),
            ],
            query: fn () => RegistrationCriteria::studentsQuery()
                ->leftJoin('users', 'users.id', '=', 'students.user_id')
                ->leftJoin('programs', 'programs.id', '=', 'students.program_id')
                ->leftJoin('applications', 'applications.id', '=', 'students.application_id')
                ->leftJoin('intakes', 'intakes.id', '=', 'applications.intake_id')
                ->leftJoin('academic_terms', 'academic_terms.id', '=', 'intakes.academic_term_id'),
            countColumn: 'students.id',
            defaultColumns: ['matric_number', 'first_name', 'last_name', 'programme', 'entry_mode', 'status'],
            defaultSort: [['field' => 'created_at', 'dir' => 'desc']],
        );
    }

    private static function students(): ReportDataset
    {
        return new ReportDataset(
            key: 'students',
            label: 'Students',
            category: 'Students',
            description: 'Student records (identity numbers excluded).',
            permissions: ['students.view_any'],
            columns: [
                self::col('matric_number', 'Matric no.', 'string', 'students.matric_number'),
                self::col('student_number', 'Student no.', 'string', 'students.student_number'),
                self::col('first_name', 'First name', 'string', 'students.first_name'),
                self::col('middle_name', 'Middle name', 'string', 'students.middle_name'),
                self::col('last_name', 'Last name', 'string', 'students.last_name'),
                self::col('email', 'Email', 'string', 'users.email'),
                self::col('phone', 'Phone', 'string', 'students.phone'),
                self::col('gender', 'Gender', 'string', 'students.gender'),
                self::col('date_of_birth', 'Date of birth', 'date', 'students.date_of_birth'),
                self::col('programme', 'Programme', 'string', 'programs.name'),
                self::col('programme_code', 'Programme code', 'string', 'programs.code'),
                self::col('study_level', 'Study level', 'string', 'students.study_level'),
                self::col('current_level', 'Level', 'number', 'students.current_level', aggregatable: true),
                self::col('status', 'Status', 'string', 'students.status'),
                self::col('created_at', 'Created', 'datetime', 'students.created_at'),
            ],
            query: fn () => Student::query()
                ->leftJoin('users', 'users.id', '=', 'students.user_id')
                ->leftJoin('programs', 'programs.id', '=', 'students.program_id'),
            countColumn: 'students.id',
            defaultColumns: ['matric_number', 'first_name', 'last_name', 'programme', 'current_level', 'status'],
            defaultSort: [['field' => 'created_at', 'dir' => 'desc']],
        );
    }

    private static function campuses(): ReportDataset
    {
        return new ReportDataset(
            key: 'campuses',
            label: 'Campuses',
            category: 'Academic',
            description: 'Campus directory.',
            permissions: ['academic.campuses.manage', 'institution.manage'],
            columns: [
                self::col('name', 'Name', 'string', 'campuses.name'),
                self::col('code', 'Code', 'string', 'campuses.code'),
                self::col('city', 'City', 'string', 'campuses.city'),
                self::col('address', 'Address', 'string', 'campuses.address'),
                self::col('is_active', 'Active', 'boolean', 'campuses.is_active'),
                self::col('created_at', 'Created', 'datetime', 'campuses.created_at'),
            ],
            query: fn () => Campus::query(),
            countColumn: 'campuses.id',
            defaultColumns: ['name', 'code', 'city', 'is_active'],
            defaultSort: [['field' => 'name', 'dir' => 'asc']],
        );
    }

    private static function colleges(): ReportDataset
    {
        return new ReportDataset(
            key: 'colleges',
            label: 'Colleges',
            category: 'Academic',
            description: 'Colleges (faculties) by campus.',
            permissions: ['academic.colleges.manage', 'institution.manage'],
            columns: [
                self::col('name', 'Name', 'string', 'faculties.name'),
                self::col('code', 'Code', 'string', 'faculties.code'),
                self::col('campus', 'Campus', 'string', 'campuses.name'),
                self::col('created_at', 'Created', 'datetime', 'faculties.created_at'),
            ],
            query: fn () => Faculty::query()->leftJoin('campuses', 'campuses.id', '=', 'faculties.campus_id'),
            countColumn: 'faculties.id',
            defaultColumns: ['name', 'code', 'campus'],
            defaultSort: [['field' => 'name', 'dir' => 'asc']],
        );
    }

    private static function departments(): ReportDataset
    {
        return new ReportDataset(
            key: 'departments',
            label: 'Departments',
            category: 'Academic',
            description: 'Academic departments.',
            permissions: ['academic.departments.manage', 'institution.manage'],
            columns: [
                self::col('name', 'Name', 'string', 'departments.name'),
                self::col('code', 'Code', 'string', 'departments.code'),
                self::col('college', 'College', 'string', 'faculties.name'),
                self::col('campus', 'Campus', 'string', 'campuses.name'),
                self::col('created_at', 'Created', 'datetime', 'departments.created_at'),
            ],
            query: fn () => Department::query()
                ->leftJoin('faculties', 'faculties.id', '=', 'departments.faculty_id')
                ->leftJoin('campuses', 'campuses.id', '=', 'faculties.campus_id'),
            countColumn: 'departments.id',
            defaultColumns: ['name', 'code', 'college', 'campus'],
            defaultSort: [['field' => 'name', 'dir' => 'asc']],
        );
    }

    private static function sessions(): ReportDataset
    {
        return new ReportDataset(
            key: 'sessions',
            label: 'Sessions',
            category: 'Academic',
            description: 'Academic sessions.',
            permissions: ['academic.sessions.manage', 'institution.manage'],
            columns: [
                self::col('label', 'Label', 'string', 'academic_sessions.label'),
                self::col('starts_on', 'Starts', 'date', 'academic_sessions.starts_on'),
                self::col('ends_on', 'Ends', 'date', 'academic_sessions.ends_on'),
                self::col('created_at', 'Created', 'datetime', 'academic_sessions.created_at'),
            ],
            query: fn () => AcademicSession::query(),
            countColumn: 'academic_sessions.id',
            defaultColumns: ['label', 'starts_on', 'ends_on'],
            defaultSort: [['field' => 'starts_on', 'dir' => 'desc']],
        );
    }

    private static function programmes(): ReportDataset
    {
        return new ReportDataset(
            key: 'programmes',
            label: 'Programmes',
            category: 'Academic',
            description: 'Programme catalogue.',
            permissions: ['academic.programmes.manage', 'academic.catalog.manage'],
            columns: [
                self::col('name', 'Name', 'string', 'programs.name'),
                self::col('code', 'Code', 'string', 'programs.code'),
                self::col('award_type', 'Award', 'string', 'programs.award_type'),
                self::col('study_level', 'Study level', 'string', 'programs.study_level'),
                self::col('duration_years', 'Duration (years)', 'number', 'programs.duration_years', aggregatable: true),
                self::col('department', 'Department', 'string', 'departments.name'),
                self::col('college', 'College', 'string', 'faculties.name'),
                self::col('is_active', 'Active', 'boolean', 'programs.is_active'),
                self::col('created_at', 'Created', 'datetime', 'programs.created_at'),
            ],
            query: fn () => Program::query()
                ->leftJoin('departments', 'departments.id', '=', 'programs.department_id')
                ->leftJoin('faculties', 'faculties.id', '=', 'departments.faculty_id'),
            countColumn: 'programs.id',
            defaultColumns: ['name', 'code', 'study_level', 'department', 'is_active'],
            defaultSort: [['field' => 'name', 'dir' => 'asc']],
        );
    }

    private static function levels(): ReportDataset
    {
        return new ReportDataset(
            key: 'levels',
            label: 'Academic levels',
            category: 'Academic',
            description: 'Level catalogue.',
            permissions: ['academic.levels.manage', 'institution.manage'],
            columns: [
                self::col('name', 'Name', 'string', 'academic_levels.name'),
                self::col('code', 'Code', 'string', 'academic_levels.code'),
                self::col('study_level', 'Study level', 'string', 'academic_levels.study_level'),
                self::col('sort_order', 'Order', 'number', 'academic_levels.sort_order', aggregatable: true),
                self::col('is_active', 'Active', 'boolean', 'academic_levels.is_active'),
            ],
            query: fn () => AcademicLevel::query(),
            countColumn: 'academic_levels.id',
            defaultColumns: ['name', 'code', 'study_level', 'is_active'],
            defaultSort: [['field' => 'sort_order', 'dir' => 'asc']],
        );
    }

    private static function courses(): ReportDataset
    {
        return new ReportDataset(
            key: 'courses',
            label: 'Courses',
            category: 'Academic',
            description: 'Course catalogue.',
            permissions: ['academic.courses.manage', 'academic.catalog.manage'],
            columns: [
                self::col('code', 'Code', 'string', 'courses.code'),
                self::col('title', 'Title', 'string', 'courses.title'),
                self::col('units', 'Units', 'number', 'courses.units', aggregatable: true),
                self::col('department', 'Department', 'string', 'departments.name'),
                self::col('created_at', 'Created', 'datetime', 'courses.created_at'),
            ],
            query: fn () => Course::query()->leftJoin('departments', 'departments.id', '=', 'courses.department_id'),
            countColumn: 'courses.id',
            defaultColumns: ['code', 'title', 'units', 'department'],
            defaultSort: [['field' => 'code', 'dir' => 'asc']],
        );
    }

    private static function intakes(): ReportDataset
    {
        return new ReportDataset(
            key: 'intakes',
            label: 'Application windows',
            category: 'Academic',
            description: 'Admission intakes / application windows.',
            permissions: ['academic.intakes.manage', 'institution.manage'],
            columns: [
                self::col('name', 'Name', 'string', 'intakes.name'),
                self::col('entry_mode', 'Entry mode', 'string', 'intakes.entry_mode'),
                self::col('session', 'Session', 'string', 'academic_terms.session_label'),
                self::col('opens_on', 'Opens', 'date', 'intakes.opens_on'),
                self::col('closes_on', 'Closes', 'date', 'intakes.closes_on'),
                self::col('is_open', 'Open', 'boolean', 'intakes.is_open'),
                self::col('application_fee_amount', 'Application fee', 'number', 'intakes.application_fee_amount', aggregatable: true),
                self::col('created_at', 'Created', 'datetime', 'intakes.created_at'),
            ],
            query: fn () => Intake::query()->leftJoin('academic_terms', 'academic_terms.id', '=', 'intakes.academic_term_id'),
            countColumn: 'intakes.id',
            defaultColumns: ['name', 'entry_mode', 'session', 'opens_on', 'closes_on', 'is_open'],
            defaultSort: [['field' => 'created_at', 'dir' => 'desc']],
        );
    }

    private static function enrollments(): ReportDataset
    {
        return new ReportDataset(
            key: 'enrollments',
            label: 'Enrollments',
            category: 'Academic',
            description: 'Course enrollments.',
            permissions: ['students.view_any'],
            columns: [
                self::col('matric_number', 'Matric no.', 'string', 'students.matric_number'),
                self::col('student_name', 'Student', 'string', 'users.name'),
                self::col('course_code', 'Course code', 'string', 'courses.code'),
                self::col('course_title', 'Course', 'string', 'courses.title'),
                self::col('section', 'Section', 'string', 'course_offerings.section'),
                self::col('session', 'Session', 'string', 'academic_terms.session_label'),
                self::col('status', 'Status', 'string', 'enrollments.status'),
                self::col('created_at', 'Enrolled', 'datetime', 'enrollments.created_at'),
            ],
            query: fn () => Enrollment::query()
                ->leftJoin('students', 'students.id', '=', 'enrollments.student_id')
                ->leftJoin('users', 'users.id', '=', 'students.user_id')
                ->leftJoin('course_offerings', 'course_offerings.id', '=', 'enrollments.course_offering_id')
                ->leftJoin('courses', 'courses.id', '=', 'course_offerings.course_id')
                ->leftJoin('academic_terms', 'academic_terms.id', '=', 'course_offerings.academic_term_id'),
            countColumn: 'enrollments.id',
            defaultColumns: ['matric_number', 'student_name', 'course_code', 'course_title', 'status'],
            defaultSort: [['field' => 'created_at', 'dir' => 'desc']],
        );
    }

    private static function grades(): ReportDataset
    {
        return new ReportDataset(
            key: 'grades',
            label: 'Grades',
            category: 'Academic',
            description: 'Enrollment grades.',
            permissions: ['students.view_any'],
            columns: [
                self::col('matric_number', 'Matric no.', 'string', 'students.matric_number'),
                self::col('student_name', 'Student', 'string', 'users.name'),
                self::col('course_code', 'Course code', 'string', 'courses.code'),
                self::col('course_title', 'Course', 'string', 'courses.title'),
                self::col('letter', 'Letter', 'string', 'grades.letter'),
                self::col('score', 'Score', 'number', 'grades.score', aggregatable: true),
                self::col('points', 'Points', 'number', 'grades.points', aggregatable: true),
                self::col('status', 'Status', 'string', 'grades.status'),
                self::col('term_name', 'Term', 'string', 'academic_terms.name'),
                self::col('released_at', 'Released', 'datetime', 'grades.released_at'),
                self::col('created_at', 'Recorded', 'datetime', 'grades.created_at'),
            ],
            query: fn () => Grade::query()
                ->leftJoin('enrollments', 'enrollments.id', '=', 'grades.enrollment_id')
                ->leftJoin('students', 'students.id', '=', 'enrollments.student_id')
                ->leftJoin('users', 'users.id', '=', 'students.user_id')
                ->leftJoin('course_offerings', 'course_offerings.id', '=', 'enrollments.course_offering_id')
                ->leftJoin('academic_terms', 'academic_terms.id', '=', 'course_offerings.academic_term_id')
                ->leftJoin('courses', 'courses.id', '=', 'course_offerings.course_id'),
            countColumn: 'grades.id',
            defaultColumns: ['matric_number', 'student_name', 'course_code', 'letter', 'score', 'status'],
            defaultSort: [['field' => 'created_at', 'dir' => 'desc']],
        );
    }

    private static function attendance(): ReportDataset
    {
        return new ReportDataset(
            key: 'attendance',
            label: 'Attendance',
            category: 'Academic',
            description: 'Class attendance records.',
            permissions: ['students.view_any'],
            columns: [
                self::col('matric_number', 'Matric no.', 'string', 'students.matric_number'),
                self::col('student_name', 'Student', 'string', 'users.name'),
                self::col('course_code', 'Course code', 'string', 'courses.code'),
                self::col('attended_on', 'Date', 'date', 'attendance.attended_on'),
                self::col('status', 'Status', 'string', 'attendance.status'),
            ],
            query: fn () => Attendance::query()
                ->leftJoin('students', 'students.id', '=', 'attendance.student_id')
                ->leftJoin('users', 'users.id', '=', 'students.user_id')
                ->leftJoin('course_offerings', 'course_offerings.id', '=', 'attendance.course_offering_id')
                ->leftJoin('courses', 'courses.id', '=', 'course_offerings.course_id'),
            countColumn: 'attendance.id',
            defaultColumns: ['matric_number', 'student_name', 'course_code', 'attended_on', 'status'],
            defaultSort: [['field' => 'attended_on', 'dir' => 'desc']],
        );
    }

    private static function invoices(): ReportDataset
    {
        return new ReportDataset(
            key: 'invoices',
            label: 'Invoices',
            category: 'Finance',
            description: 'Fee invoices and balances.',
            permissions: ['finance.invoices.manage'],
            columns: [
                self::col('number', 'Number', 'string', 'invoices.number'),
                self::col('payer', 'Payer', 'string', 'users.name'),
                self::col('matric_number', 'Matric no.', 'string', 'students.matric_number'),
                self::col('category', 'Category', 'string', 'invoices.category'),
                self::col('amount', 'Amount', 'number', 'invoices.amount', aggregatable: true),
                self::col('balance', 'Balance', 'number', 'invoices.balance', aggregatable: true),
                self::col('rebate_total', 'Rebate', 'number', 'invoices.rebate_total', aggregatable: true),
                self::col('status', 'Status', 'enum', 'invoices.status', options: ['unpaid', 'partial', 'paid', 'cancelled']),
                self::col('programme', 'Programme', 'string', 'programs.name'),
                self::col('created_at', 'Created', 'datetime', 'invoices.created_at'),
            ],
            query: fn () => Invoice::query()
                ->leftJoin('users', 'users.id', '=', 'invoices.user_id')
                ->leftJoin('students', 'students.id', '=', 'invoices.student_id')
                ->leftJoin('programs', 'programs.id', '=', 'students.program_id'),
            countColumn: 'invoices.id',
            defaultColumns: ['number', 'payer', 'category', 'amount', 'balance', 'status'],
            defaultSort: [['field' => 'created_at', 'dir' => 'desc']],
        );
    }

    private static function payments(): ReportDataset
    {
        return new ReportDataset(
            key: 'payments',
            label: 'Payments',
            category: 'Finance',
            description: 'Recorded payments.',
            permissions: ['finance.invoices.manage'],
            columns: [
                self::col('receipt_no', 'Receipt', 'string', 'payments.receipt_no'),
                self::col('reference', 'Reference', 'string', 'payments.reference'),
                self::col('payer', 'Payer', 'string', 'users.name'),
                self::col('invoice_number', 'Invoice', 'string', 'invoices.number'),
                self::col('method', 'Method', 'string', 'payments.method'),
                self::col('amount', 'Amount', 'number', 'payments.amount', aggregatable: true),
                self::col('status', 'Status', 'enum', 'payments.status', options: ['pending', 'successful', 'failed']),
                self::col('purpose', 'Purpose', 'string', 'payments.purpose'),
                self::col('created_at', 'Paid', 'datetime', 'payments.created_at'),
            ],
            query: fn () => Payment::query()
                ->leftJoin('users', 'users.id', '=', 'payments.user_id')
                ->leftJoin('invoices', 'invoices.id', '=', 'payments.invoice_id'),
            countColumn: 'payments.id',
            defaultColumns: ['receipt_no', 'payer', 'amount', 'method', 'status', 'created_at'],
            defaultSort: [['field' => 'created_at', 'dir' => 'desc']],
        );
    }

    private static function wallets(): ReportDataset
    {
        return new ReportDataset(
            key: 'wallets',
            label: 'Wallets',
            category: 'Finance',
            description: 'Student wallet balances.',
            permissions: ['wallet.view_any'],
            columns: [
                self::col('matric_number', 'Matric no.', 'string', 'students.matric_number'),
                self::col('student_name', 'Student', 'string', 'users.name'),
                self::col('balance', 'Balance', 'number', 'wallets.balance', aggregatable: true),
                self::col('status', 'Status', 'string', 'wallets.status'),
                self::col('created_at', 'Created', 'datetime', 'wallets.created_at'),
            ],
            query: fn () => Wallet::query()
                ->leftJoin('students', 'students.id', '=', 'wallets.student_id')
                ->leftJoin('users', 'users.id', '=', 'students.user_id'),
            countColumn: 'wallets.id',
            defaultColumns: ['matric_number', 'student_name', 'balance', 'status'],
            defaultSort: [['field' => 'balance', 'dir' => 'desc']],
        );
    }

    private static function walletTransactions(): ReportDataset
    {
        return new ReportDataset(
            key: 'wallet_transactions',
            label: 'Wallet transactions',
            category: 'Finance',
            description: 'Wallet credits and debits.',
            permissions: ['wallet.view_any'],
            columns: [
                self::col('matric_number', 'Matric no.', 'string', 'students.matric_number'),
                self::col('student_name', 'Student', 'string', 'users.name'),
                self::col('type', 'Type', 'string', 'wallet_transactions.type'),
                self::col('amount', 'Amount', 'number', 'wallet_transactions.amount', aggregatable: true),
                self::col('reference', 'Reference', 'string', 'wallet_transactions.reference'),
                self::col('source_module', 'Source', 'string', 'wallet_transactions.source_module'),
                self::col('description', 'Description', 'string', 'wallet_transactions.description'),
                self::col('created_at', 'When', 'datetime', 'wallet_transactions.created_at'),
            ],
            query: fn () => WalletTransaction::query()
                ->leftJoin('wallets', 'wallets.id', '=', 'wallet_transactions.wallet_id')
                ->leftJoin('students', 'students.id', '=', 'wallets.student_id')
                ->leftJoin('users', 'users.id', '=', 'students.user_id'),
            countColumn: 'wallet_transactions.id',
            defaultColumns: ['matric_number', 'student_name', 'type', 'amount', 'created_at'],
            defaultSort: [['field' => 'created_at', 'dir' => 'desc']],
        );
    }

    private static function hostelAllocations(): ReportDataset
    {
        return new ReportDataset(
            key: 'hostel_allocations',
            label: 'Hostel allocations',
            category: 'Hostel',
            description: 'Bed allocations by term.',
            permissions: ['hostel.view'],
            columns: [
                self::col('matric_number', 'Matric no.', 'string', 'students.matric_number'),
                self::col('student_name', 'Student', 'string', 'users.name'),
                self::col('hostel', 'Hostel', 'string', 'hostels.name'),
                self::col('block', 'Block', 'string', 'hostel_blocks.name'),
                self::col('room', 'Room', 'string', 'hostel_rooms.number'),
                self::col('bed', 'Bed', 'string', 'hostel_beds.label'),
                self::col('session', 'Session', 'string', 'academic_terms.session_label'),
                self::col('status', 'Status', 'string', 'hostel_allocations.status'),
                self::col('allocated_at', 'Allocated', 'datetime', 'hostel_allocations.allocated_at'),
                self::col('vacated_at', 'Vacated', 'datetime', 'hostel_allocations.vacated_at'),
            ],
            query: fn () => HostelAllocation::query()
                ->leftJoin('students', 'students.id', '=', 'hostel_allocations.student_id')
                ->leftJoin('users', 'users.id', '=', 'students.user_id')
                ->leftJoin('hostel_beds', 'hostel_beds.id', '=', 'hostel_allocations.hostel_bed_id')
                ->leftJoin('hostel_rooms', 'hostel_rooms.id', '=', 'hostel_beds.hostel_room_id')
                ->leftJoin('hostel_blocks', 'hostel_blocks.id', '=', 'hostel_rooms.hostel_block_id')
                ->leftJoin('hostels', 'hostels.id', '=', 'hostel_blocks.hostel_id')
                ->leftJoin('academic_terms', 'academic_terms.id', '=', 'hostel_allocations.academic_term_id'),
            countColumn: 'hostel_allocations.id',
            defaultColumns: ['matric_number', 'student_name', 'hostel', 'room', 'bed', 'status'],
            defaultSort: [['field' => 'allocated_at', 'dir' => 'desc']],
        );
    }

    private static function hostelBeds(): ReportDataset
    {
        return new ReportDataset(
            key: 'hostel_beds',
            label: 'Hostel beds',
            category: 'Hostel',
            description: 'Bed inventory and occupancy.',
            permissions: ['hostel.view'],
            columns: [
                self::col('hostel', 'Hostel', 'string', 'hostels.name'),
                self::col('block', 'Block', 'string', 'hostel_blocks.name'),
                self::col('room', 'Room', 'string', 'hostel_rooms.number'),
                self::col('label', 'Bed', 'string', 'hostel_beds.label'),
                self::col('status', 'Status', 'string', 'hostel_beds.status'),
                self::col('gender', 'Gender', 'string', 'hostel_rooms.gender'),
                self::col('created_at', 'Created', 'datetime', 'hostel_beds.created_at'),
            ],
            query: fn () => HostelBed::query()
                ->leftJoin('hostel_rooms', 'hostel_rooms.id', '=', 'hostel_beds.hostel_room_id')
                ->leftJoin('hostel_blocks', 'hostel_blocks.id', '=', 'hostel_rooms.hostel_block_id')
                ->leftJoin('hostels', 'hostels.id', '=', 'hostel_blocks.hostel_id'),
            countColumn: 'hostel_beds.id',
            defaultColumns: ['hostel', 'block', 'room', 'label', 'status'],
            defaultSort: [['field' => 'hostel', 'dir' => 'asc']],
        );
    }

    private static function clinicVisits(): ReportDataset
    {
        return new ReportDataset(
            key: 'clinic_visits',
            label: 'Clinic visits',
            category: 'Clinic',
            description: 'Clinic visits (internal notes excluded).',
            permissions: ['medical.view_any'],
            columns: [
                self::col('matric_number', 'Matric no.', 'string', 'students.matric_number'),
                self::col('student_name', 'Student', 'string', 'users.name'),
                self::col('status', 'Status', 'string', 'clinic_visits.status'),
                self::col('visit_type', 'Type', 'string', 'clinic_visits.visit_type'),
                self::col('visited_on', 'Visited', 'date', 'clinic_visits.visited_on'),
                self::col('triage_priority', 'Priority', 'string', 'clinic_visits.triage_priority'),
                self::col('complaint', 'Complaint', 'string', 'clinic_visits.complaint'),
                self::col('diagnosis', 'Diagnosis', 'string', 'clinic_visits.diagnosis'),
                self::col('disposition', 'Disposition', 'string', 'clinic_visits.disposition'),
                self::col('created_at', 'Created', 'datetime', 'clinic_visits.created_at'),
            ],
            query: fn () => ClinicVisit::query()
                ->leftJoin('students', 'students.id', '=', 'clinic_visits.student_id')
                ->leftJoin('users', 'users.id', '=', 'students.user_id'),
            countColumn: 'clinic_visits.id',
            defaultColumns: ['matric_number', 'student_name', 'visited_on', 'visit_type', 'status', 'diagnosis'],
            defaultSort: [['field' => 'visited_on', 'dir' => 'desc']],
        );
    }

    private static function medicalBills(): ReportDataset
    {
        return new ReportDataset(
            key: 'medical_bills',
            label: 'Medical bills',
            category: 'Clinic',
            description: 'Clinic billing.',
            permissions: ['medical.view_any'],
            columns: [
                self::col('matric_number', 'Matric no.', 'string', 'students.matric_number'),
                self::col('student_name', 'Student', 'string', 'users.name'),
                self::col('invoice_number', 'Invoice', 'string', 'invoices.number'),
                self::col('amount', 'Amount', 'number', 'medical_bills.amount', aggregatable: true),
                self::col('gross_amount', 'Gross', 'number', 'medical_bills.gross_amount', aggregatable: true),
                self::col('status', 'Status', 'string', 'medical_bills.status'),
                self::col('visited_on', 'Visit date', 'date', 'clinic_visits.visited_on'),
                self::col('created_at', 'Created', 'datetime', 'medical_bills.created_at'),
            ],
            query: fn () => MedicalBill::query()
                ->leftJoin('clinic_visits', 'clinic_visits.id', '=', 'medical_bills.clinic_visit_id')
                ->leftJoin('students', 'students.id', '=', 'clinic_visits.student_id')
                ->leftJoin('users', 'users.id', '=', 'students.user_id')
                ->leftJoin('invoices', 'invoices.id', '=', 'medical_bills.invoice_id'),
            countColumn: 'medical_bills.id',
            defaultColumns: ['matric_number', 'student_name', 'amount', 'status', 'visited_on'],
            defaultSort: [['field' => 'created_at', 'dir' => 'desc']],
        );
    }

    private static function documents(): ReportDataset
    {
        return new ReportDataset(
            key: 'documents',
            label: 'Documents',
            category: 'Documents',
            description: 'Issued student documents.',
            permissions: ['documents.issue'],
            columns: [
                self::col('matric_number', 'Matric no.', 'string', 'students.matric_number'),
                self::col('student_name', 'Student', 'string', 'users.name'),
                self::col('type', 'Type', 'string', 'documents.type'),
                self::col('title', 'Title', 'string', 'documents.title'),
                self::col('status', 'Status', 'string', 'documents.status'),
                self::col('created_at', 'Issued', 'datetime', 'documents.created_at'),
            ],
            query: fn () => Document::query()
                ->leftJoin('students', 'students.id', '=', 'documents.student_id')
                ->leftJoin('users', 'users.id', '=', 'students.user_id'),
            countColumn: 'documents.id',
            defaultColumns: ['matric_number', 'student_name', 'type', 'title', 'status', 'created_at'],
            defaultSort: [['field' => 'created_at', 'dir' => 'desc']],
        );
    }

    private static function auditLogs(): ReportDataset
    {
        return new ReportDataset(
            key: 'audit_logs',
            label: 'Audit trail',
            category: 'Audit',
            description: 'Staff activity trail (no before/after payloads).',
            permissions: ['audit.view'],
            columns: [
                self::col('actor_name', 'Actor', 'string', 'audit_logs.actor_name'),
                self::col('actor_email', 'Email', 'string', 'audit_logs.actor_email'),
                self::col('action', 'Action', 'string', 'audit_logs.action'),
                self::col('module', 'Module', 'string', 'audit_logs.module'),
                self::col('summary', 'Summary', 'string', 'audit_logs.summary'),
                self::col('path', 'Path', 'string', 'audit_logs.path'),
                self::col('ip', 'IP', 'string', 'audit_logs.ip'),
                self::col('occurred_at', 'When', 'datetime', 'audit_logs.occurred_at'),
            ],
            query: fn () => AuditLog::query(),
            countColumn: 'audit_logs.id',
            defaultColumns: ['occurred_at', 'actor_name', 'action', 'module', 'summary'],
            defaultSort: [['field' => 'occurred_at', 'dir' => 'desc']],
        );
    }

    /**
     * @param  list<string>|null  $options
     */
    private static function col(
        string $key,
        string $label,
        string $type,
        string $sql,
        bool $aggregatable = false,
        ?array $options = null,
    ): ReportColumn {
        return ReportColumn::make($key, $label, $type, $sql, $aggregatable, $options);
    }
}
