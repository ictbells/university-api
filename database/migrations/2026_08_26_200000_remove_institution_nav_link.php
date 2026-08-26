<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('office_nav_links')->where('nav_key', 'institution')->delete();
    }

    public function down(): void
    {
        // Institution is no longer an assignable portal link.
    }
};
