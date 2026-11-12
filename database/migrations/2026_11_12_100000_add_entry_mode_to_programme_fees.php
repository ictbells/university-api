<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('programme_fees', function (Blueprint $table) {
            if (! Schema::hasColumn('programme_fees', 'entry_mode')) {
                $table->string('entry_mode', 20)->default('all')->after('level_code')
                    ->comment('Admission entry mode: all, utme, de, jupeb, transfer, pg');
            }
        });

        DB::table('programme_fees')
            ->where(function ($query) {
                $query->whereNull('entry_mode')->orWhere('entry_mode', '');
            })
            ->update(['entry_mode' => 'all']);

        try {
            Schema::table('programme_fees', function (Blueprint $table) {
                $table->dropUnique('programme_fees_unique');
            });
        } catch (\Throwable) {
            // Index already dropped or named differently on this database.
        }

        Schema::table('programme_fees', function (Blueprint $table) {
            $table->unique(
                ['program_id', 'fee_item_id', 'level_code', 'entry_mode', 'semester', 'installment_tranche'],
                'programme_fees_unique'
            );
        });

        try {
            Schema::table('programme_fees', function (Blueprint $table) {
                $table->dropIndex('programme_fees_lookup_idx');
            });
        } catch (\Throwable) {
        }

        Schema::table('programme_fees', function (Blueprint $table) {
            $table->index(
                ['program_id', 'level_code', 'entry_mode', 'semester', 'is_active'],
                'programme_fees_lookup_idx'
            );
        });
    }

    public function down(): void
    {
        try {
            Schema::table('programme_fees', function (Blueprint $table) {
                $table->dropUnique('programme_fees_unique');
            });
        } catch (\Throwable) {
        }

        try {
            Schema::table('programme_fees', function (Blueprint $table) {
                $table->dropIndex('programme_fees_lookup_idx');
            });
        } catch (\Throwable) {
        }

        Schema::table('programme_fees', function (Blueprint $table) {
            if (Schema::hasColumn('programme_fees', 'entry_mode')) {
                $table->dropColumn('entry_mode');
            }
        });

        Schema::table('programme_fees', function (Blueprint $table) {
            $table->unique(
                ['program_id', 'fee_item_id', 'level_code', 'semester', 'installment_tranche'],
                'programme_fees_unique'
            );
            $table->index(
                ['program_id', 'level_code', 'semester', 'is_active'],
                'programme_fees_lookup_idx'
            );
        });
    }
};
