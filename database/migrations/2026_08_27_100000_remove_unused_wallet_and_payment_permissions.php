<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const REMOVED = [
        'finance.payments.record',
        'wallet.topup',
        'wallet.view_own',
    ];

    public function up(): void
    {
        $ids = Permission::query()->whereIn('key', self::REMOVED)->pluck('id');
        if ($ids->isEmpty()) {
            return;
        }

        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        Permission::query()->whereIn('id', $ids)->delete();
    }

    public function down(): void
    {
        // Permissions are restored by re-seeding PermissionCatalog if needed.
    }
};
