<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\AcademicTerm;
use App\Models\Application;
use App\Models\Intake;
use App\Models\OfficeDepartment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdmissionCategoryReuseTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private AcademicSession $firstSession;

    private AcademicTerm $firstTerm;

    private AcademicSession $nextSession;

    private AcademicTerm $nextTerm;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (PermissionCatalog::all() as $perm) {
            Permission::query()->updateOrCreate(['key' => $perm['key']], $perm);
        }

        $role = Role::query()->create(['name' => 'Registrar', 'slug' => 'registrar-reuse']);
        $role->permissions()->sync(
            Permission::query()->whereIn('key', [
                'academic.intakes.manage',
                'admissions.view',
            ])->pluck('id'),
        );

        $this->staff = User::factory()->create(['status' => 'active']);
        $this->staff->roles()->attach($role->id);
        $office = OfficeDepartment::query()->create([
            'name' => 'Academic Affairs',
            'code' => 'AA-REUSE',
            'is_active' => true,
        ]);
        $office->syncNavKeys(['intakes']);
        Staff::query()->create([
            'user_id' => $this->staff->id,
            'staff_number' => 'STF-REUSE-001',
            'office_department_id' => $office->id,
        ]);

        $this->firstSession = AcademicSession::query()->create([
            'label' => '2025/2026',
            'starts_on' => '2025-10-01',
            'ends_on' => '2026-09-30',
        ]);
        $this->firstTerm = AcademicTerm::query()->create([
            'academic_session_id' => $this->firstSession->id,
            'name' => 'First',
            'session_label' => '2025/2026',
            'is_current' => true,
        ]);
        $this->nextSession = AcademicSession::query()->create([
            'label' => '2026/2027',
            'starts_on' => '2026-10-01',
            'ends_on' => '2027-09-30',
        ]);
        $this->nextTerm = AcademicTerm::query()->create([
            'academic_session_id' => $this->nextSession->id,
            'name' => 'First',
            'session_label' => '2026/2027',
            'is_current' => false,
        ]);
    }

    public function test_cannot_create_a_second_intake_for_the_same_category(): void
    {
        $this->createUtme();
        Sanctum::actingAs($this->staff);

        $this->postJson('/api/intakes', [
            'academic_term_id' => $this->nextTerm->id,
            'name' => 'UTME 2026/2027',
            'entry_mode' => 'utme',
            'opens_on' => now()->toDateString(),
            'closes_on' => now()->addMonth()->toDateString(),
            'is_open' => true,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['entry_mode']);
    }

    public function test_cannot_change_session_while_category_is_accepting(): void
    {
        $intake = $this->createUtme();
        Sanctum::actingAs($this->staff);

        $this->patchJson('/api/intakes/'.$intake->id, [
            'academic_term_id' => $this->nextTerm->id,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['academic_term_id']);

        $this->assertSame($this->firstTerm->id, $intake->fresh()->academic_term_id);
    }

    public function test_application_keeps_snapshot_session_after_category_is_reopened(): void
    {
        $intake = $this->createUtme();
        $application = $this->applicationOn($intake);

        $this->assertSame($this->firstSession->id, $application->academic_session_id);

        Sanctum::actingAs($this->staff);
        $this->patchJson('/api/intakes/'.$intake->id, ['is_open' => false])->assertSuccessful();
        $this->patchJson('/api/intakes/'.$intake->id, [
            'academic_term_id' => $this->nextTerm->id,
            'opens_on' => now()->toDateString(),
            'closes_on' => now()->addMonth()->toDateString(),
            'is_open' => true,
        ])->assertSuccessful();

        $this->assertSame($this->nextTerm->id, $intake->fresh()->academic_term_id);
        $this->assertSame($this->firstSession->id, $application->fresh()->academic_session_id);

        $this->getJson('/api/applications?academic_session_id='.$this->firstSession->id)
            ->assertOk()
            ->assertJsonPath('data.0.id', $application->id);

        $this->getJson('/api/applications?academic_session_id='.$this->nextSession->id)
            ->assertOk()
            ->assertJsonPath('total', 0);

        $sessions = $this->getJson('/api/applications/sessions?entry_modes=utme')->assertOk()->json();
        $ids = collect($sessions)->pluck('id')->all();
        $this->assertContains($this->firstSession->id, $ids);
        $this->assertContains($this->nextSession->id, $ids);
    }

    public function test_cannot_delete_a_category_that_has_applications(): void
    {
        $intake = $this->createUtme();
        $this->applicationOn($intake);
        Sanctum::actingAs($this->staff);

        $this->deleteJson('/api/intakes/'.$intake->id)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['intake']);

        $this->assertNotNull($intake->fresh());
    }

    public function test_empty_category_can_be_deleted_and_recreated(): void
    {
        $intake = $this->createUtme();
        Sanctum::actingAs($this->staff);

        $this->deleteJson('/api/intakes/'.$intake->id)->assertNoContent();
        $this->assertNull(Intake::query()->find($intake->id));

        $this->postJson('/api/intakes', [
            'academic_term_id' => $this->firstTerm->id,
            'name' => 'UTME',
            'entry_mode' => 'utme',
            'opens_on' => now()->toDateString(),
            'closes_on' => now()->addMonth()->toDateString(),
            'is_open' => true,
        ])->assertSuccessful();
    }

    public function test_entry_mode_cannot_be_changed(): void
    {
        $intake = $this->createUtme();
        Sanctum::actingAs($this->staff);

        $this->patchJson('/api/intakes/'.$intake->id, ['entry_mode' => 'de'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['entry_mode']);

        $this->assertSame('utme', $intake->fresh()->entry_mode);
    }

    private function createUtme(): Intake
    {
        return Intake::query()->create([
            'academic_term_id' => $this->firstTerm->id,
            'name' => 'UTME',
            'entry_mode' => 'utme',
            'opens_on' => now()->subDay()->toDateString(),
            'closes_on' => now()->addMonth()->toDateString(),
            'is_open' => true,
            'application_fee_amount' => 10000,
        ]);
    }

    private function applicationOn(Intake $intake): Application
    {
        $user = User::factory()->create(['status' => 'active']);

        return Application::query()->create([
            'application_number' => 'APP-REUSE-1',
            'user_id' => $user->id,
            'intake_id' => $intake->id,
            'entry_mode' => 'utme',
            'stage' => 'submitted',
        ]);
    }
}
