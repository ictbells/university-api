<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('stateoforigin')) {
            Schema::create('stateoforigin', function (Blueprint $table) {
                $table->integer('state_id')->primary();
                $table->string('state_title', 100);
            });
        }

        if (! Schema::hasTable('lga')) {
            Schema::create('lga', function (Blueprint $table) {
                $table->integer('lga_id')->primary();
                $table->string('lga_title', 100);
                $table->integer('state_id')->index();
            });
        }

        // Data: php artisan db:seed --class=StateOfOriginSeeder
        // (bundled in database/data/nigeria_states_lgas.json)
    }

    public function down(): void
    {
        Schema::dropIfExists('lga');
        Schema::dropIfExists('stateoforigin');
    }
};
