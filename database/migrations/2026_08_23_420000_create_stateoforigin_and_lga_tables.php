<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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

        $needsSeed = DB::table('stateoforigin')->count() === 0 || DB::table('lga')->count() === 0;
        if (! $needsSeed) {
            return;
        }

        try {
            $pdo = new PDO(
                sprintf(
                    'mysql:host=%s;port=%s;dbname=mfbinhouse',
                    config('database.connections.mysql.host', '127.0.0.1'),
                    config('database.connections.mysql.port', '3306'),
                ),
                (string) config('database.connections.mysql.username'),
                (string) (config('database.connections.mysql.password') ?? ''),
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );

            if (DB::table('stateoforigin')->count() === 0) {
                $states = $pdo->query('SELECT state_id, state_title FROM stateoforigin ORDER BY state_id')->fetchAll(PDO::FETCH_ASSOC);
                foreach (array_chunk($states, 100) as $chunk) {
                    DB::table('stateoforigin')->insert($chunk);
                }
            }

            if (DB::table('lga')->count() === 0) {
                $lgas = $pdo->query('SELECT lga_id, lga_title, state_id FROM lga ORDER BY lga_id')->fetchAll(PDO::FETCH_ASSOC);
                foreach (array_chunk($lgas, 100) as $chunk) {
                    DB::table('lga')->insert($chunk);
                }
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('lga');
        Schema::dropIfExists('stateoforigin');
    }
};
