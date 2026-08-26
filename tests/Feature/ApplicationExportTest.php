<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApplicationExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_excel_export_succeeds_when_institution_logo_is_present(): void
    {
        $logoDir = public_path('images');
        File::ensureDirectoryExists($logoDir);
        $logoPath = $logoDir.DIRECTORY_SEPARATOR.'logo.png';
        $createdLogo = ! is_file($logoPath);
        if ($createdLogo) {
            File::put($logoPath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
        }

        try {
            Sanctum::actingAs($this->staffUser(['admissions.view']));

            $this->get('/api/applications/export?format=excel&title=Undergraduate+applications&entry_modes=utme,de,transfer')
                ->assertOk()
                ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        } finally {
            if ($createdLogo) {
                File::delete($logoPath);
            }
        }
    }

    /**
     * @param  list<string>  $permissions
     */
    private function staffUser(array $permissions): User
    {
        foreach ($permissions as $key) {
            Permission::query()->updateOrCreate(
                ['key' => $key],
                ['module' => 'admissions', 'label' => $key],
            );
        }

        $role = Role::query()->create([
            'name' => 'Admissions tester',
            'slug' => 'admissions-tester-'.Str::lower(Str::random(8)),
            'is_system' => false,
            'is_active' => true,
        ]);
        $role->permissions()->sync(
            Permission::query()->whereIn('key', $permissions)->pluck('id')
        );

        $user = User::factory()->create();
        $user->roles()->attach($role->id);

        return $user->fresh(['roles.permissions']);
    }
}
