<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\AcademicTerm;
use App\Models\Intake;
use App\Models\OfficeDepartment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use App\Services\AcademicCalendarService;
use App\Support\AdmissionCurrentGate;
use App\Support\PermissionCatalog;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdmissionCurrentGateTest extends TestCase
{
    use RefreshDatabase;

    private User $staffUser;

    private AcademicSession $previousSession;

    private AcademicTerm $previousTerm;

    private AcademicSession $newSession;

    private AcademicTerm $newTerm;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (PermissionCatalog::all() as $perm) {
            Permission::query()->updateOrCreate(['key' => $perm['key']], $perm);
        }

        $role = Role::query()->create(['name' => 'Registrar', 'slug' => 'registrar']);
        $role->permissions()->sync(
            Permission::query()->whereIn('key', ['academic.sessions.manage'])->pluck('id'),
        );

        $this->staffUser = User::factory()->create(['status' => 'active']);
        $this->staffUser->roles()->attach($role->id);
        $office = OfficeDepartment::query()->create([
            'name' => 'Academic Affairs',
            'code' => 'AA',
            'is_active' => true,
        ]);
        $office->syncNavKeys(['sessions']);
        Staff::query()->create([
            'user_id' => $this->staffUser->id,
            'staff_number' => 'STF-GATE-001',
            'office_department_id' => $office->id,
        ]);

        $this->previousSession = AcademicSession::query()->create([
            'label' => '2024/2025',
            'starts_on' => '2024-10-01',
            'ends_on' => '2025-09-30',
        ]);
        $this->previousTerm = AcademicTerm::query()->create([
            'academic_session_id' => $this->previousSession->id,
            'name' => 'First',
            'session_label' => '2024/2025',
            'is_current' => true,
        ]);

        $this->newSession = AcademicSession::query()->create([
            'label' => '2025/2026',
            'starts_on' => '2025-10-01',
            'ends_on' => '2026-09-30',
        ]);
        $this->newTerm = AcademicTerm::query()->create([
            'academic_session_id' => $this->newSession->id,
            'name' => 'First',
            'session_label' => '2025/2026',
            'is_current' => false,
            'auto_schedule' => true,
            'starts_on' => now()->subDay()->toDateString(),
            'ends_on' => now()->addMonths(4)->toDateString(),
        ]);
        AcademicTerm::query()->create([
            'academic_session_id' => $this->newSession->id,
            'name' => 'Second',
            'session_label' => '2025/2026',
            'is_current' => false,
        ]);
    }

    public function test_create_session_with_no_current_keeps_previous_current(): void
    {
        Sanctum::actingAs($this->staffUser);

        $this->postJson('/api/academic/sessions', [
            'label' => '2026/2027',
            'semesters' => [
                ['name' => 'First', 'is_current' => false],
                ['name' => 'Second', 'is_current' => false],
            ],
        ])->assertSuccessful();

        $this->assertTrue((bool) $this->previousTerm->fresh()->is_current);
        $this->assertFalse(
            AcademicTerm::query()->where('session_label', '2026/2027')->where('is_current', true)->exists()
        );
    }

    public function test_cannot_set_term_current_while_intake_accepting(): void
    {
        Intake::query()->create([
            'academic_term_id' => $this->newTerm->id,
            'name' => 'UTME 2025/2026',
            'entry_mode' => 'utme',
            'opens_on' => now()->subDay()->toDateString(),
            'closes_on' => now()->addMonth()->toDateString(),
            'is_open' => true,
            'application_fee_amount' => 10000,
        ]);

        Sanctum::actingAs($this->staffUser);

        $this->patchJson("/api/terms/{$this->newTerm->id}", ['is_current' => true])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['is_current']);

        $this->assertTrue((bool) $this->previousTerm->fresh()->is_current);
        $this->assertFalse((bool) $this->newTerm->fresh()->is_current);
        $this->assertFalse(AdmissionCurrentGate::canSetCurrent($this->newTerm));
    }

    public function test_can_set_term_current_after_stop_accepting(): void
    {
        Intake::query()->create([
            'academic_term_id' => $this->newTerm->id,
            'name' => 'UTME 2025/2026',
            'entry_mode' => 'utme',
            'opens_on' => now()->subMonth()->toDateString(),
            'closes_on' => now()->subDay()->toDateString(),
            'is_open' => false,
            'application_fee_amount' => 10000,
        ]);

        Sanctum::actingAs($this->staffUser);

        $this->patchJson("/api/terms/{$this->newTerm->id}", ['is_current' => true])
            ->assertSuccessful();

        $this->assertFalse((bool) $this->previousTerm->fresh()->is_current);
        $this->assertTrue((bool) $this->newTerm->fresh()->is_current);
    }

    public function test_auto_calendar_skips_activation_while_intakes_accepting(): void
    {
        Intake::query()->create([
            'academic_term_id' => $this->newTerm->id,
            'name' => 'UTME 2025/2026',
            'entry_mode' => 'utme',
            'opens_on' => now()->subDay()->toDateString(),
            'closes_on' => now()->addMonth()->toDateString(),
            'is_open' => true,
            'application_fee_amount' => 10000,
        ]);

        $result = app(AcademicCalendarService::class)->sync(Carbon::today());

        $this->assertNull($result['opened']);
        $this->assertTrue((bool) $this->previousTerm->fresh()->is_current);
        $this->assertFalse((bool) $this->newTerm->fresh()->is_current);
    }

    public function test_sessions_list_exposes_can_set_current_flag(): void
    {
        Intake::query()->create([
            'academic_term_id' => $this->newTerm->id,
            'name' => 'UTME 2025/2026',
            'entry_mode' => 'utme',
            'opens_on' => now()->subDay()->toDateString(),
            'closes_on' => now()->addMonth()->toDateString(),
            'is_open' => true,
            'application_fee_amount' => 10000,
        ]);

        Sanctum::actingAs($this->staffUser);

        $rows = $this->getJson('/api/academic/sessions')->assertSuccessful()->json();
        $new = collect($rows)->firstWhere('id', $this->newSession->id);

        $this->assertNotNull($new);
        $this->assertFalse($new['can_set_current']);
        $this->assertContains('UTME 2025/2026', $new['accepting_application_sessions']);
    }
}
