<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('label')->unique();
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('academic_terms', function (Blueprint $table) {
            $table->foreignId('academic_session_id')
                ->nullable()
                ->after('id')
                ->constrained('academic_sessions')
                ->cascadeOnDelete();
        });

        $labels = DB::table('academic_terms')
            ->whereNull('deleted_at')
            ->select('session_label')
            ->distinct()
            ->pluck('session_label')
            ->filter();

        foreach ($labels as $label) {
            $terms = DB::table('academic_terms')
                ->where('session_label', $label)
                ->whereNull('deleted_at')
                ->orderBy('id')
                ->get();

            $sessionId = DB::table('academic_sessions')->insertGetId([
                'label' => $label,
                'starts_on' => $terms->min('starts_on'),
                'ends_on' => $terms->max('ends_on'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('academic_terms')
                ->where('session_label', $label)
                ->update(['academic_session_id' => $sessionId]);

            if ($terms->count() < 2) {
                DB::table('academic_terms')->insert([
                    'academic_session_id' => $sessionId,
                    'name' => 'Second',
                    'session_label' => $label,
                    'starts_on' => null,
                    'ends_on' => null,
                    'is_current' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                    'deleted_at' => null,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('academic_terms', function (Blueprint $table) {
            $table->dropConstrainedForeignId('academic_session_id');
        });
        Schema::dropIfExists('academic_sessions');
    }
};
