<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rebate_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('kind');
            $table->decimal('default_value', 12, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('invoice_rebates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rebate_type_id')->constrained('rebate_types')->restrictOnDelete();
            $table->foreignId('invoice_item_id')->nullable()->constrained('invoice_items')->nullOnDelete();
            $table->string('kind');
            $table->decimal('value', 12, 2);
            $table->decimal('amount', 12, 2);
            $table->text('reason');
            $table->foreignId('applied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reverse_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->decimal('rebate_total', 12, 2)->default(0)->after('balance');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('rebate_total');
        });
        Schema::dropIfExists('invoice_rebates');
        Schema::dropIfExists('rebate_types');
    }
};
