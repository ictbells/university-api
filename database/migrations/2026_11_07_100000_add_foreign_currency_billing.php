<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->string('country', 40)->nullable()->after('entry_mode');
            $table->string('billing_currency', 3)->default('NGN')->after('country');
        });

        Schema::table('students', function (Blueprint $table) {
            $table->string('billing_currency', 3)->default('NGN')->after('country');
        });

        Schema::table('fee_items', function (Blueprint $table) {
            $table->decimal('amount_usd', 12, 2)->nullable()->after('amount');
        });

        Schema::table('programme_fees', function (Blueprint $table) {
            $table->decimal('amount_usd', 12, 2)->nullable()->after('amount');
        });

        Schema::table('intakes', function (Blueprint $table) {
            $table->decimal('application_fee_amount_usd', 12, 2)->nullable()->after('application_fee_amount');
            $table->decimal('acceptance_fee_amount_usd', 12, 2)->nullable()->after('acceptance_fee_amount');
        });

        Schema::table('hostels', function (Blueprint $table) {
            $table->decimal('due_amount_usd', 12, 2)->nullable()->after('due_amount');
        });

        Schema::table('academic_terms', function (Blueprint $table) {
            $table->decimal('extension_price_per_unit_usd', 12, 2)->nullable()->after('extension_price_per_unit');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->string('currency', 3)->default('NGN')->after('category');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->string('currency', 3)->default('NGN')->after('amount');
        });

        Schema::table('wallets', function (Blueprint $table) {
            $table->decimal('balance_usd', 12, 2)->default(0)->after('balance');
        });

        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->string('currency', 3)->default('NGN')->after('amount');
        });

        Schema::table('rebate_types', function (Blueprint $table) {
            $table->string('currency', 3)->default('NGN')->after('kind');
        });
    }

    public function down(): void
    {
        Schema::table('rebate_types', function (Blueprint $table) {
            $table->dropColumn('currency');
        });
        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->dropColumn('currency');
        });
        Schema::table('wallets', function (Blueprint $table) {
            $table->dropColumn('balance_usd');
        });
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('currency');
        });
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('currency');
        });
        Schema::table('academic_terms', function (Blueprint $table) {
            $table->dropColumn('extension_price_per_unit_usd');
        });
        Schema::table('hostels', function (Blueprint $table) {
            $table->dropColumn('due_amount_usd');
        });
        Schema::table('intakes', function (Blueprint $table) {
            $table->dropColumn(['application_fee_amount_usd', 'acceptance_fee_amount_usd']);
        });
        Schema::table('programme_fees', function (Blueprint $table) {
            $table->dropColumn('amount_usd');
        });
        Schema::table('fee_items', function (Blueprint $table) {
            $table->dropColumn('amount_usd');
        });
        Schema::table('students', function (Blueprint $table) {
            $table->dropColumn('billing_currency');
        });
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn(['country', 'billing_currency']);
        });
    }
};
