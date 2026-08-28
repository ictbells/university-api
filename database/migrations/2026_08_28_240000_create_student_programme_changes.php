<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_programme_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('from_program_id')->constrained('programs')->restrictOnDelete();
            $table->foreignId('to_program_id')->constrained('programs')->restrictOnDelete();
            $table->unsignedSmallInteger('from_level');
            $table->unsignedSmallInteger('to_level');
            $table->boolean('same_college')->default(false);
            $table->foreignId('application_id')->nullable()->constrained('applications')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['student_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_programme_changes');
    }
};
