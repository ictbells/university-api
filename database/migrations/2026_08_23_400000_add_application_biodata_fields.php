<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->string('marital_status')->nullable()->after('gender');
            $table->string('religion')->nullable()->after('marital_status');
            $table->string('country')->nullable()->after('religion');
            $table->string('state')->nullable()->after('country');
            $table->string('lga')->nullable()->after('state');
            $table->string('next_of_kin_relationship')->nullable()->after('next_of_kin_phone');
            $table->string('next_of_kin_email')->nullable()->after('next_of_kin_relationship');
            $table->text('next_of_kin_address')->nullable()->after('next_of_kin_email');
            $table->string('sponsor_name')->nullable()->after('next_of_kin_address');
            $table->string('sponsor_relationship')->nullable()->after('sponsor_name');
            $table->string('sponsor_phone')->nullable()->after('sponsor_relationship');
            $table->string('sponsor_email')->nullable()->after('sponsor_phone');
            $table->text('sponsor_address')->nullable()->after('sponsor_email');
        });

        Schema::table('medical_profiles', function (Blueprint $table) {
            $table->string('genotype')->nullable()->after('blood_type');
            $table->boolean('has_medical_condition')->nullable()->after('genotype');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropColumn([
                'marital_status',
                'religion',
                'country',
                'state',
                'lga',
                'next_of_kin_relationship',
                'next_of_kin_email',
                'next_of_kin_address',
                'sponsor_name',
                'sponsor_relationship',
                'sponsor_phone',
                'sponsor_email',
                'sponsor_address',
            ]);
        });

        Schema::table('medical_profiles', function (Blueprint $table) {
            $table->dropColumn(['genotype', 'has_medical_condition']);
        });
    }
};
