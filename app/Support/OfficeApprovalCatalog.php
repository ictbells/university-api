<?php

namespace App\Support;

class OfficeApprovalCatalog
{
    public const MUTATION_CREATE = 'create';

    public const MUTATION_UPDATE = 'update';

    public const MUTATION_DELETE = 'delete';

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
            'admissions.staff_update' => ['nav_key' => 'admissions-undergraduate', 'label' => 'Update application file', 'mutation' => self::MUTATION_UPDATE],
            'admissions.update_acceptance_fee' => ['nav_key' => 'admissions-undergraduate', 'label' => 'Update acceptance fee', 'mutation' => self::MUTATION_UPDATE],
            'medical.approve_appointment' => ['nav_key' => 'medical', 'label' => 'Approve clinic appointment', 'mutation' => self::MUTATION_UPDATE],
            'medical.reject_appointment' => ['nav_key' => 'medical', 'label' => 'Reject clinic appointment', 'mutation' => self::MUTATION_UPDATE],
            'medical.finalize_bill' => ['nav_key' => 'medical', 'label' => 'Finalize clinic bill', 'mutation' => self::MUTATION_UPDATE],
            'medical.update_profile' => ['nav_key' => 'medical', 'label' => 'Update medical profile', 'mutation' => self::MUTATION_UPDATE],
            'finance.store_fee' => ['nav_key' => 'finance', 'label' => 'Create fee item', 'mutation' => self::MUTATION_CREATE],
            'finance.update_fee' => ['nav_key' => 'finance', 'label' => 'Update fee item', 'mutation' => self::MUTATION_UPDATE],
            'finance.destroy_fee' => ['nav_key' => 'finance', 'label' => 'Delete fee item', 'mutation' => self::MUTATION_DELETE],
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
            'finance.bulk_programme_fees' => ['nav_key' => 'finance', 'label' => 'Bulk save programme fees', 'mutation' => self::MUTATION_UPDATE],
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
            'pg.update' => ['nav_key' => 'pg', 'label' => 'Update PG record', 'mutation' => self::MUTATION_UPDATE],
            'announcements.store' => ['nav_key' => 'announcements', 'label' => 'Create announcement', 'mutation' => self::MUTATION_CREATE],
            'announcements.update' => ['nav_key' => 'announcements', 'label' => 'Update announcement', 'mutation' => self::MUTATION_UPDATE],
            'announcements.publish' => ['nav_key' => 'announcements', 'label' => 'Publish announcement', 'mutation' => self::MUTATION_UPDATE],
            'announcements.unpublish' => ['nav_key' => 'announcements', 'label' => 'Unpublish announcement', 'mutation' => self::MUTATION_UPDATE],
            'announcements.destroy' => ['nav_key' => 'announcements', 'label' => 'Delete announcement', 'mutation' => self::MUTATION_DELETE],
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
}
