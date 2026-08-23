<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('office_nav_links', function (Blueprint $table) {
            $table->id();
            $table->morphs('linkable');
            $table->string('nav_key');
            $table->timestamps();
            $table->unique(['linkable_type', 'linkable_id', 'nav_key']);
        });

        Schema::table('staff', function (Blueprint $table) {
            $table->foreignId('office_department_id')->nullable()->after('department_id')->constrained()->nullOnDelete();
            $table->foreignId('office_unit_id')->nullable()->after('office_department_id')->constrained()->nullOnDelete();
            $table->foreignId('office_subunit_id')->nullable()->after('office_unit_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            $table->dropConstrainedForeignId('office_subunit_id');
            $table->dropConstrainedForeignId('office_unit_id');
            $table->dropConstrainedForeignId('office_department_id');
        });
        Schema::dropIfExists('office_nav_links');
    }
};
