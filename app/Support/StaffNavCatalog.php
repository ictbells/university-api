<?php

namespace App\Support;

class StaffNavCatalog
{
    public static function all(): array
    {
        return [
            ['key' => 'home', 'section' => 'Overview', 'label' => 'Home', 'perm' => null],
            ['key' => 'approvals', 'section' => 'Overview', 'label' => 'Approvals', 'perm' => null],
            ['key' => 'students', 'section' => 'Overview', 'label' => 'Students', 'perm' => 'students.view_any'],
            ['key' => 'admissions-undergraduate', 'section' => 'Applications', 'label' => 'Undergraduate', 'perm' => 'admissions.view'],
            ['key' => 'admissions-jupeb', 'section' => 'Applications', 'label' => 'JUPEB', 'perm' => 'admissions.view'],
            ['key' => 'admissions-postgraduate', 'section' => 'Applications', 'label' => 'Postgraduate', 'perm' => 'admissions.view'],
            ['key' => 'admissions-clearance-undergraduate', 'section' => 'Applications', 'label' => 'Undergraduate clearance', 'perm' => 'admissions.clear'],
            ['key' => 'admissions-clearance-jupeb', 'section' => 'Applications', 'label' => 'JUPEB clearance', 'perm' => 'admissions.clear'],
            ['key' => 'admissions-clearance-postgraduate', 'section' => 'Applications', 'label' => 'Postgraduate clearance', 'perm' => 'admissions.clear'],
            ['key' => 'admission-guide', 'section' => 'Applications', 'label' => 'Admission guide', 'perm' => 'admissions.guide'],
            ['key' => 'registrations-undergraduate', 'section' => 'Registrations', 'label' => 'Undergraduate', 'perm' => 'registrations.view'],
            ['key' => 'registrations-jupeb', 'section' => 'Registrations', 'label' => 'JUPEB', 'perm' => 'registrations.view'],
            ['key' => 'registrations-postgraduate', 'section' => 'Registrations', 'label' => 'Postgraduate', 'perm' => 'registrations.view'],
            ['key' => 'campuses', 'section' => 'Academic', 'label' => 'Campuses', 'perm' => 'academic.campuses.manage'],
            ['key' => 'colleges', 'section' => 'Academic', 'label' => 'Colleges', 'perm' => 'academic.colleges.manage'],
            ['key' => 'departments', 'section' => 'Academic', 'label' => 'Departments', 'perm' => 'academic.departments.manage'],
            ['key' => 'sessions', 'section' => 'Academic', 'label' => 'Academic Sessions', 'perm' => 'academic.sessions.manage'],
            ['key' => 'levels', 'section' => 'Academic', 'label' => 'Levels', 'perm' => 'academic.levels.manage'],
            ['key' => 'graduation', 'section' => 'Academic', 'label' => 'Graduation', 'perm' => 'academic.graduate'],
            ['key' => 'import-students', 'section' => 'Academic', 'label' => 'Import students', 'perm' => 'students.import'],
            ['key' => 'jupeb-matric', 'section' => 'Academic', 'label' => 'JUPEB matric numbers', 'perm' => 'admissions.matriculate'],
            ['key' => 'intakes', 'section' => 'Academic', 'label' => 'Application sessions', 'perm' => 'academic.intakes.manage'],
            ['key' => 'programmes', 'section' => 'Academic', 'label' => 'Programmes', 'perm' => 'academic.programmes.manage'],
            ['key' => 'olevel', 'section' => 'Academic', 'label' => "O'level", 'perm' => 'academic.olevel.manage'],
            ['key' => 'candidate-data', 'section' => 'Academic', 'label' => 'Candidate data', 'perm' => 'admissions.import'],
            ['key' => 'import-applicants', 'section' => 'Academic', 'label' => 'Import applicants', 'perm' => 'admissions.import'],
            ['key' => 'courses', 'section' => 'Academic', 'label' => 'Course catalog', 'perm' => 'academic.courses.manage'],
            ['key' => 'programme-courses', 'section' => 'Academic', 'label' => 'Programme courses', 'perm' => 'academic.programmes.manage'],
            ['key' => 'offerings', 'section' => 'Academic', 'label' => 'Offerings', 'perm' => 'academic.offerings.manage'],
            ['key' => 'course-registration', 'section' => 'Academic', 'label' => 'Course registration', 'perm' => 'academic.enrollments.manage'],
            ['key' => 'exam-clearance', 'section' => 'Academic', 'label' => 'Exam clearance', 'perm' => 'exam_clearance.view'],
            ['key' => 'unit-limits', 'section' => 'Academic', 'label' => 'Unit limits', 'perm' => 'academic.enrollments.manage'],
            ['key' => 'registration-extensions', 'section' => 'Academic', 'label' => 'Registration extensions', 'perm' => 'academic.extensions.review'],
            ['key' => 'results', 'section' => 'Academic', 'label' => 'Results dashboard', 'perm' => 'results.read'],
            ['key' => 'results-students', 'section' => 'Academic', 'label' => 'Result entry', 'perm' => 'results.read'],
            ['key' => 'results-import', 'section' => 'Academic', 'label' => 'Upload Score', 'perm' => 'results.import'],
            ['key' => 'results-department', 'section' => 'Academic', 'label' => 'Department', 'perm' => 'results.department_submit'],
            ['key' => 'results-college', 'section' => 'Academic', 'label' => 'College', 'perm' => 'results.submit'],
            ['key' => 'results-approvals', 'section' => 'Academic', 'label' => 'Committee of Deans', 'perm' => 'results.faculty_approve'],
            ['key' => 'results-board', 'section' => 'Academic', 'label' => 'Senate', 'perm' => 'results.board'],
            ['key' => 'results-release', 'section' => 'Academic', 'label' => 'Release results', 'perm' => 'results.release'],
            ['key' => 'results-grading-scale', 'section' => 'Academic', 'label' => 'Grading scale', 'perm' => 'scales.manage'],
            ['key' => 'finance', 'section' => 'Services', 'label' => 'Fees & payments', 'perm' => 'finance.invoices.manage'],
            ['key' => 'import-invoices', 'section' => 'Services', 'label' => 'Import invoices', 'perm' => 'finance.invoices.manage'],
            ['key' => 'import-wallet', 'section' => 'Services', 'label' => 'Import wallet history', 'perm' => 'finance.invoices.manage'],
            ['key' => 'medical', 'section' => 'Services', 'label' => 'Clinic', 'perm' => 'medical.view_any'],
            ['key' => 'hostel', 'section' => 'Services', 'label' => 'Hostel', 'perm' => 'hostel.view'],
            ['key' => 'documents', 'section' => 'Services', 'label' => 'Documents', 'perm' => 'documents.issue'],
            ['key' => 'transcript-undergraduate', 'section' => 'Services', 'label' => 'Undergraduate transcripts', 'perm' => 'transcripts.view'],
            ['key' => 'transcript-jupeb', 'section' => 'Services', 'label' => 'JUPEB transcripts', 'perm' => 'transcripts.view'],
            ['key' => 'transcript-postgraduate', 'section' => 'Services', 'label' => 'Postgraduate transcripts', 'perm' => 'transcripts.view'],
            ['key' => 'users', 'section' => 'Administration', 'label' => 'Users', 'perm' => 'users.manage'],
            ['key' => 'roles', 'section' => 'Administration', 'label' => 'Roles', 'perm' => 'roles.manage'],
            ['key' => 'permissions', 'section' => 'Administration', 'label' => 'Permissions', 'perm' => 'roles.manage'],
            ['key' => 'office-setup', 'section' => 'Administration', 'label' => 'Office setup', 'perm' => 'institution.manage'],
            ['key' => 'application-settings', 'section' => 'System', 'label' => 'Application settings', 'perm' => 'settings.manage'],
            ['key' => 'resources', 'section' => 'System', 'label' => 'Resources', 'perm' => 'resources.view'],
            ['key' => 'audit', 'section' => 'System', 'label' => 'Audit', 'perm' => 'audit.view'],
            ['key' => 'reports', 'section' => 'System', 'label' => 'Reports', 'perm' => 'reports.view'],
            ['key' => 'announcements', 'section' => 'System', 'label' => 'Announcements', 'perm' => null],
            ['key' => 'integrations', 'section' => 'System', 'label' => 'Integrations', 'perm' => 'integrations.view'],
        ];
    }

    public static function keys(): array
    {
        return array_column(self::all(), 'key');
    }

    public static function isValidKey(string $key): bool
    {
        return in_array($key, self::keys(), true);
    }
}
