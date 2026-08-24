<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candidate_data', function (Blueprint $table) {
            $table->id();
            $table->string('rg_num')->index();
            $table->string('academic_year', 20)->index();
            $table->string('rg_candname')->nullable();
            $table->string('rg_sex')->nullable();
            $table->string('state_name')->nullable();
            $table->decimal('rg_aggr', 5, 2)->nullable();
            $table->string('co_name')->nullable();
            $table->string('lga_name')->nullable();
            $table->string('subject1')->nullable();
            $table->decimal('rg_sub1scor', 5, 2)->nullable();
            $table->string('subject2')->nullable();
            $table->decimal('rg_sub2scor', 5, 2)->nullable();
            $table->string('subject3')->nullable();
            $table->decimal('rg_sub3scor', 5, 2)->nullable();
            $table->decimal('eng_score', 5, 2)->nullable();
            $table->string('subj')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['rg_num', 'academic_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidate_data');
    }
};
