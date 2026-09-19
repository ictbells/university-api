<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\AcademicTerm;
use App\Models\Campus;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\FeeCategory;
use App\Models\FeeItem;
use App\Models\Invoice;
use App\Models\OfficeDepartment;
use App\Models\Permission;
use App\Models\Program;
use App\Models\Role;
use App\Models\Staff;
use App\Models\Student;
use App\Models\User;
use App\Models\Wallet;
use App\Support\PermissionCatalog;
use App\Support\SemesterFeeAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SemesterFeeTest extends TestCase
{
    use RefreshDatabase;

    public function test_generate_creates_invoices_for_active_students_only(): void
    {
        $staff = $this->financeStaff();
        [$term, $fee] = $this->seedSemesterFeeCatalog(5000);
        $active = $this->makeStudent('BUT/2026/S/0001', 'active');
        $inactive = $this->makeStudent('BUT/2026/S/0002', 'withdrawn');
        User::factory()->create(['status' => 'active']); // applicant only

        Sanctum::actingAs($staff);
        $payload = $this->postJson('/api/finance/semester-fee/generate', [
            'academic_term_id' => $term->id,
        ])
            ->assertOk()
            ->json();

        $this->assertSame(1, $payload['created']);
        $this->assertSame(0, $payload['skipped']);
        $this->assertEquals(5000.0, $payload['amount']);
        $this->assertSame($fee->id, $payload['fee_item_id']);

        $this->assertDatabaseHas('invoices', [
            'student_id' => $active->id,
            'category' => 'semester_fee',
            'academic_term_id' => $term->id,
            'amount' => 5000,
            'status' => 'unpaid',
        ]);
        $this->assertDatabaseMissing('invoices', [
            'student_id' => $inactive->id,
            'category' => 'semester_fee',
        ]);
    }

    public function test_generate_is_idempotent_for_same_term(): void
    {
        $staff = $this->financeStaff();
        [$term] = $this->seedSemesterFeeCatalog(2500);
        $this->makeStudent('BUT/2026/S/0010', 'active');

        Sanctum::actingAs($staff);
        $this->postJson('/api/finance/semester-fee/generate', ['academic_term_id' => $term->id])
            ->assertOk()
            ->assertJsonPath('created', 1);

        $second = $this->postJson('/api/finance/semester-fee/generate', ['academic_term_id' => $term->id])
            ->assertOk()
            ->json();

        $this->assertSame(0, $second['created']);
        $this->assertGreaterThanOrEqual(1, $second['skipped']);
        $this->assertSame(1, Invoice::query()->where('category', 'semester_fee')->count());
    }

    public function test_new_term_creates_fresh_invoices(): void
    {
        $staff = $this->financeStaff();
        [$firstTerm] = $this->seedSemesterFeeCatalog(3000);
        $student = $this->makeStudent('BUT/2026/S/0020', 'active');

        Sanctum::actingAs($staff);
        $this->postJson('/api/finance/semester-fee/generate', ['academic_term_id' => $firstTerm->id])
            ->assertOk()
            ->assertJsonPath('created', 1);

        $session = $firstTerm->session;
        $secondTerm = AcademicTerm::query()->create([
            'academic_session_id' => $session->id,
            'name' => 'Second',
            'session_label' => $session->label,
            'is_current' => false,
        ]);

        $this->postJson('/api/finance/semester-fee/generate', ['academic_term_id' => $secondTerm->id])
            ->assertOk()
            ->assertJsonPath('created', 1);

        $this->assertSame(2, Invoice::query()
            ->where('student_id', $student->id)
            ->where('category', 'semester_fee')
            ->count());
    }

    public function test_unpaid_semester_fee_blocks_other_wallet_payments_but_allows_semester_fee(): void
    {
        [$term] = $this->seedSemesterFeeCatalog(4000);
        $student = $this->makeStudent('BUT/2026/S/0030', 'active', 20000);

        $semester = Invoice::query()->create([
            'number' => 'INV-SEM-1',
            'user_id' => $student->user_id,
            'student_id' => $student->id,
            'category' => 'semester_fee',
            'amount' => 4000,
            'full_amount' => 4000,
            'balance' => 4000,
            'status' => 'unpaid',
            'wallet_allowed' => true,
            'academic_session_id' => $term->academic_session_id,
            'academic_term_id' => $term->id,
        ]);
        $hostel = Invoice::query()->create([
            'number' => 'INV-HOSTEL-1',
            'user_id' => $student->user_id,
            'student_id' => $student->id,
            'category' => 'hostel',
            'amount' => 1000,
            'full_amount' => 1000,
            'balance' => 1000,
            'status' => 'unpaid',
            'wallet_allowed' => true,
            'academic_session_id' => $term->academic_session_id,
        ]);

        Sanctum::actingAs($student->user);
        $this->postJson('/api/wallet/pay/'.$hostel->id)
            ->assertStatus(422)
            ->assertJsonPath('message', SemesterFeeAccess::BLOCKED_MESSAGE);

        $this->postJson('/api/wallet/pay/'.$semester->id)
            ->assertOk()
            ->assertJsonPath('status', 'paid');

        $this->postJson('/api/wallet/pay/'.$hostel->id)
            ->assertOk()
            ->assertJsonPath('status', 'paid');
    }

    public function test_tuition_installment_blocked_until_semester_fee_paid(): void
    {
        [$term] = $this->seedSemesterFeeCatalog(2000);
        $student = $this->studentOnProgrammeWithSchoolFees(10000);
        Wallet::query()->where('student_id', $student->id)->update(['balance' => 50000]);

        Invoice::query()->create([
            'number' => 'INV-SEM-BLOCK',
            'user_id' => $student->user_id,
            'student_id' => $student->id,
            'category' => 'semester_fee',
            'amount' => 2000,
            'full_amount' => 2000,
            'balance' => 2000,
            'status' => 'unpaid',
            'wallet_allowed' => true,
            'academic_session_id' => $term->academic_session_id,
            'academic_term_id' => $term->id,
        ]);

        Sanctum::actingAs($student->user);
        $this->postJson('/api/invoices/tuition-installment', ['installment_percent' => 25])
            ->assertStatus(422)
            ->assertJsonPath('message', SemesterFeeAccess::INSTALLMENT_BLOCKED_MESSAGE);

        $schedule = $this->getJson('/api/my-programme-fees')->assertOk()->json();
        $this->assertTrue($schedule['semester_fee_required']);
        $this->assertSame([], $schedule['available_installment_percents']);
    }

    public function test_late_student_gets_semester_fee_without_bulk_generate(): void
    {
        $this->seedSemesterFeeCatalog(3500);
        $student = $this->makeStudent('BUT/2026/S/0050', 'active', 10000);

        Sanctum::actingAs($student->user);
        $schedule = $this->getJson('/api/my-programme-fees')->assertOk()->json();
        $this->assertTrue($schedule['semester_fee_required']);
        $this->assertNotNull($schedule['semester_fee_invoice_id']);
        $this->assertEquals(3500.0, $schedule['semester_fee_balance']);

        $this->assertDatabaseHas('invoices', [
            'student_id' => $student->id,
            'category' => 'semester_fee',
            'amount' => 3500,
            'status' => 'unpaid',
        ]);

        $history = $this->getJson('/api/transactions')->assertOk()->json();
        $rows = $history['data'] ?? $history;
        $this->assertTrue(collect($rows)->contains(fn ($row) => ($row['category'] ?? null) === 'semester_fee'));
    }

    public function test_other_payments_blocked_when_catalog_amount_set_even_without_bulk_generate(): void
    {
        $this->seedSemesterFeeCatalog(1500);
        $student = $this->makeStudent('BUT/2026/S/0040', 'active', 5000);

        $hostel = Invoice::query()->create([
            'number' => 'INV-HOSTEL-FREE',
            'user_id' => $student->user_id,
            'student_id' => $student->id,
            'category' => 'hostel',
            'amount' => 1000,
            'full_amount' => 1000,
            'balance' => 1000,
            'status' => 'unpaid',
            'wallet_allowed' => true,
        ]);

        Sanctum::actingAs($student->user);
        $this->postJson('/api/wallet/pay/'.$hostel->id)
            ->assertStatus(422)
            ->assertJsonPath('message', SemesterFeeAccess::BLOCKED_MESSAGE);

        $semesterId = (int) $this->getJson('/api/my-programme-fees')->json('semester_fee_invoice_id');
        $this->postJson('/api/wallet/pay/'.$semesterId)->assertOk();
        $this->postJson('/api/wallet/pay/'.$hostel->id)->assertOk()->assertJsonPath('status', 'paid');
    }

    public function test_zero_amount_catalog_does_not_auto_bill(): void
    {
        $this->seedSemesterFeeCatalog(0);
        $student = $this->makeStudent('BUT/2026/S/0041', 'active', 5000);

        $hostel = Invoice::query()->create([
            'number' => 'INV-HOSTEL-ZERO',
            'user_id' => $student->user_id,
            'student_id' => $student->id,
            'category' => 'hostel',
            'amount' => 1000,
            'full_amount' => 1000,
            'balance' => 1000,
            'status' => 'unpaid',
            'wallet_allowed' => true,
        ]);

        Sanctum::actingAs($student->user);
        $this->postJson('/api/wallet/pay/'.$hostel->id)
            ->assertOk()
            ->assertJsonPath('status', 'paid');
        $this->assertSame(0, Invoice::query()->where('student_id', $student->id)->where('category', 'semester_fee')->count());
    }

    public function test_legacy_semester_fee_without_term_still_blocks_tuition_payment(): void
    {
        $this->seedSemesterFeeCatalog(2000);
        $student = $this->makeStudent('BUT/2026/S/0060', 'active', 20000);

        Invoice::query()->create([
            'number' => 'INV-SEM-LEGACY',
            'user_id' => $student->user_id,
            'student_id' => $student->id,
            'category' => 'semester_fee',
            'amount' => 2000,
            'full_amount' => 2000,
            'balance' => 2000,
            'status' => 'unpaid',
            'wallet_allowed' => true,
            'academic_term_id' => null,
        ]);
        $tuition = Invoice::query()->create([
            'number' => 'INV-TUITION-LEGACY',
            'user_id' => $student->user_id,
            'student_id' => $student->id,
            'category' => 'tuition',
            'amount' => 5000,
            'full_amount' => 5000,
            'balance' => 5000,
            'status' => 'unpaid',
            'wallet_allowed' => true,
        ]);

        Sanctum::actingAs($student->user);
        $this->postJson('/api/wallet/pay/'.$tuition->id)
            ->assertStatus(422)
            ->assertJsonPath('message', SemesterFeeAccess::BLOCKED_MESSAGE);
    }

    public function test_wallet_topup_remains_allowed_while_semester_fee_is_unpaid(): void
    {
        $this->seedSemesterFeeCatalog(2000);
        $student = $this->makeStudent('BUT/2026/S/0061', 'active', 0);

        Invoice::query()->create([
            'number' => 'INV-SEM-TOPUP',
            'user_id' => $student->user_id,
            'student_id' => $student->id,
            'category' => 'semester_fee',
            'amount' => 2000,
            'full_amount' => 2000,
            'balance' => 2000,
            'status' => 'unpaid',
            'wallet_allowed' => true,
            'academic_term_id' => AcademicTerm::current()?->id,
        ]);

        Sanctum::actingAs($student->user);
        $this->postJson('/api/wallet/topup', ['amount' => 5000, 'portal' => 'student'])
            ->assertOk();
        $this->postJson('/api/payments/initialize', [
            'type' => 'wallet_topup',
            'amount' => 3000,
            'portal' => 'student',
        ])->assertOk();
    }

    public function test_generate_rejects_zero_amount_catalog(): void
    {
        $staff = $this->financeStaff();
        [$term] = $this->seedSemesterFeeCatalog(0);

        Sanctum::actingAs($staff);
        $this->postJson('/api/finance/semester-fee/generate', ['academic_term_id' => $term->id])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Semester fee amount must be greater than zero. Update the fee catalog amount.']);
    }

    /**
     * @return array{0: AcademicTerm, 1: FeeItem}
     */
    private function seedSemesterFeeCatalog(float $amount): array
    {
        FeeCategory::query()->updateOrCreate(
            ['code' => 'semester_fee'],
            [
                'name' => 'Semester fee',
                'description' => 'University-wide flat charge',
                'is_schedule' => false,
                'is_system' => true,
                'is_active' => true,
                'display_order' => 99,
            ],
        );

        $session = AcademicSession::query()->create([
            'label' => '2025/2026',
            'starts_on' => '2025-10-01',
            'ends_on' => '2026-09-30',
        ]);
        $term = AcademicTerm::query()->create([
            'academic_session_id' => $session->id,
            'name' => 'First',
            'session_label' => '2025/2026',
            'is_current' => true,
        ]);

        $fee = FeeItem::query()->updateOrCreate(
            ['category' => 'semester_fee', 'name' => 'Semester fee'],
            [
                'amount' => $amount,
                'wallet_allowed' => true,
                'is_active' => true,
                'is_required' => true,
            ],
        );

        return [$term, $fee];
    }

    private function makeStudent(string $matric, string $status, float $wallet = 0): Student
    {
        $user = User::factory()->create(['status' => 'active']);
        $student = Student::query()->create([
            'user_id' => $user->id,
            'first_name' => 'Test',
            'last_name' => 'Student',
            'matric_number' => $matric,
            'status' => $status,
            'current_level' => '100',
        ]);
        Wallet::query()->create(['student_id' => $student->id, 'balance' => $wallet]);

        return $student->fresh(['user']);
    }

    private function studentOnProgrammeWithSchoolFees(float $amount): Student
    {
        $campus = Campus::query()->create(['name' => 'Main', 'is_active' => true]);
        $faculty = Faculty::query()->create(['campus_id' => $campus->id, 'name' => 'Science']);
        $department = Department::query()->create(['faculty_id' => $faculty->id, 'name' => 'Computer Science']);
        $program = Program::query()->create([
            'department_id' => $department->id,
            'name' => 'B.Sc Computer Science',
            'code' => 'BSC-CS-SEM',
            'award_type' => 'B.Sc',
            'study_level' => 'undergraduate',
            'entry_modes' => ['utme'],
            'duration_years' => 4,
            'is_active' => true,
        ]);
        $fee = FeeItem::query()->create([
            'name' => 'School fees',
            'category' => 'tuition',
            'amount' => $amount,
            'is_active' => true,
        ]);
        $program->programmeFees()->create([
            'fee_item_id' => $fee->id,
            'amount' => null,
            'level_code' => 'all',
            'semester' => 'both',
            'is_active' => true,
        ]);
        $user = User::factory()->create(['status' => 'active']);
        $student = Student::query()->create([
            'user_id' => $user->id,
            'program_id' => $program->id,
            'first_name' => 'Oyindamola',
            'last_name' => 'Oladejo',
            'matric_number' => 'BUT/2026/M/SEM1',
            'status' => 'active',
            'current_level' => '100',
        ]);
        Wallet::query()->create(['student_id' => $student->id, 'balance' => 800]);

        return $student->fresh(['user', 'program']);
    }

    private function financeStaff(): User
    {
        foreach (PermissionCatalog::all() as $perm) {
            Permission::query()->updateOrCreate(['key' => $perm['key']], $perm);
        }

        $role = Role::query()->create([
            'name' => 'Finance',
            'slug' => 'finance-sem-test',
            'is_system' => false,
            'is_active' => true,
        ]);
        $role->permissions()->sync(
            Permission::query()->whereIn('key', ['finance.invoices.manage'])->pluck('id'),
        );

        $office = OfficeDepartment::query()->create([
            'name' => 'Bursary Sem',
            'code' => 'BUR-SEM',
            'is_active' => true,
        ]);
        $office->syncNavKeys(['finance']);

        $user = User::factory()->create(['status' => 'active']);
        $user->roles()->attach($role->id);
        Staff::query()->create([
            'user_id' => $user->id,
            'staff_number' => 'FIN-SEM-1',
            'office_department_id' => $office->id,
        ]);

        return $user->fresh(['roles.permissions', 'staff']);
    }
}
