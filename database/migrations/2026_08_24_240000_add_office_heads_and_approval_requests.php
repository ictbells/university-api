<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('office_departments', function (Blueprint $table) {
            $table->foreignId('head_staff_id')->nullable()->after('is_active')->constrained('staff')->nullOnDelete();
            $table->unique('head_staff_id');
        });

        Schema::table('office_units', function (Blueprint $table) {
            $table->foreignId('head_staff_id')->nullable()->after('is_active')->constrained('staff')->nullOnDelete();
            $table->unique('head_staff_id');
        });

        Schema::create('office_approval_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_department_id')->constrained('office_departments')->cascadeOnDelete();
            $table->foreignId('office_unit_id')->nullable()->constrained('office_units')->nullOnDelete();
            $table->foreignId('requester_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('action_key');
            $table->string('nav_key');
            $table->nullableMorphs('subject');
            $table->json('payload')->nullable();
            $table->string('summary');
            $table->string('status', 32);
            $table->foreignId('unit_reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('unit_reviewed_at')->nullable();
            $table->text('unit_comment')->nullable();
            $table->foreignId('hod_reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('hod_reviewed_at')->nullable();
            $table->text('hod_comment')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('office_approval_requests');
        Schema::table('office_units', function (Blueprint $table) {
            $table->dropUnique(['head_staff_id']);
            $table->dropConstrainedForeignId('head_staff_id');
        });
        Schema::table('office_departments', function (Blueprint $table) {
            $table->dropUnique(['head_staff_id']);
            $table->dropConstrainedForeignId('head_staff_id');
        });
    }
};
