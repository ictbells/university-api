<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_categories', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('description')->nullable();
            $table->boolean('is_schedule')->default(false);
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('display_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        $now = now();
        $rows = [];
        $order = 0;

        $schedule = [
            'tuition' => 'Tuition',
            'library' => 'Library',
            'medical' => 'Medical levy',
            'sports' => 'Sports',
            'ict' => 'ICT',
            'laboratory' => 'Laboratory',
            'development' => 'Development levy',
            'other' => 'Other',
        ];
        foreach ($schedule as $code => $name) {
            $rows[] = [
                'code' => $code,
                'name' => $name,
                'description' => null,
                'is_schedule' => true,
                'is_system' => true,
                'is_active' => true,
                'display_order' => $order++,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $operational = [
            'acceptance_fee' => 'Acceptance fee',
            'hostel' => 'Hostel',
            'clinic' => 'Clinic services',
            'sundry' => 'Sundry',
            'course_registration_extension' => 'Course registration extension',
        ];
        foreach ($operational as $code => $name) {
            $rows[] = [
                'code' => $code,
                'name' => $name,
                'description' => null,
                'is_schedule' => false,
                'is_system' => true,
                'is_active' => true,
                'display_order' => $order++,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('fee_categories')->insert($rows);
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_categories');
    }
};
