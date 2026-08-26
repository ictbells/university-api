<?php

namespace App\Support;

class OfficeApprovalCatalog
{
    public const MUTATION_CREATE = 'create';

    public const MUTATION_UPDATE = 'update';

    public const MUTATION_DELETE = 'delete';

    /** Bulk/upsert POSTs wait if Create or Update is required on the link. */
    public const MUTATION_UPSERT = 'upsert';

    /**
     * @return array<string, array{nav_key: string, label: string, mutation: string}>
     */
    public static function actions(): array
    {
        $actions = [
            'hostel.allocate' => ['nav_key' => 'hostel', 'label' => 'Allocate hostel bed', 'mutation' => self::MUTATION_UPDATE],
            'hostel.auto_allocate' => ['nav_key' => 'hostel', 'label' => 'Auto-allocate hostel bed', 'mutation' => self::MUTATION_UPDATE],
            'hostel.approve' => ['nav_key' => 'hostel', 'label' => 'Approve hostel allocation', 'mutation' => self::MUTATION_UPDATE],
            'hostel.reject' => ['nav_key' => 'hostel', 'label' => 'Reject hostel allocation', 'mutation' => self::MUTATION_UPDATE],
            'hostel.vacate' => ['nav_key' => 'hostel', 'label' => 'Vacate hostel bed', 'mutation' => self::MUTATION_UPDATE],
            'hostel.store' => ['nav_key' => 'hostel', 'label' => 'Create hostel', 'mutation' => self::MUTATION_CREATE],
            'hostel.update' => ['nav_key' => 'hostel', 'label' => 'Update hostel', 'mutation' => self::MUTATION_UPDATE],
            'hostel.destroy' => ['nav_key' => 'hostel', 'label' => 'Delete hostel', 'mutation' => self::MUTATION_DELETE],
            'hostel.sync_level_windows' => ['nav_key' => 'hostel', 'label' => 'Update hostel level windows', 'mutation' => self::MUTATION_UPDATE],
            'hostel.store_block' => ['nav_key' => 'hostel', 'label' => 'Create hostel block', 'mutation' => self::MUTATION_CREATE],
            'hostel.update_block' => ['nav_key' => 'hostel', 'label' => 'Update hostel block', 'mutation' => self::MUTATION_UPDATE],
            'hostel.destroy_block' => ['nav_key' => 'hostel', 'label' => 'Delete hostel block', 'mutation' => self::MUTATION_DELETE],
            'hostel.store_room' => ['nav_key' => 'hostel', 'label' => 'Create hostel room', 'mutation' => self::MUTATION_CREATE],
            'hostel.update_room' => ['nav_key' => 'hostel', 'label' => 'Update hostel room', 'mutation' => self::MUTATION_UPDATE],
            'hostel.destroy_room' => ['nav_key' => 'hostel', 'label' => 'Delete hostel room', 'mutation' => self::MUTATION_DELETE],
            'hostel.reserve_room' => ['nav_key' => 'hostel', 'label' => 'Reserve hostel room', 'mutation' => self::MUTATION_UPDATE],
            'hostel.release_room' => ['nav_key' => 'hostel', 'label' => 'Release hostel room', 'mutation' => self::MUTATION_UPDATE],
            'hostel.disable_room' => ['nav_key' => 'hostel', 'label' => 'Disable hostel room', 'mutation' => self::MUTATION_UPDATE],
            'hostel.enable_room' => ['nav_key' => 'hostel', 'label' => 'Enable hostel room', 'mutation' => self::MUTATION_UPDATE],
            'admissions.transition' => ['nav_key' => 'admissions-undergraduate', 'label' => 'Advance application', 'mutation' => self::MUTATION_UPDATE],
            'admissions.revert' => ['nav_key' => 'admissions-undergraduate', 'label' => 'Revert last application decision', 'mutation' => self::MUTATION_UPDATE],
            'admissions.staff_update' => ['nav_key' => 'admissions-undergraduate', 'label' => 'Update application file', 'mutation' => self::MUTATION_UPDATE],
            'admissions.update_acceptance_fee' => ['nav_key' => 'admissions-undergraduate', 'label' => 'Update acceptance fee', 'mutation' => self::MUTATION_UPDATE],
            'medical.approve_appointment' => ['nav_key' => 'medical', 'label' => 'Approve clinic appointment', 'mutation' => self::MUTATION_UPDATE],
            'medical.reject_appointment' => ['nav_key' => 'medical', 'label' => 'Reject clinic appointment', 'mutation' => self::MUTATION_UPDATE],
            'medical.finalize_bill' => ['nav_key' => 'medical', 'label' => 'Finalize clinic bill', 'mutation' => self::MUTATION_UPDATE],
            'medical.update_profile' => ['nav_key' => 'medical', 'label' => 'Update medical profile', 'mutation' => self::MUTATION_UPDATE],
            'finance.store_fee' => ['nav_key' => 'finance', 'label' => 'Create fee item', 'mutation' => self::MUTATION_CREATE],
            'finance.update_fee' => ['nav_key' => 'finance', 'label' => 'Update fee item', 'mutation' => self::MUTATION_UPDATE],
            'finance.destroy_fee' => ['nav_key' => 'finance', 'label' => 'Delete fee item', 'mutation' => self::MUTATION_DELETE],
            'finance.store_fee_category' => ['nav_key' => 'finance', 'label' => 'Create fee category', 'mutation' => self::MUTATION_CREATE],
            'finance.update_fee_category' => ['nav_key' => 'finance', 'label' => 'Update fee category', 'mutation' => self::MUTATION_UPDATE],
            'finance.destroy_fee_category' => ['nav_key' => 'finance', 'label' => 'Delete fee category', 'mutation' => self::MUTATION_DELETE],
            'finance.generate_invoice' => ['nav_key' => 'finance', 'label' => 'Generate invoice', 'mutation' => self::MUTATION_CREATE],
            'finance.disable_invoice' => ['nav_key' => 'finance', 'label' => 'Disable invoice', 'mutation' => self::MUTATION_UPDATE],
            'finance.enable_invoice' => ['nav_key' => 'finance', 'label' => 'Enable invoice', 'mutation' => self::MUTATION_UPDATE],
            'finance.store_rebate_type' => ['nav_key' => 'finance', 'label' => 'Create rebate type', 'mutation' => self::MUTATION_CREATE],
            'finance.update_rebate_type' => ['nav_key' => 'finance', 'label' => 'Update rebate type', 'mutation' => self::MUTATION_UPDATE],
            'finance.destroy_rebate_type' => ['nav_key' => 'finance', 'label' => 'Delete rebate type', 'mutation' => self::MUTATION_DELETE],
            'finance.apply_rebate' => ['nav_key' => 'finance', 'label' => 'Apply invoice rebate', 'mutation' => self::MUTATION_UPDATE],
            'finance.reverse_rebate' => ['nav_key' => 'finance', 'label' => 'Reverse invoice rebate', 'mutation' => self::MUTATION_UPDATE],
            'finance.store_programme_fee' => ['nav_key' => 'finance', 'label' => 'Create programme fee', 'mutation' => self::MUTATION_CREATE],
            'finance.update_programme_fee' => ['nav_key' => 'finance', 'label' => 'Update programme fee', 'mutation' => self::MUTATION_UPDATE],
            'finance.destroy_programme_fee' => ['nav_key' => 'finance', 'label' => 'Delete programme fee', 'mutation' => self::MUTATION_DELETE],
            'finance.bulk_programme_fees' => ['nav_key' => 'finance', 'label' => 'Bulk save programme fees', 'mutation' => self::MUTATION_UPSERT],
            'finance.copy_programme_fees' => ['nav_key' => 'finance', 'label' => 'Copy programme fee schedule', 'mutation' => self::MUTATION_UPSERT],
            'documents.issue' => ['nav_key' => 'documents', 'label' => 'Issue document', 'mutation' => self::MUTATION_CREATE],
            'academic.staff_register' => ['nav_key' => 'course-registration', 'label' => 'Staff course registration', 'mutation' => self::MUTATION_CREATE],
            'academic.staff_drop' => ['nav_key' => 'course-registration', 'label' => 'Staff course drop', 'mutation' => self::MUTATION_DELETE],
            'academic.grant_grace' => ['nav_key' => 'course-registration', 'label' => 'Grant grace units', 'mutation' => self::MUTATION_UPDATE],
            'academic.review_extension' => ['nav_key' => 'registration-extensions', 'label' => 'Review registration extension', 'mutation' => self::MUTATION_UPDATE],
            'academic.store_unit_limit' => ['nav_key' => 'unit-limits', 'label' => 'Create unit limit', 'mutation' => self::MUTATION_CREATE],
            'academic.update_unit_limit' => ['nav_key' => 'unit-limits', 'label' => 'Update unit limit', 'mutation' => self::MUTATION_UPDATE],
            'academic.destroy_unit_limit' => ['nav_key' => 'unit-limits', 'label' => 'Delete unit limit', 'mutation' => self::MUTATION_DELETE],
            'academic.store_offering' => ['nav_key' => 'offerings', 'label' => 'Create course offering', 'mutation' => self::MUTATION_CREATE],
            'academic.update_offering' => ['nav_key' => 'offerings', 'label' => 'Update course offering', 'mutation' => self::MUTATION_UPDATE],
            'academic.destroy_offering' => ['nav_key' => 'offerings', 'label' => 'Delete course offering', 'mutation' => self::MUTATION_DELETE],
            'academic.store_program' => ['nav_key' => 'programmes', 'label' => 'Create programme', 'mutation' => self::MUTATION_CREATE],
            'academic.update_program' => ['nav_key' => 'programmes', 'label' => 'Update programme', 'mutation' => self::MUTATION_UPDATE],
            'academic.destroy_program' => ['nav_key' => 'programmes', 'label' => 'Delete programme', 'mutation' => self::MUTATION_DELETE],
            'academic.store_course' => ['nav_key' => 'courses', 'label' => 'Create course', 'mutation' => self::MUTATION_CREATE],
            'academic.update_course' => ['nav_key' => 'courses', 'label' => 'Update course', 'mutation' => self::MUTATION_UPDATE],
            'academic.destroy_course' => ['nav_key' => 'courses', 'label' => 'Delete course', 'mutation' => self::MUTATION_DELETE],
            'academic.sync_program_courses' => ['nav_key' => 'programme-courses', 'label' => 'Assign programme courses', 'mutation' => self::MUTATION_UPDATE],
            'academic.store_campus' => ['nav_key' => 'campuses', 'label' => 'Create campus', 'mutation' => self::MUTATION_CREATE],
            'academic.update_campus' => ['nav_key' => 'campuses', 'label' => 'Update campus', 'mutation' => self::MUTATION_UPDATE],
            'academic.destroy_campus' => ['nav_key' => 'campuses', 'label' => 'Delete campus', 'mutation' => self::MUTATION_DELETE],
            'academic.store_faculty' => ['nav_key' => 'colleges', 'label' => 'Create college', 'mutation' => self::MUTATION_CREATE],
            'academic.update_faculty' => ['nav_key' => 'colleges', 'label' => 'Update college', 'mutation' => self::MUTATION_UPDATE],
            'academic.destroy_faculty' => ['nav_key' => 'colleges', 'label' => 'Delete college', 'mutation' => self::MUTATION_DELETE],
            'academic.store_department' => ['nav_key' => 'departments', 'label' => 'Create academic department', 'mutation' => self::MUTATION_CREATE],
            'academic.update_department' => ['nav_key' => 'departments', 'label' => 'Update academic department', 'mutation' => self::MUTATION_UPDATE],
            'academic.destroy_department' => ['nav_key' => 'departments', 'label' => 'Delete academic department', 'mutation' => self::MUTATION_DELETE],
            'academic.store_session' => ['nav_key' => 'sessions', 'label' => 'Create session', 'mutation' => self::MUTATION_CREATE],
            'academic.update_session' => ['nav_key' => 'sessions', 'label' => 'Update session', 'mutation' => self::MUTATION_UPDATE],
            'academic.destroy_session' => ['nav_key' => 'sessions', 'label' => 'Delete session', 'mutation' => self::MUTATION_DELETE],
            'academic.close_session' => ['nav_key' => 'sessions', 'label' => 'Close session and promote students', 'mutation' => self::MUTATION_UPDATE],
            'academic.graduate' => ['nav_key' => 'graduation', 'label' => 'Confirm graduation', 'mutation' => self::MUTATION_UPDATE],
            'results.board_clear' => ['nav_key' => 'results-board', 'label' => 'Board clear results', 'mutation' => self::MUTATION_UPDATE],
            'results.release' => ['nav_key' => 'results-release', 'label' => 'Release results to students', 'mutation' => self::MUTATION_UPDATE],
            'academic.store_term' => ['nav_key' => 'sessions', 'label' => 'Create term', 'mutation' => self::MUTATION_CREATE],
            'academic.update_term' => ['nav_key' => 'sessions', 'label' => 'Update term', 'mutation' => self::MUTATION_UPDATE],
            'academic.destroy_term' => ['nav_key' => 'sessions', 'label' => 'Delete term', 'mutation' => self::MUTATION_DELETE],
            'academic.store_level' => ['nav_key' => 'levels', 'label' => 'Create level', 'mutation' => self::MUTATION_CREATE],
            'academic.update_level' => ['nav_key' => 'levels', 'label' => 'Update level', 'mutation' => self::MUTATION_UPDATE],
            'academic.destroy_level' => ['nav_key' => 'levels', 'label' => 'Delete level', 'mutation' => self::MUTATION_DELETE],
            'academic.store_intake' => ['nav_key' => 'intakes', 'label' => 'Create application session', 'mutation' => self::MUTATION_CREATE],
            'academic.update_intake' => ['nav_key' => 'intakes', 'label' => 'Update application session', 'mutation' => self::MUTATION_UPDATE],
            'academic.destroy_intake' => ['nav_key' => 'intakes', 'label' => 'Delete application session', 'mutation' => self::MUTATION_DELETE],
            'academic.store_olevel' => ['nav_key' => 'olevel', 'label' => "Create O'level subject", 'mutation' => self::MUTATION_CREATE],
            'academic.update_olevel' => ['nav_key' => 'olevel', 'label' => "Update O'level subject", 'mutation' => self::MUTATION_UPDATE],
            'academic.destroy_olevel' => ['nav_key' => 'olevel', 'label' => "Delete O'level subject", 'mutation' => self::MUTATION_DELETE],
            'students.update' => ['nav_key' => 'students', 'label' => 'Update student record', 'mutation' => self::MUTATION_UPDATE],
            'announcements.store' => ['nav_key' => 'announcements', 'label' => 'Create announcement', 'mutation' => self::MUTATION_CREATE],
            'announcements.update' => ['nav_key' => 'announcements', 'label' => 'Update announcement', 'mutation' => self::MUTATION_UPDATE],
            'announcements.publish' => ['nav_key' => 'announcements', 'label' => 'Publish announcement', 'mutation' => self::MUTATION_UPDATE],
            'announcements.unpublish' => ['nav_key' => 'announcements', 'label' => 'Unpublish announcement', 'mutation' => self::MUTATION_UPDATE],
            'announcements.destroy' => ['nav_key' => 'announcements', 'label' => 'Delete announcement', 'mutation' => self::MUTATION_DELETE],
            'roles.store' => ['nav_key' => 'roles', 'label' => 'Create role', 'mutation' => self::MUTATION_CREATE],
            'roles.update' => ['nav_key' => 'roles', 'label' => 'Update role', 'mutation' => self::MUTATION_UPDATE],
            'roles.destroy' => ['nav_key' => 'roles', 'label' => 'Delete role', 'mutation' => self::MUTATION_DELETE],
            'roles.sync_permissions' => ['nav_key' => 'roles', 'label' => 'Update role permissions', 'mutation' => self::MUTATION_UPDATE],
            'users.store' => ['nav_key' => 'users', 'label' => 'Create user', 'mutation' => self::MUTATION_CREATE],
            'users.update' => ['nav_key' => 'users', 'label' => 'Update user', 'mutation' => self::MUTATION_UPDATE],
            'users.destroy' => ['nav_key' => 'users', 'label' => 'Delete user', 'mutation' => self::MUTATION_DELETE],
            'users.assign_roles' => ['nav_key' => 'users', 'label' => 'Assign user roles', 'mutation' => self::MUTATION_UPDATE],
            'office.store_department' => ['nav_key' => 'office-setup', 'label' => 'Create office department', 'mutation' => self::MUTATION_CREATE],
            'office.update_department' => ['nav_key' => 'office-setup', 'label' => 'Update office department', 'mutation' => self::MUTATION_UPDATE],
            'office.destroy_department' => ['nav_key' => 'office-setup', 'label' => 'Delete office department', 'mutation' => self::MUTATION_DELETE],
            'office.store_unit' => ['nav_key' => 'office-setup', 'label' => 'Create office unit', 'mutation' => self::MUTATION_CREATE],
            'office.update_unit' => ['nav_key' => 'office-setup', 'label' => 'Update office unit', 'mutation' => self::MUTATION_UPDATE],
            'office.destroy_unit' => ['nav_key' => 'office-setup', 'label' => 'Delete office unit', 'mutation' => self::MUTATION_DELETE],
            'office.store_subunit' => ['nav_key' => 'office-setup', 'label' => 'Create office subunit', 'mutation' => self::MUTATION_CREATE],
            'office.update_subunit' => ['nav_key' => 'office-setup', 'label' => 'Update office subunit', 'mutation' => self::MUTATION_UPDATE],
            'office.destroy_subunit' => ['nav_key' => 'office-setup', 'label' => 'Delete office subunit', 'mutation' => self::MUTATION_DELETE],
            'office.sync_department_nav' => ['nav_key' => 'office-setup', 'label' => 'Update department portal links', 'mutation' => self::MUTATION_UPDATE],
            'office.sync_unit_nav' => ['nav_key' => 'office-setup', 'label' => 'Update unit portal links', 'mutation' => self::MUTATION_UPDATE],
            'office.sync_subunit_nav' => ['nav_key' => 'office-setup', 'label' => 'Update subunit portal links', 'mutation' => self::MUTATION_UPDATE],
            'transcripts.start' => ['nav_key' => 'transcript-undergraduate', 'label' => 'Start transcript processing', 'mutation' => self::MUTATION_UPDATE],
            'transcripts.ready' => ['nav_key' => 'transcript-undergraduate', 'label' => 'Mark transcript ready', 'mutation' => self::MUTATION_UPDATE],
            'transcripts.reject' => ['nav_key' => 'transcript-undergraduate', 'label' => 'Reject transcript request', 'mutation' => self::MUTATION_UPDATE],
            'students.import' => ['nav_key' => 'import-students', 'label' => 'Import students', 'mutation' => self::MUTATION_CREATE],
            'admissions.import_applicants' => ['nav_key' => 'import-applicants', 'label' => 'Import applicants', 'mutation' => self::MUTATION_CREATE],
            'admissions.import_candidate_data' => ['nav_key' => 'candidate-data', 'label' => 'Import candidate data', 'mutation' => self::MUTATION_CREATE],
            'finance.import_invoices' => ['nav_key' => 'import-invoices', 'label' => 'Import invoices', 'mutation' => self::MUTATION_CREATE],
            'finance.import_wallet' => ['nav_key' => 'import-wallet', 'label' => 'Import wallet history', 'mutation' => self::MUTATION_CREATE],
            'academic.import_courses' => ['nav_key' => 'courses', 'label' => 'Import course catalogue', 'mutation' => self::MUTATION_CREATE],
            'academic.import_programs' => ['nav_key' => 'programmes', 'label' => 'Import programmes', 'mutation' => self::MUTATION_CREATE],
            'academic.import_faculties' => ['nav_key' => 'colleges', 'label' => 'Import colleges', 'mutation' => self::MUTATION_CREATE],
            'academic.import_departments' => ['nav_key' => 'departments', 'label' => 'Import academic departments', 'mutation' => self::MUTATION_CREATE],
            'academic.import_olevel' => ['nav_key' => 'olevel', 'label' => "Import O'level subjects", 'mutation' => self::MUTATION_CREATE],
            'hostel.import_rooms' => ['nav_key' => 'hostel', 'label' => 'Import hostel rooms', 'mutation' => self::MUTATION_CREATE],
            'results.store' => ['nav_key' => 'results-students', 'label' => 'Enter result', 'mutation' => self::MUTATION_CREATE],
            'results.update' => ['nav_key' => 'results-students', 'label' => 'Update result', 'mutation' => self::MUTATION_UPDATE],
            'results.destroy' => ['nav_key' => 'results-students', 'label' => 'Delete result', 'mutation' => self::MUTATION_DELETE],
            'results.submit' => ['nav_key' => 'results-department', 'label' => 'Submit results', 'mutation' => self::MUTATION_UPDATE],
            'results.faculty_approve' => ['nav_key' => 'results-approvals', 'label' => 'Faculty approve results', 'mutation' => self::MUTATION_UPDATE],
            'results.faculty_return' => ['nav_key' => 'results-approvals', 'label' => 'Faculty return results', 'mutation' => self::MUTATION_UPDATE],
            'results.import' => ['nav_key' => 'results-import', 'label' => 'Import results CSV', 'mutation' => self::MUTATION_CREATE],
            'results.update_grading_scale' => ['nav_key' => 'results-grading-scale', 'label' => 'Update grading scale', 'mutation' => self::MUTATION_UPDATE],
            'settings.update' => ['nav_key' => 'application-settings', 'label' => 'Update application settings', 'mutation' => self::MUTATION_UPDATE],
            'institution.update_settings' => ['nav_key' => 'application-settings', 'label' => 'Update institution settings', 'mutation' => self::MUTATION_UPDATE],
            'reports.store' => ['nav_key' => 'reports', 'label' => 'Save report', 'mutation' => self::MUTATION_CREATE],
            'reports.update' => ['nav_key' => 'reports', 'label' => 'Update saved report', 'mutation' => self::MUTATION_UPDATE],
            'reports.destroy' => ['nav_key' => 'reports', 'label' => 'Delete saved report', 'mutation' => self::MUTATION_DELETE],
            'medical.update_settings' => ['nav_key' => 'medical', 'label' => 'Update clinic settings', 'mutation' => self::MUTATION_UPDATE],
            'medical.check_in' => ['nav_key' => 'medical', 'label' => 'Check in clinic visit', 'mutation' => self::MUTATION_CREATE],
            'medical.update_visit' => ['nav_key' => 'medical', 'label' => 'Update clinic visit', 'mutation' => self::MUTATION_UPDATE],
            'medical.add_item' => ['nav_key' => 'medical', 'label' => 'Add clinic bill item', 'mutation' => self::MUTATION_CREATE],
            'medical.update_item' => ['nav_key' => 'medical', 'label' => 'Update clinic bill item', 'mutation' => self::MUTATION_UPDATE],
            'medical.delete_item' => ['nav_key' => 'medical', 'label' => 'Remove clinic bill item', 'mutation' => self::MUTATION_DELETE],
            'medical.add_prescription' => ['nav_key' => 'medical', 'label' => 'Add prescription', 'mutation' => self::MUTATION_CREATE],
            'medical.dispense_prescription' => ['nav_key' => 'medical', 'label' => 'Dispense prescription', 'mutation' => self::MUTATION_UPDATE],
            'medical.add_sick_note' => ['nav_key' => 'medical', 'label' => 'Issue sick note', 'mutation' => self::MUTATION_CREATE],
            'medical.add_immunization' => ['nav_key' => 'medical', 'label' => 'Record immunization', 'mutation' => self::MUTATION_CREATE],
            'medical.delete_immunization' => ['nav_key' => 'medical', 'label' => 'Delete immunization', 'mutation' => self::MUTATION_DELETE],
        ];

        if (app()->environment('testing')) {
            $actions['test.echo'] = ['nav_key' => 'hostel', 'label' => 'Test office approval', 'mutation' => self::MUTATION_UPDATE];
            $actions['test.echo_create'] = ['nav_key' => 'hostel', 'label' => 'Test create approval', 'mutation' => self::MUTATION_CREATE];
            $actions['test.echo_delete'] = ['nav_key' => 'hostel', 'label' => 'Test delete approval', 'mutation' => self::MUTATION_DELETE];
        }

        return $actions;
    }

    /**
     * @return list<string>
     */
    public static function navKeysWithActions(): array
    {
        return collect(self::actions())->pluck('nav_key')->unique()->values()->all();
    }

    public static function definition(string $actionKey): array
    {
        $definition = self::actions()[$actionKey] ?? null;
        abort_unless($definition, 500, 'Unknown office approval action.');

        return $definition;
    }

    public static function navKey(string $actionKey): string
    {
        return self::definition($actionKey)['nav_key'];
    }

    public static function label(string $actionKey): string
    {
        return self::definition($actionKey)['label'];
    }

    public static function mutationFor(string $actionKey): string
    {
        return self::definition($actionKey)['mutation'] ?? self::MUTATION_UPDATE;
    }

    public static function admissionsNavKey(?string $channel): string
    {
        return match ($channel) {
            'jupeb' => 'admissions-jupeb',
            'postgraduate', 'pg' => 'admissions-postgraduate',
            default => 'admissions-undergraduate',
        };
    }

    public static function transcriptNavKey(?string $channel): string
    {
        return match ($channel) {
            TranscriptChannel::JUPEB, 'jupeb' => 'transcript-jupeb',
            TranscriptChannel::POSTGRADUATE, 'postgraduate', 'pg' => 'transcript-postgraduate',
            default => 'transcript-undergraduate',
        };
    }
}
