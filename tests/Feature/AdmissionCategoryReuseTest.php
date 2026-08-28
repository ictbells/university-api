<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\AcademicTerm;
use App\Models\Application;
use App\Models\FeeItem;
use App\Models\Intake;
use App\Models\OfficeDepartment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use App\Services\InvoiceService;
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

    public function test_staff_can_stop_and_resume_accepting_applications(): void
    {
        $intake = $this->createUtme();
        Sanctum::actingAs($this->staff);

        $this->getJson('/api/academic/intakes')
            ->assertOk()
            ->assertJsonPath('0.is_open', true)
            ->assertJsonPath('0.is_accepting', true);

        $this->patchJson('/api/intakes/'.$intake->id, ['is_open' => false])
            ->assertSuccessful()
            ->assertJsonPath('is_open', false);

        $this->getJson('/api/academic/intakes')
            ->assertOk()
            ->assertJsonPath('0.is_open', false)
            ->assertJsonPath('0.is_accepting', false);

        $this->patchJson('/api/intakes/'.$intake->id, ['is_open' => true])
            ->assertSuccessful()
            ->assertJsonPath('is_open', true);

        $this->getJson('/api/academic/intakes')
            ->assertOk()
            ->assertJsonPath('0.is_open', true)
            ->assertJsonPath('0.is_accepting', true);
    }

    public function test_can_assign_a_new_session_when_opening_applications(): void
    {
        $intake = $this->createUtme();
        Sanctum::actingAs($this->staff);

        $this->patchJson('/api/intakes/'.$intake->id, ['is_open' => false])->assertSuccessful();

        $this->patchJson('/api/intakes/'.$intake->id, [
            'academic_term_id' => $this->nextTerm->id,
            'opens_on' => now()->toDateString(),
            'closes_on' => now()->addMonth()->toDateString(),
            'is_open' => true,
        ])->assertSuccessful();

        $moved = $intake->fresh();
        $this->assertSame($this->nextTerm->id, $moved->academic_term_id);
        $this->assertTrue((bool) $moved->is_open);
        $this->assertTrue($moved->isAcceptingApplications());

        $this->getJson('/api/academic/intakes')
            ->assertOk()
            ->assertJsonPath('0.academic_term_id', $this->nextTerm->id)
            ->assertJsonPath('0.is_accepting', true);
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

    public function test_catalog_application_and_acceptance_fees_are_reused_on_the_next_session(): void
    {
        $this->seedUtmeCatalogFees(12000, 25000);
        $intake = $this->createUtme();
        $invoices = app(InvoiceService::class);

        $this->assertSame(12000.0, $invoices->resolveApplicationFeeAmount($intake));
        $this->assertSame(25000.0, $invoices->resolveAcceptanceFeeAmount($intake));

        Sanctum::actingAs($this->staff);
        $this->patchJson('/api/intakes/'.$intake->id, ['is_open' => false])->assertSuccessful();
        $this->patchJson('/api/intakes/'.$intake->id, [
            'academic_term_id' => $this->nextTerm->id,
            'opens_on' => now()->toDateString(),
            'closes_on' => now()->addMonth()->toDateString(),
            'is_open' => true,
        ])->assertSuccessful();

        $moved = $intake->fresh();
        $this->assertSame($this->nextTerm->id, $moved->academic_term_id);
        $this->assertSame(12000.0, $invoices->resolveApplicationFeeAmount($moved));
        $this->assertSame(25000.0, $invoices->resolveAcceptanceFeeAmount($moved));

        $this->getJson('/api/academic/intakes')
            ->assertOk()
            ->assertJsonPath('0.resolved_application_fee_amount', 12000)
            ->assertJsonPath('0.resolved_acceptance_fee_amount', 25000)
            ->assertJsonPath('0.is_accepting', true);

        $user = User::factory()->create();
        $application = $this->applicationOn($moved);
        $invoice = $invoices->createApplicationFeeInvoice($user, $moved, $application->id);
        $this->assertSame(12000.0, (float) $invoice->amount);
        $acceptance = $invoices->createAcceptanceFeeInvoice($user, $moved, $application->id);
        $this->assertSame(25000.0, (float) $acceptance->amount);
    }

    public function test_recreated_empty_category_still_uses_existing_catalog_fees(): void
    {
        $this->seedUtmeCatalogFees(8000, 20000);
        $intake = $this->createUtme();
        Sanctum::actingAs($this->staff);

        $this->deleteJson('/api/intakes/'.$intake->id)->assertNoContent();

        $this->postJson('/api/intakes', [
            'academic_term_id' => $this->nextTerm->id,
            'name' => 'UTME 2026/2027',
            'entry_mode' => 'utme',
            'opens_on' => now()->toDateString(),
            'closes_on' => now()->addMonth()->toDateString(),
            'is_open' => true,
        ])->assertSuccessful();

        $recreated = Intake::query()->where('entry_mode', 'utme')->firstOrFail();
        $invoices = app(InvoiceService::class);
        $this->assertSame(8000.0, $invoices->resolveApplicationFeeAmount($recreated));
        $this->assertSame(20000.0, $invoices->resolveAcceptanceFeeAmount($recreated));
    }

    private function seedUtmeCatalogFees(float $application, float $acceptance): void
    {
        FeeItem::query()->create([
            'name' => 'UTME application fee',
            'category' => 'application_fee',
            'entry_mode' => 'utme',
            'amount' => $application,
            'wallet_allowed' => false,
            'is_active' => true,
            'is_required' => true,
            'display_order' => 0,
        ]);
        FeeItem::query()->create([
            'name' => 'UTME acceptance fee',
            'category' => 'acceptance_fee',
            'entry_mode' => 'utme',
            'amount' => $acceptance,
            'wallet_allowed' => false,
            'is_active' => true,
            'is_required' => true,
            'display_order' => 0,
        ]);
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
