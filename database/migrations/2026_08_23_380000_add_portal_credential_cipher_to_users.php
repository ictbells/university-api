<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('portal_credential_cipher')->nullable()->after('password');
        });

        Schema::table('applications', function (Blueprint $table) {
            $table->timestamp('credentials_emailed_at')->nullable()->after('jamb_registration');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('portal_credential_cipher');
        });

        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn('credentials_emailed_at');
        });
    }
};
