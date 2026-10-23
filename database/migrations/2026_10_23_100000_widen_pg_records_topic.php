<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pg_records', function (Blueprint $table) {
            $table->text('topic')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('pg_records', function (Blueprint $table) {
            $table->string('topic')->nullable()->change();
        });
    }
};
