<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\FeeItem;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Permission;
use App\Models\Program;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GenerateInvoiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_generate_invoice_by_matric_using_fee_items(): void
    {
        $staff = $this->financeStaff();
        [$student, $hostel, $programmeFee] = $this->studentWithHostelAndProgrammeFee();
        Sanctum::actingAs($staff);

        $catalog = $this->getJson('/api/fees?active=1&operational=1')->assertOk()->json();
        $this->assertTrue(collect($catalog)->contains(
            fn ($row) => (int) ($row['id'] ?? 0) === $hostel->id && ($row['name'] ?? '') === 'Hostel fee',
        ));
        $this->assertFalse(collect($catalog)->contains(
            fn ($row) => ($row['category'] ?? '') === 'tuition' || ($row['name'] ?? '') === 'School fees',
        ));
        $this->assertTrue(collect($catalog)->every(
            fn ($row) => ! array_key_exists('fee_item_id', $row) || $row['fee_item_id'] === null,
        ));

        $invoice = $this->postJson('/api/invoices', [
            'matric' => $student->matric_number,
            'fee_item_ids' => [$hostel->id],
        ])->assertOk()->json();

        $this->assertSame($student->id, $invoice['student_id']);
        $this->assertEquals(80000, (float) $invoice['amount']);
        $this->assertTrue(
            InvoiceItem::query()
                ->where('invoice_id', $invoice['id'])
                ->where('fee_item_id', $hostel->id)
                ->where('amount', 80000)
                ->exists()
        );
        $this->assertFalse(
            InvoiceItem::query()
                ->where('invoice_id', $invoice['id'])
                ->where('fee_item_id', $programmeFee->id)
                ->exists()
        );
        $this->assertSame(1, Invoice::query()->where('student_id', $student->id)->count());
    }

    public function test_programme_schedule_fee_items_cannot_be_invoiced_here(): void
    {
        $staff = $this->financeStaff();
        [$student, , $programmeFee] = $this->studentWithHostelAndProgrammeFee();
        Sanctum::actingAs($staff);

        $this->postJson('/api/invoices', [
            'matric' => $student->matric_number,
            'fee_item_ids' => [$programmeFee->fee_item_id],
        ])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Programme-schedule fees cannot be invoiced here. Choose operational fee items, or use the student portal for school fees.']);
    }

    public function test_unknown_matric_is_rejected(): void
    {
        Sanctum::actingAs($this->financeStaff());
        $fee = FeeItem::query()->create([
            'name' => 'Sundry',
            'category' => 'sundry',
            'amount' => 1000,
            'is_active' => true,
            'wallet_allowed' => true,
        ]);

        $this->postJson('/api/invoices', [
            'matric' => 'BUT/NOPE/0001',
            'fee_item_ids' => [$fee->id],
        ])->assertStatus(422);
    }

    private function financeStaff(): User
    {
        foreach (PermissionCatalog::all() as $perm) {
            Permission::query()->updateOrCreate(['key' => $perm['key']], $perm);
        }
        $role = Role::query()->create([
            'name' => 'Finance',
            'slug' => 'finance-generate-'.uniqid(),
            'is_system' => false,
            'is_active' => true,
        ]);
        $role->permissions()->sync(
            Permission::query()->whereIn('key', ['finance.invoices.manage'])->pluck('id'),
        );
        $user = User::factory()->create(['status' => 'active']);
        $user->roles()->attach($role->id);

        return $user->fresh(['roles.permissions']);
    }

    /**
     * @return array{0: Student, 1: FeeItem, 2: \App\Models\ProgrammeFee}
     */
    private function studentWithHostelAndProgrammeFee(): array
    {
        $campus = Campus::query()->create(['name' => 'Main', 'is_active' => true]);
        $faculty = Faculty::query()->create(['campus_id' => $campus->id, 'name' => 'Science']);
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
        $hostel = FeeItem::query()->create([
            'name' => 'Hostel fee',
            'category' => 'hostel',
            'amount' => 80000,
            'is_active' => true,
            'wallet_allowed' => true,
        ]);
        $tuition = FeeItem::query()->create([
            'name' => 'School fees',
            'category' => 'tuition',
            'amount' => 170000,
            'is_active' => true,
            'wallet_allowed' => true,
        ]);
        $programmeFee = $program->programmeFees()->create([
            'fee_item_id' => $tuition->id,
            'amount' => 155000,
            'level_code' => 'all',
            'semester' => 'both',
            'is_active' => true,
        ]);
        $user = User::factory()->create(['status' => 'active']);
        $student = Student::query()->create([
            'user_id' => $user->id,
            'program_id' => $program->id,
            'first_name' => 'Ada',
            'last_name' => 'Okoye',
            'matric_number' => 'BUT/2026/M/0101',
            'status' => 'active',
            'current_level' => '100',
        ]);

        return [$student, $hostel, $programmeFee];
    }
}
