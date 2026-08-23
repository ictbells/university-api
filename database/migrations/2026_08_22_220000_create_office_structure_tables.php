<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('office_departments', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->nullable()->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('office_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_department_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['office_department_id', 'code']);
        });

        Schema::create('office_subunits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_unit_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['office_unit_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('office_subunits');
        Schema::dropIfExists('office_units');
        Schema::dropIfExists('office_departments');
    }
};
