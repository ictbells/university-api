<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transcript_requests', function (Blueprint $table) {
            if (! Schema::hasColumn('transcript_requests', 'transcript_type')) {
                $table->string('transcript_type', 32)->nullable()->after('program_id');
            }
            if (! Schema::hasColumn('transcript_requests', 'delivery_email')) {
                $table->string('delivery_email')->nullable()->after('contact_email');
            }
            if (! Schema::hasColumn('transcript_requests', 'delivery_address')) {
                $table->text('delivery_address')->nullable()->after('delivery_email');
            }
            if (! Schema::hasColumn('transcript_requests', 'collection_method')) {
                $table->string('collection_method', 32)->nullable()->after('delivery_address');
            }
        });
    }

    public function down(): void
    {
        Schema::table('transcript_requests', function (Blueprint $table) {
            foreach (['collection_method', 'delivery_address', 'delivery_email', 'transcript_type'] as $column) {
                if (Schema::hasColumn('transcript_requests', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
