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
        $ugTerm = $this->term('2025/2026');
        $pgTerm = $this->term('2024/2025');
        $utme = $this->intake($ugTerm, 'UTME', 'utme');
        $this->intake($ugTerm, 'JUPEB', 'jupeb');
        $pg = $this->intake($pgTerm, 'PG', 'pg');

        Sanctum::actingAs($this->staffUser(['admissions.view']));

        $this->getJson('/api/applications/sessions?entry_modes=utme,de,transfer')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $utme->academicSessionId())
            ->assertJsonPath('0.session_label', '2025/2026');

        $this->getJson('/api/applications/sessions?entry_modes=jupeb')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.session_label', '2025/2026');

        $this->getJson('/api/applications/sessions?entry_modes=pg')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $pg->academicSessionId())
            ->assertJsonPath('0.session_label', '2024/2025');
    }

    private function term(string $label): AcademicTerm
    {
        $session = AcademicSession::query()->create(['label' => $label]);

        return AcademicTerm::query()->create([
            'academic_session_id' => $session->id,
            'name' => 'First',
            'session_label' => $label,
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
