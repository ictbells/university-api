<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\AcademicTerm;
use App\Models\Intake;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApplicationSessionsFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_application_sessions_are_scoped_to_channel_entry_modes(): void
    {
        $term = $this->term();
        $utme = $this->intake($term, 'UTME 2025/2026', 'utme');
        $this->intake($term, 'JUPEB 2025/2026', 'jupeb');
        $pg = $this->intake($term, 'PG 2025/2026', 'pg');

        Sanctum::actingAs($this->staffUser(['admissions.view']));

        $this->getJson('/api/applications/sessions?entry_modes=utme,de,transfer')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $utme->id)
            ->assertJsonPath('0.entry_mode', 'utme');

        $this->getJson('/api/applications/sessions?entry_modes=jupeb')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.entry_mode', 'jupeb');

        $this->getJson('/api/applications/sessions?entry_modes=pg')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $pg->id)
            ->assertJsonPath('0.entry_mode', 'pg');
    }

    private function term(): AcademicTerm
    {
        $session = AcademicSession::query()->create(['label' => '2025/2026']);

        return AcademicTerm::query()->create([
            'academic_session_id' => $session->id,
            'name' => 'First',
            'session_label' => '2025/2026',
            'is_current' => true,
        ]);
    }

    private function intake(AcademicTerm $term, string $name, string $entryMode): Intake
    {
        return Intake::query()->create([
            'academic_term_id' => $term->id,
            'name' => $name,
            'entry_mode' => $entryMode,
            'is_open' => true,
            'application_fee_amount' => 5000,
            'opens_on' => now()->subDay()->toDateString(),
            'closes_on' => now()->addMonth()->toDateString(),
        ]);
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
