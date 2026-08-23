<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private array $tables = [
        'users',
        'permissions',
        'roles',
        'campuses',
        'faculties',
        'departments',
        'settings',
        'academic_terms',
        'programs',
        'staff',
        'intakes',
        'fee_items',
        'invoices',
        'invoice_items',
        'applications',
        'application_steps',
        'application_documents',
        'application_reviews',
        'nin_verifications',
        'students',
        'wallets',
        'wallet_transactions',
        'wallet_credentials',
        'payments',
        'courses',
        'course_offerings',
        'enrollments',
        'grades',
        'attendance',
        'pg_records',
        'medical_profiles',
        'immunizations',
        'clinic_visits',
        'medical_bills',
        'hostels',
        'hostel_blocks',
        'hostel_rooms',
        'hostel_beds',
        'hostel_allocations',
        'documents',
        'announcements',
        'notifications',
        'integration_endpoints',
        'webhook_logs',
        'office_departments',
        'office_units',
        'office_subunits',
        'office_nav_links',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'deleted_at')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->softDeletes();
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'deleted_at')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropSoftDeletes();
            });
        }
    }
};
