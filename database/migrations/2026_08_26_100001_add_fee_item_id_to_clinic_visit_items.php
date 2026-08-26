<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('clinic_visit_items') || Schema::hasColumn('clinic_visit_items', 'fee_item_id')) {
            return;
        }

        Schema::table('clinic_visit_items', function (Blueprint $table) {
            $table->foreignId('fee_item_id')->nullable()->after('clinic_visit_id')->constrained('fee_items')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('clinic_visit_items') || ! Schema::hasColumn('clinic_visit_items', 'fee_item_id')) {
            return;
        }

        Schema::table('clinic_visit_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('fee_item_id');
        });
    }
};
