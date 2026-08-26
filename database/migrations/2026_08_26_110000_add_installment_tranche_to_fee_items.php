<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fee_items', function (Blueprint $table) {
            $table->unsignedSmallInteger('installment_tranche')->nullable()->after('category');
        });
    }

    public function down(): void
    {
        Schema::table('fee_items', function (Blueprint $table) {
            $table->dropColumn('installment_tranche');
        });
    }
};
