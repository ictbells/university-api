<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('fee_categories')) {
            return;
        }

        if (! Schema::hasColumn('fee_categories', 'deleted_at')) {
            Schema::table('fee_categories', function (Blueprint $table) {
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('fee_categories') || ! Schema::hasColumn('fee_categories', 'deleted_at')) {
            return;
        }

        Schema::table('fee_categories', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
