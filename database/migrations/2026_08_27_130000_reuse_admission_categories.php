<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('applications') && ! Schema::hasColumn('applications', 'academic_session_id')) {
            Schema::table('applications', function (Blueprint $table) {
                $table->foreignId('academic_session_id')
                    ->nullable()
                    ->after('intake_id')
                    ->constrained('academic_sessions')
                    ->nullOnDelete();
            });
        }

        if (Schema::hasColumn('applications', 'academic_session_id')) {
            $sessionByTerm = DB::table('academic_terms')
                ->whereNotNull('academic_session_id')
                ->pluck('academic_session_id', 'id');

            foreach (DB::table('applications')->whereNull('academic_session_id')->get(['id', 'intake_id']) as $application) {
                $termId = DB::table('intakes')->where('id', $application->intake_id)->value('academic_term_id');
                $sessionId = $termId ? ($sessionByTerm[$termId] ?? null) : null;
                if (! $sessionId) {
                    continue;
                }
                DB::table('applications')->where('id', $application->id)->update([
                    'academic_session_id' => $sessionId,
                ]);
            }
        }

        $keepByMode = [];
        $intakes = DB::table('intakes')
            ->orderByRaw('CASE WHEN deleted_at IS NULL THEN 0 ELSE 1 END')
            ->orderByDesc('id')
            ->get(['id', 'entry_mode']);
        foreach ($intakes as $intake) {
            $mode = (string) $intake->entry_mode;
            if (! isset($keepByMode[$mode])) {
                $keepByMode[$mode] = (int) $intake->id;
                continue;
            }
            $keepId = $keepByMode[$mode];
            DB::table('applications')->where('intake_id', $intake->id)->update(['intake_id' => $keepId]);
            DB::table('intakes')->where('id', $intake->id)->delete();
        }

        if (Schema::hasTable('intakes')) {
            $indexName = 'intakes_entry_mode_unique';
            $hasIndex = collect(Schema::getIndexes('intakes'))->contains(
                fn (array $index) => ($index['name'] ?? '') === $indexName || (($index['unique'] ?? false) && ($index['columns'] ?? []) === ['entry_mode'])
            );
            if (! $hasIndex) {
                Schema::table('intakes', function (Blueprint $table) use ($indexName) {
                    $table->unique('entry_mode', $indexName);
                });
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('intakes')) {
            $hasIndex = collect(Schema::getIndexes('intakes'))->contains(
                fn (array $index) => ($index['name'] ?? '') === 'intakes_entry_mode_unique'
                    || (($index['unique'] ?? false) && ($index['columns'] ?? []) === ['entry_mode'])
            );
            if ($hasIndex) {
                Schema::table('intakes', function (Blueprint $table) {
                    $table->dropUnique('intakes_entry_mode_unique');
                });
            }
        }

        if (Schema::hasTable('applications') && Schema::hasColumn('applications', 'academic_session_id')) {
            Schema::table('applications', function (Blueprint $table) {
                $table->dropConstrainedForeignId('academic_session_id');
            });
        }
    }
};
