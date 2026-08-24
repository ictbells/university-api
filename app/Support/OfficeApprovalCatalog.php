<?php

namespace App\Support;

class OfficeApprovalCatalog
{
    /**
     * @return array<string, array{nav_key: string, label: string}>
     */
    public static function actions(): array
    {
        $actions = [
            'hostel.allocate' => ['nav_key' => 'hostel', 'label' => 'Allocate hostel bed'],
            'hostel.auto_allocate' => ['nav_key' => 'hostel', 'label' => 'Auto-allocate hostel bed'],
            'hostel.approve' => ['nav_key' => 'hostel', 'label' => 'Approve hostel allocation'],
            'hostel.reject' => ['nav_key' => 'hostel', 'label' => 'Reject hostel allocation'],
            'hostel.vacate' => ['nav_key' => 'hostel', 'label' => 'Vacate hostel bed'],
            'hostel.store' => ['nav_key' => 'hostel', 'label' => 'Create hostel'],
            'hostel.update' => ['nav_key' => 'hostel', 'label' => 'Update hostel'],
            'hostel.destroy' => ['nav_key' => 'hostel', 'label' => 'Delete hostel'],
            'hostel.sync_level_windows' => ['nav_key' => 'hostel', 'label' => 'Update hostel level windows'],
            'hostel.store_block' => ['nav_key' => 'hostel', 'label' => 'Create hostel block'],
            'hostel.update_block' => ['nav_key' => 'hostel', 'label' => 'Update hostel block'],
            'hostel.destroy_block' => ['nav_key' => 'hostel', 'label' => 'Delete hostel block'],
            'hostel.store_room' => ['nav_key' => 'hostel', 'label' => 'Create hostel room'],
            'hostel.update_room' => ['nav_key' => 'hostel', 'label' => 'Update hostel room'],
            'hostel.destroy_room' => ['nav_key' => 'hostel', 'label' => 'Delete hostel room'],
            'hostel.reserve_room' => ['nav_key' => 'hostel', 'label' => 'Reserve hostel room'],
            'hostel.release_room' => ['nav_key' => 'hostel', 'label' => 'Release hostel room'],
            'hostel.disable_room' => ['nav_key' => 'hostel', 'label' => 'Disable hostel room'],
            'hostel.enable_room' => ['nav_key' => 'hostel', 'label' => 'Enable hostel room'],
            'admissions.transition' => ['nav_key' => 'admissions-undergraduate', 'label' => 'Advance application'],
            'admissions.staff_update' => ['nav_key' => 'admissions-undergraduate', 'label' => 'Update application file'],
            'admissions.update_acceptance_fee' => ['nav_key' => 'admissions-undergraduate', 'label' => 'Update acceptance fee'],
            'medical.approve_appointment' => ['nav_key' => 'medical', 'label' => 'Approve clinic appointment'],
            'medical.reject_appointment' => ['nav_key' => 'medical', 'label' => 'Reject clinic appointment'],
            'medical.finalize_bill' => ['nav_key' => 'medical', 'label' => 'Finalize clinic bill'],
            'medical.update_profile' => ['nav_key' => 'medical', 'label' => 'Update medical profile'],
            'finance.store_fee' => ['nav_key' => 'finance', 'label' => 'Create fee item'],
            'finance.update_fee' => ['nav_key' => 'finance', 'label' => 'Update fee item'],
            'finance.destroy_fee' => ['nav_key' => 'finance', 'label' => 'Delete fee item'],
            'finance.generate_invoice' => ['nav_key' => 'finance', 'label' => 'Generate invoice'],
            'finance.disable_invoice' => ['nav_key' => 'finance', 'label' => 'Disable invoice'],
            'finance.enable_invoice' => ['nav_key' => 'finance', 'label' => 'Enable invoice'],
            'finance.store_rebate_type' => ['nav_key' => 'finance', 'label' => 'Create rebate type'],
            'finance.update_rebate_type' => ['nav_key' => 'finance', 'label' => 'Update rebate type'],
            'finance.destroy_rebate_type' => ['nav_key' => 'finance', 'label' => 'Delete rebate type'],
            'finance.apply_rebate' => ['nav_key' => 'finance', 'label' => 'Apply invoice rebate'],
            'finance.reverse_rebate' => ['nav_key' => 'finance', 'label' => 'Reverse invoice rebate'],
            'finance.store_programme_fee' => ['nav_key' => 'finance', 'label' => 'Create programme fee'],
            'finance.update_programme_fee' => ['nav_key' => 'finance', 'label' => 'Update programme fee'],
            'finance.destroy_programme_fee' => ['nav_key' => 'finance', 'label' => 'Delete programme fee'],
            'finance.bulk_programme_fees' => ['nav_key' => 'finance', 'label' => 'Bulk save programme fees'],
            'documents.issue' => ['nav_key' => 'documents', 'label' => 'Issue document'],
            'academic.staff_register' => ['nav_key' => 'course-registration', 'label' => 'Staff course registration'],
            'academic.staff_drop' => ['nav_key' => 'course-registration', 'label' => 'Staff course drop'],
            'academic.grant_grace' => ['nav_key' => 'course-registration', 'label' => 'Grant grace units'],
            'academic.review_extension' => ['nav_key' => 'registration-extensions', 'label' => 'Review registration extension'],
            'academic.store_unit_limit' => ['nav_key' => 'unit-limits', 'label' => 'Create unit limit'],
            'academic.update_unit_limit' => ['nav_key' => 'unit-limits', 'label' => 'Update unit limit'],
            'academic.destroy_unit_limit' => ['nav_key' => 'unit-limits', 'label' => 'Delete unit limit'],
            'academic.store_offering' => ['nav_key' => 'offerings', 'label' => 'Create course offering'],
            'academic.update_offering' => ['nav_key' => 'offerings', 'label' => 'Update course offering'],
            'academic.destroy_offering' => ['nav_key' => 'offerings', 'label' => 'Delete course offering'],
            'academic.store_program' => ['nav_key' => 'programmes', 'label' => 'Create programme'],
            'academic.update_program' => ['nav_key' => 'programmes', 'label' => 'Update programme'],
            'academic.destroy_program' => ['nav_key' => 'programmes', 'label' => 'Delete programme'],
            'academic.store_course' => ['nav_key' => 'courses', 'label' => 'Create course'],
            'academic.update_course' => ['nav_key' => 'courses', 'label' => 'Update course'],
            'academic.destroy_course' => ['nav_key' => 'courses', 'label' => 'Delete course'],
            'academic.store_campus' => ['nav_key' => 'campuses', 'label' => 'Create campus'],
            'academic.update_campus' => ['nav_key' => 'campuses', 'label' => 'Update campus'],
            'academic.destroy_campus' => ['nav_key' => 'campuses', 'label' => 'Delete campus'],
            'academic.store_faculty' => ['nav_key' => 'colleges', 'label' => 'Create college'],
            'academic.update_faculty' => ['nav_key' => 'colleges', 'label' => 'Update college'],
            'academic.destroy_faculty' => ['nav_key' => 'colleges', 'label' => 'Delete college'],
            'academic.store_department' => ['nav_key' => 'departments', 'label' => 'Create academic department'],
            'academic.update_department' => ['nav_key' => 'departments', 'label' => 'Update academic department'],
            'academic.destroy_department' => ['nav_key' => 'departments', 'label' => 'Delete academic department'],
            'academic.store_session' => ['nav_key' => 'sessions', 'label' => 'Create session'],
            'academic.update_session' => ['nav_key' => 'sessions', 'label' => 'Update session'],
            'academic.destroy_session' => ['nav_key' => 'sessions', 'label' => 'Delete session'],
            'academic.close_session' => ['nav_key' => 'sessions', 'label' => 'Close session and promote students'],
            'academic.graduate' => ['nav_key' => 'graduation', 'label' => 'Confirm graduation'],
            'academic.store_term' => ['nav_key' => 'sessions', 'label' => 'Create term'],
            'academic.update_term' => ['nav_key' => 'sessions', 'label' => 'Update term'],
            'academic.destroy_term' => ['nav_key' => 'sessions', 'label' => 'Delete term'],
            'academic.store_level' => ['nav_key' => 'levels', 'label' => 'Create level'],
            'academic.update_level' => ['nav_key' => 'levels', 'label' => 'Update level'],
            'academic.destroy_level' => ['nav_key' => 'levels', 'label' => 'Delete level'],
            'academic.store_intake' => ['nav_key' => 'intakes', 'label' => 'Create application window'],
            'academic.update_intake' => ['nav_key' => 'intakes', 'label' => 'Update application window'],
            'academic.destroy_intake' => ['nav_key' => 'intakes', 'label' => 'Delete application window'],
            'academic.store_olevel' => ['nav_key' => 'olevel', 'label' => "Create O'level subject"],
            'academic.update_olevel' => ['nav_key' => 'olevel', 'label' => "Update O'level subject"],
            'academic.destroy_olevel' => ['nav_key' => 'olevel', 'label' => "Delete O'level subject"],
            'students.update' => ['nav_key' => 'students', 'label' => 'Update student record'],
            'pg.update' => ['nav_key' => 'pg', 'label' => 'Update PG record'],
            'announcements.store' => ['nav_key' => 'announcements', 'label' => 'Create announcement'],
            'announcements.update' => ['nav_key' => 'announcements', 'label' => 'Update announcement'],
            'announcements.publish' => ['nav_key' => 'announcements', 'label' => 'Publish announcement'],
            'announcements.unpublish' => ['nav_key' => 'announcements', 'label' => 'Unpublish announcement'],
            'announcements.destroy' => ['nav_key' => 'announcements', 'label' => 'Delete announcement'],
        ];

        if (app()->environment('testing')) {
            $actions['test.echo'] = ['nav_key' => 'hostel', 'label' => 'Test office approval'];
        }

        return $actions;
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

    public static function admissionsNavKey(?string $channel): string
    {
        return match ($channel) {
            'jupeb' => 'admissions-jupeb',
            'postgraduate', 'pg' => 'admissions-postgraduate',
            default => 'admissions-undergraduate',
        };
    }
}
