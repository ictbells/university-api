<?php

use App\Models\Permission;
use App\Models\Role;
use App\Support\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->date('graduated_at')->nullable()->after('status');
            $table->date('studentship_expires_at')->nullable()->after('graduated_at');
        });

        foreach (PermissionCatalog::all() as $perm) {
            Permission::query()->updateOrCreate(['key' => $perm['key']], $perm);
        }

        $id = Permission::query()->where('key', 'academic.graduate')->value('id');
        if ($id) {
            $roles = Role::query()
                ->where('is_system', true)
                ->whereIn('slug', ['super-admin', 'registrar'])
                ->get();
            foreach ($roles as $role) {
                $role->permissions()->syncWithoutDetaching([$id]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropColumn(['graduated_at', 'studentship_expires_at']);
        });

        Permission::query()->where('key', 'academic.graduate')->delete();
    }
};
