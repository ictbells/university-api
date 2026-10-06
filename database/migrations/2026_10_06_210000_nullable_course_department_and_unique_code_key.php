<?php

use App\Models\Course;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropForeign(['department_id']);
        });

        Schema::table('courses', function (Blueprint $table) {
            $table->unsignedBigInteger('department_id')->nullable()->change();
            $table->string('code_key', 50)->nullable()->after('code');
        });

        $seen = [];
        DB::table('courses')->orderBy('id')->get(['id', 'code'])->each(function ($row) use (&$seen) {
            $key = Course::normalizeCode((string) $row->code);
            if ($key === '') {
                $key = 'EMPTY-'.$row->id;
            }
            if (isset($seen[$key])) {
                $key = $key.'-'.$row->id;
            }
            $seen[$key] = true;
            DB::table('courses')->where('id', $row->id)->update(['code_key' => $key]);
        });

        Schema::table('courses', function (Blueprint $table) {
            $table->string('code_key', 50)->nullable(false)->change();
            $table->unique('code_key');
            $table->foreign('department_id')
                ->references('id')
                ->on('departments')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropForeign(['department_id']);
            $table->dropUnique(['code_key']);
            $table->dropColumn('code_key');
        });

        Schema::table('courses', function (Blueprint $table) {
            $table->unsignedBigInteger('department_id')->nullable(false)->change();
            $table->foreign('department_id')
                ->references('id')
                ->on('departments')
                ->cascadeOnDelete();
        });
    }
};
