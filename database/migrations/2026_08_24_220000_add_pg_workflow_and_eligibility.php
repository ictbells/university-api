<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_templates', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('description')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('workflow_template_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_template_id')->constrained('workflow_templates')->cascadeOnDelete();
            $table->string('key');
            $table->string('label');
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('phase');
            $table->string('subject');
            $table->string('permission_key')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->boolean('is_wired')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['workflow_template_id', 'key']);
        });

        Schema::create('workflow_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_template_id')->constrained('workflow_templates')->cascadeOnDelete();
            $table->morphs('subject');
            $table->string('phase');
            $table->string('current_stage_key');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('workflow_transitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_run_id')->constrained('workflow_runs')->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('from_stage')->nullable();
            $table->string('to_stage');
            $table->string('decision')->nullable();
            $table->text('reason')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('referee_invites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained('applications')->cascadeOnDelete();
            $table->unsignedTinyInteger('position')->default(1);
            $table->string('name');
            $table->string('email');
            $table->string('institution')->nullable();
            $table->string('position_title')->nullable();
            $table->string('phone')->nullable();
            $table->string('token_hash');
            $table->timestamp('expires_at');
            $table->string('status')->default('pending');
            $table->timestamp('submitted_at')->nullable();
            $table->text('comments')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['application_id', 'email']);
        });

        Schema::table('programs', function (Blueprint $table) {
            $table->boolean('is_research_degree')->default(false)->after('is_active');
            $table->json('eligibility')->nullable()->after('is_research_degree');
            $table->foreignId('workflow_template_id')->nullable()->after('eligibility')->constrained('workflow_templates')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('programs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('workflow_template_id');
            $table->dropColumn(['is_research_degree', 'eligibility']);
        });
        Schema::dropIfExists('referee_invites');
        Schema::dropIfExists('workflow_transitions');
        Schema::dropIfExists('workflow_runs');
        Schema::dropIfExists('workflow_template_stages');
        Schema::dropIfExists('workflow_templates');
    }
};
