<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('programme_fees', function (Blueprint $table) {
            if (! Schema::hasColumn('programme_fees', 'installment_tranche')) {
                $table->unsignedSmallInteger('installment_tranche')->nullable()->after('amount')
                    ->comment('Override catalog installment slice when set');
            }
        });

        try {
            Schema::table('programme_fees', function (Blueprint $table) {
                $table->dropUnique('programme_fees_unique');
            });
        } catch (\Throwable) {
            // Index already dropped or named differently on this database.
        }

        Schema::table('programme_fees', function (Blueprint $table) {
            $table->unique(
                ['program_id', 'fee_item_id', 'level_code', 'semester', 'installment_tranche'],
                'programme_fees_unique'
            );
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            if (! Schema::hasColumn('invoice_items', 'programme_fee_id')) {
                $table->foreignId('programme_fee_id')->nullable()->after('fee_item_id')
                    ->constrained('programme_fees')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            if (Schema::hasColumn('invoice_items', 'programme_fee_id')) {
                $table->dropConstrainedForeignId('programme_fee_id');
            }
        });

        try {
            Schema::table('programme_fees', function (Blueprint $table) {
                $table->dropUnique('programme_fees_unique');
            });
        } catch (\Throwable) {
        }

        Schema::table('programme_fees', function (Blueprint $table) {
            if (Schema::hasColumn('programme_fees', 'installment_tranche')) {
                $table->dropColumn('installment_tranche');
            }
        });

        Schema::table('programme_fees', function (Blueprint $table) {
            $table->unique(
                ['program_id', 'fee_item_id', 'level_code', 'semester'],
                'programme_fees_unique'
            );
        });
    }
};
