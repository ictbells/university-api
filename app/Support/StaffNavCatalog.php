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
            ['key' => 'registrations-undergraduate', 'section' => 'Registrations', 'label' => 'Undergraduate', 'perm' => 'registrations.view'],
            ['key' => 'registrations-jupeb', 'section' => 'Registrations', 'label' => 'JUPEB', 'perm' => 'registrations.view'],
            ['key' => 'registrations-postgraduate', 'section' => 'Registrations', 'label' => 'Postgraduate', 'perm' => 'registrations.view'],
            ['key' => 'campuses', 'section' => 'Academic', 'label' => 'Campuses', 'perm' => 'academic.campuses.manage'],
            ['key' => 'colleges', 'section' => 'Academic', 'label' => 'Colleges', 'perm' => 'academic.colleges.manage'],
            ['key' => 'departments', 'section' => 'Academic', 'label' => 'Departments', 'perm' => 'academic.departments.manage'],
            ['key' => 'sessions', 'section' => 'Academic', 'label' => 'Sessions', 'perm' => 'academic.sessions.manage'],
            ['key' => 'graduation', 'section' => 'Academic', 'label' => 'Graduation', 'perm' => 'academic.graduate'],
            ['key' => 'programmes', 'section' => 'Academic', 'label' => 'Programmes', 'perm' => 'academic.programmes.manage'],
            ['key' => 'levels', 'section' => 'Academic', 'label' => 'Levels', 'perm' => 'academic.levels.manage'],
            ['key' => 'courses', 'section' => 'Academic', 'label' => 'Courses', 'perm' => 'academic.courses.manage'],
            ['key' => 'offerings', 'section' => 'Academic', 'label' => 'Offerings', 'perm' => 'academic.offerings.manage'],
            ['key' => 'course-registration', 'section' => 'Academic', 'label' => 'Course registration', 'perm' => 'academic.enrollments.manage'],
            ['key' => 'exam-clearance', 'section' => 'Academic', 'label' => 'Exam clearance', 'perm' => 'exam_clearance.view'],
            ['key' => 'unit-limits', 'section' => 'Academic', 'label' => 'Unit limits', 'perm' => 'academic.enrollments.manage'],
            ['key' => 'registration-extensions', 'section' => 'Academic', 'label' => 'Registration extensions', 'perm' => 'academic.extensions.review'],
            ['key' => 'intakes', 'section' => 'Academic', 'label' => 'Application windows', 'perm' => 'academic.intakes.manage'],
            ['key' => 'candidate-data', 'section' => 'Academic', 'label' => 'Candidate data', 'perm' => 'admissions.import'],
            ['key' => 'import-applicants', 'section' => 'Academic', 'label' => 'Import applicants', 'perm' => 'admissions.import'],
            ['key' => 'import-students', 'section' => 'Academic', 'label' => 'Import students', 'perm' => 'students.import'],
            ['key' => 'olevel', 'section' => 'Academic', 'label' => "O'level", 'perm' => 'academic.olevel.manage'],
            ['key' => 'pg', 'section' => 'Academic', 'label' => 'PG research', 'perm' => 'pg.view'],
            ['key' => 'finance', 'section' => 'Services', 'label' => 'Fees & payments', 'perm' => 'finance.invoices.manage'],
            ['key' => 'import-invoices', 'section' => 'Services', 'label' => 'Import invoices', 'perm' => 'finance.invoices.manage'],
            ['key' => 'import-wallet', 'section' => 'Services', 'label' => 'Import wallet history', 'perm' => 'finance.invoices.manage'],
            ['key' => 'medical', 'section' => 'Services', 'label' => 'Clinic', 'perm' => 'medical.view_any'],
            ['key' => 'hostel', 'section' => 'Services', 'label' => 'Hostel', 'perm' => 'hostel.view'],
            ['key' => 'documents', 'section' => 'Services', 'label' => 'Documents', 'perm' => 'documents.issue'],
            ['key' => 'users', 'section' => 'Administration', 'label' => 'Users', 'perm' => 'users.manage'],
            ['key' => 'roles', 'section' => 'Administration', 'label' => 'Roles', 'perm' => 'roles.manage'],
            ['key' => 'permissions', 'section' => 'Administration', 'label' => 'Permissions', 'perm' => 'roles.manage'],
            ['key' => 'office-setup', 'section' => 'Administration', 'label' => 'Office setup', 'perm' => 'institution.manage'],
            ['key' => 'institution', 'section' => 'Administration', 'label' => 'Institution', 'perm' => 'institution.manage'],
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
