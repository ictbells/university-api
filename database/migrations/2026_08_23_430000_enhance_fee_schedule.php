<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('intakes', function (Blueprint $table) {
            $table->decimal('acceptance_fee_amount', 12, 2)->nullable()->after('application_fee_amount');
        });

        Schema::table('programs', function (Blueprint $table) {
            $table->decimal('tuition_amount', 12, 2)->nullable()->after('duration_years');
        });

        Schema::table('fee_items', function (Blueprint $table) {
            $table->string('description')->nullable()->after('name');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->unsignedTinyInteger('installment_percent')->nullable()->after('category');
            $table->decimal('full_amount', 12, 2)->nullable()->after('amount');
        });

        $acceptance = DB::table('fee_items')->where('category', 'acceptance_fee')->where('is_active', 1)->value('amount');
        if ($acceptance !== null) {
            DB::table('intakes')->whereNull('acceptance_fee_amount')->update([
                'acceptance_fee_amount' => $acceptance,
            ]);
        }

        $tuition = DB::table('fee_items')->where('category', 'tuition')->where('is_active', 1)->value('amount');
        if ($tuition !== null) {
            DB::table('programs')->whereNull('tuition_amount')->update([
                'tuition_amount' => $tuition,
            ]);
        }

        foreach ([
            ['category' => 'sundry', 'name' => 'Sundry fee', 'amount' => 10000, 'wallet_allowed' => true],
            ['category' => 'hostel', 'name' => 'Hostel fee', 'amount' => 80000, 'wallet_allowed' => true],
            ['category' => 'medical', 'name' => 'Clinic charge', 'amount' => 5000, 'wallet_allowed' => true],
        ] as $row) {
            $exists = DB::table('fee_items')->where('category', $row['category'])->exists();
            if (! $exists) {
                DB::table('fee_items')->insert([
                    ...$row,
                    'entry_mode' => null,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['installment_percent', 'full_amount']);
        });
        Schema::table('fee_items', function (Blueprint $table) {
            $table->dropColumn('description');
        });
        Schema::table('programs', function (Blueprint $table) {
            $table->dropColumn('tuition_amount');
        });
        Schema::table('intakes', function (Blueprint $table) {
            $table->dropColumn('acceptance_fee_amount');
        });
    }
};
