<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->index('receipt_no');
        });

        // Normalize login keys so equality lookups can use unique indexes.
        DB::table('applications')
            ->whereNotNull('application_number')
            ->orderBy('id')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    $normalized = strtoupper(preg_replace('/\s+/u', '', (string) $row->application_number) ?? '');
                    if ($normalized !== '' && $normalized !== $row->application_number) {
                        DB::table('applications')->where('id', $row->id)->update([
                            'application_number' => $normalized,
                        ]);
                    }
                }
            });

        DB::table('users')
            ->whereNotNull('jamb_registration')
            ->orderBy('id')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    $normalized = strtoupper(preg_replace('/\s+/u', '', (string) $row->jamb_registration) ?? '');
                    if ($normalized !== '' && $normalized !== $row->jamb_registration) {
                        DB::table('users')->where('id', $row->id)->update([
                            'jamb_registration' => $normalized,
                        ]);
                    }
                }
            });

        DB::table('students')
            ->where(function ($q) {
                $q->whereNotNull('matric_number')->orWhereNotNull('student_number');
            })
            ->orderBy('id')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    $updates = [];
                    foreach (['matric_number', 'student_number'] as $col) {
                        $raw = $row->{$col} ?? null;
                        if ($raw === null || $raw === '') {
                            continue;
                        }
                        $normalized = strtoupper(preg_replace('/\s+/u', '', (string) $raw) ?? '');
                        if ($normalized !== '' && $normalized !== $raw) {
                            $updates[$col] = $normalized;
                        }
                    }
                    if ($updates !== []) {
                        DB::table('students')->where('id', $row->id)->update($updates);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['receipt_no']);
        });
    }
};
