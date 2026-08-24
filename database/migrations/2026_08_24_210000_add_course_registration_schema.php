<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('courses', 'course_type')) {
            Schema::table('courses', function (Blueprint $table) {
                $table->string('course_type')->default('departmental')->after('units');
            });
        }

        if (! Schema::hasColumn('program_course', 'bucket')) {
            Schema::table('program_course', function (Blueprint $table) {
                $table->string('bucket')->nullable()->after('academic_level_id');
            });
        }

        if (! Schema::hasColumn('academic_terms', 'extension_price_per_unit')) {
            Schema::table('academic_terms', function (Blueprint $table) {
                $table->decimal('extension_price_per_unit', 12, 2)->nullable()->after('late_registration_closes_at');
            });
        }

        $enrollmentColumns = ['registered_at', 'dropped_at', 'registered_by', 'drop_reason', 'is_carry_over'];
        if (collect($enrollmentColumns)->contains(fn (string $column) => ! Schema::hasColumn('enrollments', $column))) {
            Schema::table('enrollments', function (Blueprint $table) {
                if (! Schema::hasColumn('enrollments', 'registered_at')) {
                    $table->timestamp('registered_at')->nullable()->after('status');
                }
                if (! Schema::hasColumn('enrollments', 'dropped_at')) {
                    $table->timestamp('dropped_at')->nullable()->after('registered_at');
                }
                if (! Schema::hasColumn('enrollments', 'registered_by')) {
                    $table->foreignId('registered_by')->nullable()->after('dropped_at')->constrained('users')->nullOnDelete();
                }
                if (! Schema::hasColumn('enrollments', 'drop_reason')) {
                    $table->string('drop_reason')->nullable()->after('registered_by');
                }
                if (! Schema::hasColumn('enrollments', 'is_carry_over')) {
                    $table->boolean('is_carry_over')->default(false)->after('drop_reason');
                }
            });
        }

        if (! Schema::hasTable('unit_limits')) {
            Schema::create('unit_limits', function (Blueprint $table) {
                $table->id();
                $table->foreignId('program_id')->constrained()->cascadeOnDelete();
                $table->foreignId('academic_level_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('academic_term_id')->nullable()->constrained()->nullOnDelete();
                $table->string('bucket');
                $table->unsignedSmallInteger('min_units')->default(0);
                $table->unsignedSmallInteger('max_units')->default(0);
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('unit_graces')) {
            Schema::create('unit_graces', function (Blueprint $table) {
                $table->id();
                $table->foreignId('student_id')->constrained()->cascadeOnDelete();
                $table->foreignId('academic_term_id')->constrained()->cascadeOnDelete();
                $table->string('bucket')->default('overall');
                $table->unsignedSmallInteger('extra_units');
                $table->string('reason');
                $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('registration_extensions')) {
            Schema::create('registration_extensions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('student_id')->constrained()->cascadeOnDelete();
                $table->foreignId('academic_term_id')->constrained()->cascadeOnDelete();
                $table->unsignedSmallInteger('requested_units');
                $table->unsignedSmallInteger('approved_units')->nullable();
                $table->string('status')->default('pending');
                $table->text('reason')->nullable();
                $table->text('staff_note')->nullable();
                $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('reviewed_at')->nullable();
                $table->timestamp('paid_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('registration_extensions');
        Schema::dropIfExists('unit_graces');
        Schema::dropIfExists('unit_limits');

        Schema::table('enrollments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('registered_by');
            $table->dropColumn(['registered_at', 'dropped_at', 'drop_reason', 'is_carry_over']);
        });

        Schema::table('academic_terms', function (Blueprint $table) {
            $table->dropColumn('extension_price_per_unit');
        });

        Schema::table('program_course', function (Blueprint $table) {
            $table->dropColumn('bucket');
        });

        Schema::table('courses', function (Blueprint $table) {
            $table->dropColumn('course_type');
        });
    }
};
