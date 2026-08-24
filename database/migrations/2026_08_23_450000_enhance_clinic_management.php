<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medical_profiles', function (Blueprint $table) {
            $table->boolean('nhis_enrolled')->default(false)->after('conditions');
            $table->string('nhis_number')->nullable()->after('nhis_enrolled');
            $table->string('nhis_provider')->nullable()->after('nhis_number');
            $table->decimal('nhis_coverage_percent', 5, 2)->nullable()->after('nhis_provider');
            $table->date('nhis_valid_until')->nullable()->after('nhis_coverage_percent');
        });

        Schema::table('clinic_visits', function (Blueprint $table) {
            $table->string('status')->default('waiting')->after('staff_id');
            $table->string('visit_type')->default('walk_in')->after('status');
            $table->dateTime('scheduled_at')->nullable()->after('visited_on');
            $table->unsignedTinyInteger('triage_priority')->nullable()->after('scheduled_at');
            $table->decimal('temperature', 4, 1)->nullable()->after('notes');
            $table->unsignedSmallInteger('pulse')->nullable()->after('temperature');
            $table->unsignedSmallInteger('bp_systolic')->nullable()->after('pulse');
            $table->unsignedSmallInteger('bp_diastolic')->nullable()->after('bp_systolic');
            $table->decimal('weight_kg', 5, 2)->nullable()->after('bp_diastolic');
            $table->decimal('height_cm', 5, 2)->nullable()->after('weight_kg');
            $table->string('disposition')->nullable()->after('height_cm');
            $table->boolean('notes_internal')->default(true)->after('disposition');
        });

        Schema::table('medical_bills', function (Blueprint $table) {
            $table->decimal('gross_amount', 12, 2)->nullable()->after('invoice_id');
            $table->decimal('nhis_covered_amount', 12, 2)->default(0)->after('gross_amount');
            $table->decimal('student_payable_amount', 12, 2)->nullable()->after('nhis_covered_amount');
            $table->boolean('nhis_applied')->default(false)->after('student_payable_amount');
        });

        Schema::create('clinic_visit_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_visit_id')->constrained()->cascadeOnDelete();
            $table->string('description');
            $table->decimal('quantity', 10, 2)->default(1);
            $table->decimal('unit_amount', 12, 2);
            $table->decimal('line_total', 12, 2);
            $table->boolean('nhis_covered')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('prescriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_visit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->string('medication');
            $table->string('dosage')->nullable();
            $table->string('frequency')->nullable();
            $table->string('duration')->nullable();
            $table->text('instructions')->nullable();
            $table->timestamp('dispensed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('sick_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_visit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->date('issued_on');
            $table->date('valid_from');
            $table->date('valid_to');
            $table->text('reason');
            $table->text('restrictions')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Setting::query()->updateOrCreate(
            ['key' => 'clinic.nhis_enabled'],
            ['value' => '1']
        );
        Setting::query()->updateOrCreate(
            ['key' => 'clinic.nhis_default_coverage_percent'],
            ['value' => '90']
        );
        Setting::query()->updateOrCreate(
            ['key' => 'clinic.nhis_auto_cover_lines'],
            ['value' => '1']
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('sick_notes');
        Schema::dropIfExists('prescriptions');
        Schema::dropIfExists('clinic_visit_items');

        Schema::table('medical_bills', function (Blueprint $table) {
            $table->dropColumn(['gross_amount', 'nhis_covered_amount', 'student_payable_amount', 'nhis_applied']);
        });

        Schema::table('clinic_visits', function (Blueprint $table) {
            $table->dropColumn([
                'status', 'visit_type', 'scheduled_at', 'triage_priority',
                'temperature', 'pulse', 'bp_systolic', 'bp_diastolic',
                'weight_kg', 'height_cm', 'disposition', 'notes_internal',
            ]);
        });

        Schema::table('medical_profiles', function (Blueprint $table) {
            $table->dropColumn([
                'nhis_enrolled', 'nhis_number', 'nhis_provider',
                'nhis_coverage_percent', 'nhis_valid_until',
            ]);
        });

        Setting::query()->whereIn('key', [
            'clinic.nhis_enabled',
            'clinic.nhis_default_coverage_percent',
            'clinic.nhis_auto_cover_lines',
        ])->delete();
    }
};
