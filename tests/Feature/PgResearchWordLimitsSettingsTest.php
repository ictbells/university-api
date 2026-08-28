<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PgResearchWordLimitsSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_portal_info_includes_default_pg_word_limits(): void
    {
        $this->getJson('/api/portal-info')
            ->assertOk()
            ->assertJsonPath('pg_research_interest_min_words', 0)
            ->assertJsonPath('pg_research_interest_max_words', 150)
            ->assertJsonPath('pg_statement_of_purpose_min_words', 0)
            ->assertJsonPath('pg_statement_of_purpose_max_words', 500);
    }

    public function test_staff_can_update_pg_word_limits_from_application_settings(): void
    {
        Sanctum::actingAs($this->staffUser(['settings.manage']));

        $this->putJson('/api/security-settings', [
            'pg_research_interest_min_words' => 20,
            'pg_research_interest_max_words' => 80,
            'pg_statement_of_purpose_min_words' => 50,
            'pg_statement_of_purpose_max_words' => 300,
        ])
            ->assertOk()
            ->assertJsonPath('pg_research_interest_min_words', 20)
            ->assertJsonPath('pg_research_interest_max_words', 80)
            ->assertJsonPath('pg_statement_of_purpose_min_words', 50)
            ->assertJsonPath('pg_statement_of_purpose_max_words', 300);

        $this->getJson('/api/portal-info')
            ->assertOk()
            ->assertJsonPath('pg_research_interest_max_words', 80)
            ->assertJsonPath('pg_statement_of_purpose_max_words', 300);
    }

    public function test_updating_pg_word_limits_requires_settings_manage(): void
    {
        Sanctum::actingAs($this->staffUser([]));

        $this->putJson('/api/security-settings', [
            'pg_research_interest_max_words' => 80,
        ])->assertForbidden();
    }

    /**
     * @param  list<string>  $permissions
     */
    private function staffUser(array $permissions): User
    {
        foreach ($permissions as $key) {
            Permission::query()->updateOrCreate(
                ['key' => $key],
                ['module' => 'admin', 'label' => $key],
            );
        }

        $role = Role::query()->create([
            'name' => 'Settings tester',
            'slug' => 'settings-tester-'.Str::lower(Str::random(8)),
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
