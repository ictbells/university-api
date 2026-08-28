<?php

use App\Support\AdmissionGuideContent;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admission_guides', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('intro')->nullable();
            $table->json('sections')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        $now = now();
        $perm = [
            'key' => 'admissions.guide',
            'module' => 'admissions',
            'label' => 'Publish admission guide',
        ];
        if (! DB::table('permissions')->where('key', $perm['key'])->exists()) {
            DB::table('permissions')->insert([
                ...$perm,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $permId = DB::table('permissions')->where('key', 'admissions.guide')->value('id');
        if ($permId) {
            foreach (['super-admin', 'registrar', 'admissions'] as $slug) {
                $roleId = DB::table('roles')->where('slug', $slug)->value('id');
                if (! $roleId) {
                    continue;
                }
                $exists = DB::table('role_permissions')
                    ->where('role_id', $roleId)
                    ->where('permission_id', $permId)
                    ->exists();
                if (! $exists) {
                    DB::table('role_permissions')->insert([
                        'role_id' => $roleId,
                        'permission_id' => $permId,
                    ]);
                }
            }
        }

        $this->copyNavKey('admissions-undergraduate', ['admission-guide']);

        $sample = AdmissionGuideContent::sample();
        if (! DB::table('admission_guides')->exists()) {
            DB::table('admission_guides')->insert([
                'title' => $sample['title'],
                'intro' => $sample['intro'],
                'sections' => json_encode($sample['sections']),
                'published_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('office_nav_links')->where('nav_key', 'admission-guide')->delete();
        $permId = DB::table('permissions')->where('key', 'admissions.guide')->value('id');
        if ($permId) {
            DB::table('role_permissions')->where('permission_id', $permId)->delete();
            DB::table('permissions')->where('id', $permId)->delete();
        }
        Schema::dropIfExists('admission_guides');
    }

    /**
     * @param  list<string>  $targets
     */
    private function copyNavKey(string $source, array $targets): void
    {
        $rows = DB::table('office_nav_links')->where('nav_key', $source)->get();
        foreach ($rows as $row) {
            foreach ($targets as $target) {
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
    }
};
