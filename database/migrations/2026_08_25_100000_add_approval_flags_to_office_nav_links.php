<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('office_nav_links', function (Blueprint $table) {
            if (! Schema::hasColumn('office_nav_links', 'require_create')) {
                $table->boolean('require_create')->default(true)->after('nav_key');
            }
            if (! Schema::hasColumn('office_nav_links', 'require_update')) {
                $table->boolean('require_update')->default(true)->after('require_create');
            }
            if (! Schema::hasColumn('office_nav_links', 'require_delete')) {
                $table->boolean('require_delete')->default(true)->after('require_update');
            }
            if (! Schema::hasColumn('office_nav_links', 'approval_chain')) {
                $table->string('approval_chain', 32)->default('both')->after('require_delete');
            }
        });
    }

    public function down(): void
    {
        Schema::table('office_nav_links', function (Blueprint $table) {
            foreach (['require_create', 'require_update', 'require_delete', 'approval_chain'] as $column) {
                if (Schema::hasColumn('office_nav_links', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
