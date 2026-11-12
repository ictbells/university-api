<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('programs', function (Blueprint $table) {
            if (! Schema::hasColumn('programs', 'specialisation_required')) {
                $table->boolean('specialisation_required')->default(false)->after('is_research_degree');
            }
        });

        if (! Schema::hasTable('programme_specialisations')) {
            Schema::create('programme_specialisations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('program_id')->constrained('programs')->cascadeOnDelete();
                $table->string('name');
                $table->string('code', 40)->nullable();
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('display_order')->default(0);
                $table->timestamps();
                $table->softDeletes();
                $table->index(['program_id', 'is_active', 'display_order'], 'programme_specialisations_lookup_idx');
            });
        }

        Schema::table('applications', function (Blueprint $table) {
            if (! Schema::hasColumn('applications', 'specialisation_id')) {
                $table->foreignId('specialisation_id')->nullable()->after('program_id')
                    ->constrained('programme_specialisations')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            if (Schema::hasColumn('applications', 'specialisation_id')) {
                $table->dropConstrainedForeignId('specialisation_id');
            }
        });

        Schema::dropIfExists('programme_specialisations');

        Schema::table('programs', function (Blueprint $table) {
            if (Schema::hasColumn('programs', 'specialisation_required')) {
                $table->dropColumn('specialisation_required');
            }
        });
    }
};
