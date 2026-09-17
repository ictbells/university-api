<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        if (Schema::hasTable('fee_categories') && ! DB::table('fee_categories')->where('code', 'semester_fee')->exists()) {
            $order = (int) (DB::table('fee_categories')->max('display_order') ?? 0) + 1;
            DB::table('fee_categories')->insert([
                'code' => 'semester_fee',
                'name' => 'Semester fee',
                'description' => 'University-wide flat charge for enrolled students each academic term. Not a programme schedule fee.',
                'is_schedule' => false,
                'is_system' => true,
                'is_active' => true,
                'display_order' => $order,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        if (Schema::hasTable('fee_items') && ! DB::table('fee_items')->where('category', 'semester_fee')->exists()) {
            DB::table('fee_items')->insert([
                'name' => 'Semester fee',
                'description' => 'Payable before other student charges for the term.',
                'category' => 'semester_fee',
                'amount' => 0,
                'wallet_allowed' => true,
                'is_required' => true,
                'is_active' => true,
                'display_order' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        if (Schema::hasTable('invoices') && ! Schema::hasColumn('invoices', 'academic_term_id')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->foreignId('academic_term_id')
                    ->nullable()
                    ->after('academic_session_id')
                    ->constrained('academic_terms')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('invoices') && Schema::hasColumn('invoices', 'academic_term_id')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->dropConstrainedForeignId('academic_term_id');
            });
        }

        if (Schema::hasTable('fee_items')) {
            DB::table('fee_items')->where('category', 'semester_fee')->delete();
        }

        if (Schema::hasTable('fee_categories')) {
            DB::table('fee_categories')->where('code', 'semester_fee')->delete();
        }
    }
};
