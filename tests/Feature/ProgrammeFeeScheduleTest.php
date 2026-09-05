<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\FeeItem;
use App\Models\OfficeApprovalRequest;
use App\Models\ProgrammeFee;
use App\Models\OfficeDepartment;
use App\Models\Permission;
use App\Models\Program;
use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProgrammeFeeScheduleTest extends TestCase
{
    use RefreshDatabase;

    public function test_summaries_list_programmes_with_schedule_totals(): void
    {
        [$staff, $program, $tuition, $clinic] = $this->seedSchedule();
        Sanctum::actingAs($staff);

        $this->getJson('/api/programme-fees/summaries')
            ->assertOk()
            ->assertJsonPath('meta.programmes', 1)
            ->assertJsonPath('data.0.name', 'B.Sc Computer Science')
            ->assertJsonPath('data.0.line_count', 2)
            ->assertJsonPath('data.0.entry_modes', ['utme']);

        $this->assertEquals(55000, $this->getJson('/api/programme-fees/summaries')->json('data.0.total_amount'));

        $this->getJson('/api/programme-fees/summaries?scheduled=no')
            ->assertOk()
            ->assertJsonPath('meta.programmes', 0);
    }

    public function test_bulk_assign_accepts_multiple_fee_items(): void
    {
        [$staff, $program, $tuition, $clinic] = $this->seedSchedule(assign: false);
        Sanctum::actingAs($staff);

        $this->postJson('/api/programme-fees/bulk', [
            'program_id' => $program->id,
            'level_code' => '100',
            'semester' => 'first',
            'items' => [
                ['fee_item_id' => $tuition->id],
                ['fee_item_id' => $clinic->id, 'amount' => 4500],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('total_amount', 54500)
            ->assertJsonCount(2, 'data');
    }

    public function test_bulk_assign_applies_same_items_to_multiple_levels(): void
    {
        [$staff, $program, $tuition] = $this->seedSchedule(assign: false);
        Sanctum::actingAs($staff);

        $this->postJson('/api/programme-fees/bulk', [
            'program_id' => $program->id,
            'level_codes' => ['200', '300', '400', '500'],
            'semester' => 'both',
            'items' => [
                ['fee_item_id' => $tuition->id, 'amount' => 50000],
            ],
        ])
            ->assertOk()
            ->assertJsonCount(4, 'data');

        $this->assertEqualsCanonicalizing(
            ['200', '300', '400', '500'],
            $program->programmeFees()->pluck('level_code')->all(),
        );
        $this->assertTrue($program->programmeFees()->pluck('amount')->every(fn ($amount) => (float) $amount === 50000.0));
    }

    public function test_bulk_assign_waits_when_create_is_required_on_finance(): void
    {
        [$staff, $program, $tuition] = $this->seedSchedule(assign: false, gateCreate: true);
        Sanctum::actingAs($staff);

        $this->postJson('/api/programme-fees/bulk', [
            'program_id' => $program->id,
            'level_code' => '100',
            'semester' => 'first',
            'items' => [
                ['fee_item_id' => $tuition->id],
            ],
        ])
            ->assertStatus(202)
            ->assertJsonPath('status', 'pending_approval');

        $this->assertSame(0, $program->programmeFees()->count());
        $this->assertSame(1, OfficeApprovalRequest::query()->where('action_key', 'finance.bulk_programme_fees')->count());
    }

    public function test_bulk_assign_reuses_one_fee_item_across_installment_slices(): void
    {
        [$staff, $program, $tuition] = $this->seedSchedule(assign: false);
        Sanctum::actingAs($staff);

        $this->postJson('/api/programme-fees/bulk', [
            'program_id' => $program->id,
            'level_code' => 'all',
            'semester' => 'both',
            'items' => [
                ['fee_item_id' => $tuition->id, 'amount' => 20000, 'installment_tranche' => 1],
                ['fee_item_id' => $tuition->id, 'amount' => 20000, 'installment_tranche' => 2],
                ['fee_item_id' => $tuition->id, 'amount' => 20000, 'installment_tranche' => 3],
                ['fee_item_id' => $tuition->id, 'amount' => 20000, 'installment_tranche' => 4],
            ],
        ])
            ->assertOk()
            ->assertJsonCount(4, 'data')
            ->assertJsonPath('total_amount', 80000);

        $lines = $this->getJson('/api/programme-fees/program/'.$program->id)->json('data');
        $this->assertEqualsCanonicalizing(
            [1, 2, 3, 4],
            array_column($lines, 'effective_installment_tranche'),
        );
        $this->assertCount(1, array_unique(array_column($lines, 'fee_item_id')));
    }

    public function test_fee_catalog_keeps_installment_share_on_schedule_categories(): void
    {
        [$staff] = $this->seedSchedule(assign: false, syncNav: false);
        Sanctum::actingAs($staff);

        $this->postJson('/api/fees', [
            'name' => 'Tuition 1st 25%',
            'category' => 'tuition',
            'amount' => 10000,
            'installment_tranche' => 1,
            'is_active' => true,
        ])->assertCreated()
            ->assertJsonPath('installment_tranche', 1);

        $this->postJson('/api/fees', [
            'name' => 'ICT 2nd 25%',
            'category' => 'library',
            'amount' => 3000,
            'installment_tranche' => 2,
            'is_active' => true,
        ])->assertCreated()
            ->assertJsonPath('installment_tranche', 2);

        $this->postJson('/api/fees', [
            'name' => 'UTME application',
            'category' => 'application_fee',
            'entry_mode' => 'utme',
            'amount' => 5000,
            'installment_tranche' => 1,
            'is_active' => true,
        ])->assertCreated()
            ->assertJsonPath('installment_tranche', null)
            ->assertJsonPath('entry_mode', 'utme');

        $this->postJson('/api/fees', [
            'name' => 'Acceptance',
            'category' => 'acceptance_fee',
            'amount' => 25000,
            'is_active' => true,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['entry_mode']);

        $this->postJson('/api/fees', [
            'name' => 'UTME acceptance',
            'category' => 'acceptance_fee',
            'entry_mode' => 'utme',
            'amount' => 25000,
            'installment_tranche' => 1,
            'is_active' => true,
        ])->assertCreated()
            ->assertJsonPath('entry_mode', 'utme')
            ->assertJsonPath('installment_tranche', null);
    }

    public function test_copy_schedule_to_programmes_in_the_same_college(): void
    {
        [$staff, $program, $tuition, $clinic] = $this->seedSchedule();
        $peer = Program::query()->create([
            'department_id' => $program->department_id,
            'name' => 'B.Sc Information Technology',
            'code' => 'BSC-IT',
            'award_type' => 'B.Sc',
            'study_level' => 'undergraduate',
            'entry_modes' => ['utme'],
            'duration_years' => 4,
            'is_active' => true,
        ]);
        Sanctum::actingAs($staff);

        $this->postJson('/api/programme-fees/copy', [
            'from_program_id' => $program->id,
            'to_program_ids' => [$peer->id],
        ])
            ->assertOk()
            ->assertJsonPath('programmes', 1)
            ->assertJsonPath('copied_lines', 2);

        $copied = $this->getJson('/api/programme-fees/program/'.$peer->id)
            ->assertOk()
            ->json('data');
        $this->assertCount(2, $copied);
        $this->assertEqualsCanonicalizing(
            [$tuition->id, $clinic->id],
            array_column($copied, 'fee_item_id'),
        );
    }

    public function test_copy_schedule_rejects_a_programme_in_another_college(): void
    {
        [$staff, $program] = $this->seedSchedule();
        $otherCampus = Campus::query()->create(['name' => 'West', 'is_active' => true]);
        $otherFaculty = Faculty::query()->create(['campus_id' => $otherCampus->id, 'name' => 'College of Engineering']);
        $otherDepartment = Department::query()->create(['faculty_id' => $otherFaculty->id, 'name' => 'Civil']);
        $other = Program::query()->create([
            'department_id' => $otherDepartment->id,
            'name' => 'B.Eng Civil',
            'code' => 'BENG-CV',
            'award_type' => 'B.Eng',
            'study_level' => 'undergraduate',
            'entry_modes' => ['utme'],
            'duration_years' => 5,
            'is_active' => true,
        ]);
        Sanctum::actingAs($staff);

        $this->postJson('/api/programme-fees/copy', [
            'from_program_id' => $program->id,
            'to_program_ids' => [$other->id],
        ])->assertStatus(422);
    }

    /**
     * @return array{0: User, 1: Program, 2: FeeItem, 3: FeeItem}
     */
    private function seedSchedule(bool $assign = true, bool $syncNav = true, bool $gateCreate = false): array
    {
        foreach (PermissionCatalog::all() as $perm) {
            Permission::query()->updateOrCreate(['key' => $perm['key']], $perm);
        }

        $campus = Campus::query()->create(['name' => 'Main', 'is_active' => true]);
        $faculty = Faculty::query()->create(['campus_id' => $campus->id, 'name' => 'College of Natural Sciences']);
        $department = Department::query()->create(['faculty_id' => $faculty->id, 'name' => 'Computer Science']);
        $program = Program::query()->create([
            'department_id' => $department->id,
            'name' => 'B.Sc Computer Science',
            'code' => 'BSC-CS',
            'award_type' => 'B.Sc',
            'study_level' => 'undergraduate',
            'entry_modes' => ['utme'],
            'duration_years' => 4,
            'is_active' => true,
        ]);
        $tuition = FeeItem::query()->create([
            'name' => 'Tuition',
            'category' => 'tuition',
            'amount' => 50000,
            'is_active' => true,
        ]);
        $clinic = FeeItem::query()->create([
            'name' => 'Clinic charge',
            'category' => 'medical',
            'amount' => 5000,
            'is_active' => true,
        ]);

        if ($assign) {
            $program->programmeFees()->create([
                'fee_item_id' => $tuition->id,
                'amount' => null,
                'level_code' => 'all',
                'semester' => 'both',
                'is_active' => true,
            ]);
            $program->programmeFees()->create([
                'fee_item_id' => $clinic->id,
                'amount' => null,
                'level_code' => 'all',
                'semester' => 'both',
                'is_active' => true,
            ]);
        }

        $role = Role::query()->create([
            'name' => 'Bursary',
            'slug' => 'bursary-fees',
            'is_active' => true,
        ]);
        $role->permissions()->sync(
            Permission::query()->where('key', 'finance.invoices.manage')->pluck('id'),
        );
        $office = OfficeDepartment::query()->create([
            'name' => 'Bursary',
            'code' => 'BUR',
            'is_active' => true,
        ]);
        if ($syncNav) {
            $office->syncNavLinks([[
                'key' => 'finance',
                'require_create' => $gateCreate,
                'require_update' => false,
                'require_delete' => false,
                'approval_chain' => 'both',
            ]]);
        }
        $hod = User::factory()->create(['email' => 'bursary.hod@example.com']);
        $hodStaff = Staff::query()->create([
            'user_id' => $hod->id,
            'staff_number' => 'ST-HOD-FEE',
            'office_department_id' => $office->id,
        ]);
        $office->update(['head_staff_id' => $hodStaff->id]);
        $user = User::factory()->create();
        $user->roles()->attach($role->id);
        Staff::query()->create([
            'user_id' => $user->id,
            'staff_number' => 'ST-FEE',
            'office_department_id' => $office->id,
        ]);

        return [$user->fresh(['roles.permissions', 'staff']), $program, $tuition, $clinic];
    }

    public function test_bulk_store_restores_soft_deleted_programme_fee_row(): void
    {
        [$staff, $program, $tuition] = $this->seedSchedule(assign: false, syncNav: false);
        Sanctum::actingAs($staff);

        // Create the row once.
        $this->postJson('/api/programme-fees/bulk', [
            'program_id' => $program->id,
            'level_code' => '100 Level',
            'semester' => 'both',
            'items' => [
                ['fee_item_id' => $tuition->id, 'amount' => 100000, 'installment_tranche' => 2],
            ],
        ])->assertOk();

        $firstId = ProgrammeFee::query()
            ->where(['program_id' => $program->id, 'fee_item_id' => $tuition->id])
            ->value('id');
        $this->assertNotNull($firstId);

        // Soft-delete it directly (simulates a staff delete or office-gate replay race).
        ProgrammeFee::query()->whereKey($firstId)->delete();
        $this->assertSoftDeleted('programme_fees', ['id' => $firstId]);

        // Bulk-store the same key again — must restore instead of colliding.
        $this->postJson('/api/programme-fees/bulk', [
            'program_id' => $program->id,
            'level_code' => '100 Level',
            'semester' => 'both',
            'items' => [
                ['fee_item_id' => $tuition->id, 'amount' => 120000, 'installment_tranche' => 2],
            ],
        ])->assertOk();

        // Row restored with the same id, updated amount, no duplicate.
        $this->assertDatabaseHas('programme_fees', [
            'id' => $firstId,
            'amount' => 120000,
            'deleted_at' => null,
        ]);
        $this->assertSame(
            1,
            ProgrammeFee::withTrashed()
                ->where(['program_id' => $program->id, 'fee_item_id' => $tuition->id, 'level_code' => '100 Level', 'installment_tranche' => 2])
                ->count()
        );
    }

    public function test_single_store_restores_soft_deleted_programme_fee_row(): void
    {
        [$staff, $program, $tuition] = $this->seedSchedule(assign: false, syncNav: false);
        Sanctum::actingAs($staff);

        $pf = ProgrammeFee::query()->create([
            'program_id' => $program->id,
            'fee_item_id' => $tuition->id,
            'level_code' => '100 Level',
            'semester' => 'both',
            'installment_tranche' => 2,
            'amount' => 100000,
            'is_active' => true,
        ]);
        $pf->delete();
        $this->assertSoftDeleted('programme_fees', ['id' => $pf->id]);

        $this->postJson('/api/programme-fees', [
            'program_id' => $program->id,
            'fee_item_id' => $tuition->id,
            'level_code' => '100 Level',
            'semester' => 'both',
            'installment_tranche' => 2,
            'amount' => 120000,
            'is_active' => true,
        ])->assertOk();

        $this->assertDatabaseHas('programme_fees', [
            'id' => $pf->id,
            'amount' => 120000,
            'deleted_at' => null,
        ]);
        $this->assertSame(
            1,
            ProgrammeFee::withTrashed()
                ->where(['program_id' => $program->id, 'fee_item_id' => $tuition->id, 'level_code' => '100 Level', 'installment_tranche' => 2])
                ->count()
        );
    }
}
