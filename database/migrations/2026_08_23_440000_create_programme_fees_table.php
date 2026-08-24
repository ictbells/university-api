<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fee_items', function (Blueprint $table) {
            if (! Schema::hasColumn('fee_items', 'is_required')) {
                $table->boolean('is_required')->default(true)->after('wallet_allowed');
            }
            if (! Schema::hasColumn('fee_items', 'display_order')) {
                $table->unsignedInteger('display_order')->default(0)->after('is_required');
            }
        });

        Schema::create('programme_fees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_id')->constrained('programs')->cascadeOnDelete();
            $table->foreignId('fee_item_id')->constrained('fee_items')->cascadeOnDelete();
            $table->decimal('amount', 12, 2)->nullable()->comment('Override catalog amount when set');
            $table->string('level_code', 20)->default('all')->comment('Academic level code e.g. 100, or all');
            $table->string('semester', 20)->default('both')->comment('first, second, or both');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('display_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(
                ['program_id', 'fee_item_id', 'level_code', 'semester'],
                'programme_fees_unique'
            );
            $table->index(['program_id', 'level_code', 'semester', 'is_active'], 'programme_fees_lookup_idx');
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('programme_fees');
        Schema::table('fee_items', function (Blueprint $table) {
            if (Schema::hasColumn('fee_items', 'display_order')) {
                $table->dropColumn('display_order');
            }
            if (Schema::hasColumn('fee_items', 'is_required')) {
                $table->dropColumn('is_required');
            }
        });
    }
};
