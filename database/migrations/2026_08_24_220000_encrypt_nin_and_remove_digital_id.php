<?php

use App\Models\Permission;
use App\Models\Role;
use App\Support\NinCipher;
use App\Support\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nin_verifications', function (Blueprint $table) {
            $table->string('nin_hash', 64)->nullable()->after('nin');
        });
        Schema::table('students', function (Blueprint $table) {
            $table->string('nin_hash', 64)->nullable()->after('nin');
        });

        $this->encryptColumn('nin_verifications');
        $this->encryptColumn('students');
        $this->encryptStepPayloads();

        Schema::table('nin_verifications', function (Blueprint $table) {
            $table->unique('nin_hash');
        });
        Schema::table('students', function (Blueprint $table) {
            $table->unique('nin_hash');
        });

        DB::table('documents')->where('type', 'id_card')->delete();
        if (Schema::hasTable('wallet_credentials')) {
            DB::table('wallet_credentials')->where('type', 'id_card')->delete();
        }

        foreach (PermissionCatalog::all() as $perm) {
            Permission::query()->updateOrCreate(['key' => $perm['key']], $perm);
        }
        $permissionId = Permission::query()->where('key', 'exam_clearance.view')->value('id');
        if ($permissionId) {
            Role::query()
                ->where('is_system', true)
                ->whereIn('slug', ['super-admin', 'registrar'])
                ->get()
                ->each(fn (Role $role) => $role->permissions()->syncWithoutDetaching([$permissionId]));
        }
    }

    public function down(): void
    {
        Schema::table('nin_verifications', function (Blueprint $table) {
            $table->dropUnique(['nin_hash']);
            $table->dropColumn('nin_hash');
        });
        Schema::table('students', function (Blueprint $table) {
            $table->dropUnique(['nin_hash']);
            $table->dropColumn('nin_hash');
        });
        Permission::query()->where('key', 'exam_clearance.view')->delete();
    }

    private function encryptColumn(string $table): void
    {
        $rows = DB::table($table)->whereNotNull('nin')->where('nin', '!=', '')->get(['id', 'nin']);
        foreach ($rows as $row) {
            $plain = NinCipher::decrypt((string) $row->nin);
            if (! $plain || ! NinCipher::isPlain($plain)) {
                continue;
            }
            DB::table($table)->where('id', $row->id)->update([
                'nin' => NinCipher::encrypt($plain),
                'nin_hash' => NinCipher::hash($plain),
            ]);
        }
    }

    private function encryptStepPayloads(): void
    {
        if (! Schema::hasTable('application_steps')) {
            return;
        }
        $steps = DB::table('application_steps')->whereNotNull('payload')->get(['id', 'payload']);
        foreach ($steps as $step) {
            $payload = json_decode((string) $step->payload, true);
            if (! is_array($payload) || empty($payload['nin']) || ! NinCipher::isPlain((string) $payload['nin'])) {
                continue;
            }
            $payload['nin'] = NinCipher::encrypt((string) $payload['nin']);
            DB::table('application_steps')->where('id', $step->id)->update([
                'payload' => json_encode($payload),
            ]);
        }
    }
};
