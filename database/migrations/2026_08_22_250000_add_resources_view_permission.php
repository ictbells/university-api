<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $permission = Permission::query()->updateOrCreate(
            ['key' => 'resources.view'],
            ['module' => 'admin', 'label' => 'View and download platform resources'],
        );

        $superAdmin = Role::query()->where('slug', 'super-admin')->first();
        if ($superAdmin && ! $superAdmin->permissions()->where('permissions.id', $permission->id)->exists()) {
            $superAdmin->permissions()->attach($permission->id);
        }
    }

    public function down(): void
    {
        Permission::query()->where('key', 'resources.view')->delete();
    }
};
