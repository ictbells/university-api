<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Previous deploy created tables then failed on RDS trigger 1419, so the
        // migration was not recorded. Resume from triggers only in that case.
        if (Schema::hasTable('webhook_logs')) {
            $this->installAuditLogTriggers();

            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('status')->default('active')->after('password');
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('module');
            $table->string('label');
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->unique(['role_id', 'permission_id']);
        });

        Schema::create('user_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->unique(['user_id', 'role_id']);
        });

        Schema::create('campuses', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->nullable();
            $table->string('city')->nullable();
            $table->string('address')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('faculties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campus_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('code')->nullable();
            $table->timestamps();
        });

        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('faculty_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code')->nullable();
            $table->timestamps();
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        Schema::create('academic_terms', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('session_label');
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->boolean('is_current')->default(false);
            $table->timestamps();
        });

        Schema::create('programs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code')->nullable();
            $table->string('award_type');
            $table->string('study_level');
            $table->unsignedTinyInteger('duration_years')->default(4);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('staff', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->string('staff_number')->unique();
            $table->string('title')->nullable();
            $table->timestamps();
        });

        Schema::create('intakes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_term_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('entry_mode');
            $table->date('opens_on')->nullable();
            $table->date('closes_on')->nullable();
            $table->boolean('is_open')->default(true);
            $table->timestamps();
        });

        Schema::create('fee_items', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('category');
            $table->string('entry_mode')->nullable();
            $table->decimal('amount', 12, 2);
            $table->boolean('wallet_allowed')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('student_id')->nullable()->index();
            $table->unsignedBigInteger('application_id')->nullable()->index();
            $table->string('category');
            $table->decimal('amount', 12, 2);
            $table->decimal('balance', 12, 2);
            $table->string('status')->default('unpaid');
            $table->boolean('wallet_allowed')->default(true);
            $table->timestamps();
        });

        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fee_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description');
            $table->decimal('amount', 12, 2);
            $table->timestamps();
        });

        Schema::create('applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('intake_id')->constrained()->cascadeOnDelete();
            $table->foreignId('program_id')->nullable()->constrained()->nullOnDelete();
            $table->string('entry_mode');
            $table->string('stage')->default('started');
            $table->string('current_step')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('application_fee_invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->foreignId('acceptance_fee_invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->unsignedBigInteger('student_id')->nullable()->index();
            $table->string('offer_reference')->nullable();
            $table->timestamps();
        });

        Schema::create('application_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->string('step_key');
            $table->string('status')->default('pending');
            $table->json('payload')->nullable();
            $table->timestamps();
            $table->unique(['application_id', 'step_key']);
        });

        Schema::create('application_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->string('doc_type');
            $table->string('path');
            $table->string('original_name')->nullable();
            $table->timestamps();
        });

        Schema::create('application_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('from_stage')->nullable();
            $table->string('to_stage');
            $table->string('decision')->nullable();
            $table->text('reason')->nullable();
            $table->timestamps();
        });

        Schema::create('nin_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('application_id')->nullable()->constrained()->nullOnDelete();
            $table->string('nin');
            $table->string('prembly_reference')->nullable();
            $table->json('mapped_fields')->nullable();
            $table->json('raw_snapshot')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });

        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('application_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('program_id')->nullable()->constrained()->nullOnDelete();
            $table->string('student_number')->nullable()->unique();
            $table->string('matric_number')->nullable()->unique();
            $table->string('first_name');
            $table->string('middle_name')->nullable();
            $table->string('last_name');
            $table->date('date_of_birth')->nullable();
            $table->string('gender')->nullable();
            $table->string('nin')->nullable();
            $table->string('photo_path')->nullable();
            $table->string('phone')->nullable();
            $table->text('address')->nullable();
            $table->string('next_of_kin')->nullable();
            $table->string('next_of_kin_phone')->nullable();
            $table->string('study_level')->default('undergraduate');
            $table->unsignedTinyInteger('current_level')->default(100);
            $table->string('status')->default('active');
            $table->boolean('nin_locked')->default(true);
            $table->timestamps();
        });

        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->unique()->constrained()->cascadeOnDelete();
            $table->decimal('balance', 12, 2)->default(0);
            $table->string('status')->default('active');
            $table->timestamps();
        });

        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->decimal('amount', 12, 2);
            $table->string('reference')->nullable();
            $table->string('source_module')->nullable();
            $table->unsignedBigInteger('related_id')->nullable();
            $table->string('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('wallet_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->unsignedBigInteger('document_id')->nullable();
            $table->string('title');
            $table->text('payload')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('method');
            $table->decimal('amount', 12, 2);
            $table->string('status')->default('pending');
            $table->string('reference')->nullable()->unique();
            $table->string('paystack_reference')->nullable();
            $table->string('receipt_no')->nullable();
            $table->string('purpose')->nullable();
            $table->timestamps();
        });

        Schema::create('courses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->string('title');
            $table->unsignedTinyInteger('units')->default(3);
            $table->timestamps();
        });

        Schema::create('course_offerings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_term_id')->constrained()->cascadeOnDelete();
            $table->foreignId('faculty_staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->string('section')->default('A');
            $table->unsignedSmallInteger('capacity')->default(50);
            $table->timestamps();
        });

        Schema::create('enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_offering_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('enrolled');
            $table->timestamps();
            $table->unique(['student_id', 'course_offering_id']);
        });

        Schema::create('grades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('letter')->nullable();
            $table->decimal('points', 4, 2)->nullable();
            $table->unsignedTinyInteger('score')->nullable();
            $table->timestamps();
        });

        Schema::create('attendance', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_offering_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->date('attended_on');
            $table->string('status')->default('present');
            $table->timestamps();
        });

        Schema::create('pg_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('supervisor_staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->string('topic')->nullable();
            $table->string('proposal_status')->default('not_started');
            $table->string('thesis_status')->default('not_started');
            $table->timestamps();
        });

        Schema::create('medical_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('blood_type')->nullable();
            $table->text('allergies')->nullable();
            $table->text('conditions')->nullable();
            $table->timestamps();
        });

        Schema::create('immunizations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->string('vaccine');
            $table->date('given_on')->nullable();
            $table->timestamps();
        });

        Schema::create('clinic_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->date('visited_on');
            $table->text('complaint')->nullable();
            $table->text('diagnosis')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('medical_bills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_visit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('status')->default('unpaid');
            $table->timestamps();
        });

        Schema::create('hostels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campus_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('gender')->default('mixed');
            $table->timestamps();
        });

        Schema::create('hostel_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hostel_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('hostel_rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hostel_block_id')->constrained()->cascadeOnDelete();
            $table->string('number');
            $table->unsignedTinyInteger('capacity')->default(4);
            $table->string('gender')->nullable();
            $table->timestamps();
        });

        Schema::create('hostel_beds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hostel_room_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->string('status')->default('available');
            $table->timestamps();
        });

        Schema::create('hostel_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('hostel_bed_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_term_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('allocated');
            $table->timestamp('allocated_at')->nullable();
            $table->timestamp('vacated_at')->nullable();
            $table->timestamps();
        });

        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type');
            $table->string('title');
            $table->string('path')->nullable();
            $table->longText('html_body')->nullable();
            $table->string('status')->default('issued');
            $table->timestamps();
        });

        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body');
            $table->string('audience')->default('all');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('module')->nullable();
            $table->unsignedBigInteger('related_id')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('actor_type')->default('user');
            $table->unsignedBigInteger('actor_user_id')->nullable()->index();
            $table->string('actor_email')->nullable();
            $table->string('actor_name')->nullable();
            $table->json('actor_roles')->nullable();
            $table->string('action');
            $table->string('summary');
            $table->timestamp('occurred_at');
            $table->string('module');
            $table->string('entity_type')->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('route')->nullable();
            $table->string('path')->nullable();
            $table->json('before_state')->nullable();
            $table->json('after_state')->nullable();
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('device')->nullable();
            $table->string('request_id')->index();
            $table->text('reason')->nullable();
            $table->string('prev_hash')->nullable();
            $table->string('row_hash');
        });

        Schema::create('integration_endpoints', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type');
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });

        Schema::create('webhook_logs', function (Blueprint $table) {
            $table->id();
            $table->string('provider');
            $table->string('event')->nullable();
            $table->json('payload')->nullable();
            $table->string('status')->default('received');
            $table->timestamps();
        });

        $this->installAuditLogTriggers();
    }

    private function installAuditLogTriggers(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        try {
            DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_no_update');
            DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_no_delete');
            DB::unprepared('
                CREATE TRIGGER audit_logs_no_update BEFORE UPDATE ON audit_logs
                FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE "45000" SET MESSAGE_TEXT = "Audit logs are immutable";
                END
            ');
            DB::unprepared('
                CREATE TRIGGER audit_logs_no_delete BEFORE DELETE ON audit_logs
                FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE "45000" SET MESSAGE_TEXT = "Audit logs are immutable";
                END
            ');
        } catch (QueryException $e) {
            // RDS: no SUPER, binlog on, log_bin_trust_function_creators=0 → 1419.
            // App-level immutability lives on App\Models\AuditLog.
            if ($this->isRdsTriggerPrivilegeError($e)) {
                return;
            }
            throw $e;
        }
    }

    private function isRdsTriggerPrivilegeError(QueryException $e): bool
    {
        $driverCode = (int) ($e->errorInfo[1] ?? 0);
        $message = $e->getMessage();

        return $driverCode === 1419
            || str_contains($message, 'log_bin_trust_function_creators')
            || str_contains($message, 'SUPER privilege');
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_no_update');
            DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_no_delete');
        }

        $tables = [
            'webhook_logs', 'integration_endpoints', 'audit_logs', 'notifications', 'announcements',
            'documents', 'hostel_allocations', 'hostel_beds', 'hostel_rooms', 'hostel_blocks', 'hostels',
            'medical_bills', 'clinic_visits', 'immunizations', 'medical_profiles', 'pg_records',
            'attendance', 'grades', 'enrollments', 'course_offerings', 'courses', 'payments',
            'wallet_credentials', 'wallet_transactions', 'wallets', 'students', 'nin_verifications',
            'application_reviews', 'application_documents', 'application_steps', 'applications',
            'invoice_items', 'invoices', 'fee_items', 'intakes', 'staff', 'programs', 'academic_terms',
            'settings', 'departments', 'faculties', 'campuses', 'user_roles', 'role_permissions',
            'roles', 'permissions',
        ];
        foreach ($tables as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};
