<?php

use App\Models\Permission;
use App\Models\Role;
use App\Support\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ADMISSIONS_CHANNELS = [
        'admissions-undergraduate',
        'admissions-jupeb',
        'admissions-postgraduate',
    ];

    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->timestamp('physically_cleared_at')->nullable()->after('offer_reference');
            $table->foreignId('physically_cleared_by')->nullable()->after('physically_cleared_at')
                ->constrained('users')->nullOnDelete();
        });

        DB::table('applications')
            ->where('stage', 'matriculated')
            ->whereNull('physically_cleared_at')
            ->update(['physically_cleared_at' => DB::raw('updated_at')]);

        foreach (PermissionCatalog::all() as $perm) {
            Permission::query()->updateOrCreate(['key' => $perm['key']], $perm);
        }

        $permissionId = Permission::query()->where('key', 'admissions.clear')->value('id');
        if ($permissionId) {
            $roleIds = Role::query()
                ->whereHas('permissions', fn ($query) => $query->whereIn('key', [
                    'admissions.offer',
                    'admissions.matriculate',
                ]))
                ->pluck('id');
            foreach ($roleIds as $roleId) {
                Role::query()->find($roleId)?->permissions()->syncWithoutDetaching([$permissionId]);
            }
        }

        foreach (self::ADMISSIONS_CHANNELS as $source) {
            $this->copyNavKey($source, 'admissions-clearance');
        }
    }

    public function down(): void
    {
        DB::table('office_nav_links')->where('nav_key', 'admissions-clearance')->delete();

        Schema::table('applications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('physically_cleared_by');
            $table->dropColumn('physically_cleared_at');
        });
    }

    private function copyNavKey(string $source, string $target): void
    {
        $rows = DB::table('office_nav_links')->where('nav_key', $source)->get();
        foreach ($rows as $row) {
            DB::table('office_nav_links')->updateOrInsert(
                [
                    'linkable_type' => $row->linkable_type,
                    'linkable_id' => $row->linkable_id,
                    'nav_key' => $target,
                ],
                [
                    'created_at' => $row->created_at,
                    'updated_at' => now(),
                ],
            );
        }
    }
};
