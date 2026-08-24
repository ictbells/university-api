<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hostels', function (Blueprint $table) {
            $table->boolean('due_required')->default(false)->after('is_active');
            $table->decimal('due_amount', 12, 2)->nullable()->after('due_required');
        });
    }

    public function down(): void
    {
        Schema::table('hostels', function (Blueprint $table) {
            $table->dropColumn(['due_required', 'due_amount']);
        });
    }
};
