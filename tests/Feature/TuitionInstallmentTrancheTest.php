<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\FeeItem;
use App\Models\Program;
use App\Models\Student;
use App\Models\User;
use App\Models\Wallet;
use App\Services\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TuitionInstallmentTrancheTest extends TestCase
{
    use RefreshDatabase;

    public function test_bills_fixed_tranche_amounts_not_pro_rata(): void
    {
        [$student, $items] = $this->studentWithTrancheSchedule();

        $invoice = app(InvoiceService::class)->createTuitionInvoice($student, 25);

        $this->assertEquals(25, (int) $invoice->installment_percent);
        $this->assertEquals(40000.0, (float) $invoice->full_amount);
        $this->assertEquals(10000.0, (float) $invoice->amount);
        $this->assertCount(1, $invoice->items);
        $this->assertEquals($items[1]->id, $invoice->items->first()->fee_item_id);
        $this->assertEquals(10000.0, (float) $invoice->items->first()->amount);
    }

    public function test_cumulative_installment_skips_already_paid_tranches(): void
    {
        [$student, $items] = $this->studentWithTrancheSchedule();
        $service = app(InvoiceService::class);

        $first = $service->createTuitionInvoice($student, 25);
        $first->update(['status' => 'paid', 'balance' => 0]);

        $second = $service->createTuitionInvoice($student, 50);

        $this->assertEquals(50, (int) $second->installment_percent);
        $this->assertEquals(10000.0, (float) $second->amount);
        $this->assertCount(1, $second->items);
        $this->assertEquals($items[2]->id, $second->items->first()->fee_item_id);
    }

    public function test_full_payment_uses_pay_at_once_package_when_present(): void
    {
        [$student, $items] = $this->studentWithTrancheSchedule(withFullPackage: true);

        $invoice = app(InvoiceService::class)->createTuitionInvoice($student, 100);

        $this->assertEquals(40000.0, (float) $invoice->full_amount);
        $this->assertEquals(38000.0, (float) $invoice->amount);
        $this->assertCount(1, $invoice->items);
        $this->assertEquals($items[100]->id, $invoice->items->first()->fee_item_id);
    }

    public function test_legacy_untagged_schedule_still_pro_rates(): void
    {
        $student = $this->activeStudent();
        $fee = FeeItem::query()->create([
            'name' => 'Tuition',
            'category' => 'tuition',
            'amount' => 20000,
            'is_active' => true,
        ]);
        $student->program->programmeFees()->create([
            'fee_item_id' => $fee->id,
            'amount' => null,
            'level_code' => 'all',
            'semester' => 'both',
            'is_active' => true,
        ]);

        $invoice = app(InvoiceService::class)->createTuitionInvoice($student->fresh(['program']), 25);

        $this->assertEquals(20000.0, (float) $invoice->full_amount);
        $this->assertEquals(5000.0, (float) $invoice->amount);
        $this->assertEquals(5000.0, (float) $invoice->items->first()->amount);
    }

    public function test_non_tuition_schedule_lines_bill_with_their_slice(): void
    {
        $student = $this->activeStudent();
        $tuition = FeeItem::query()->create([
            'name' => 'Tuition 1st 25%',
            'category' => 'tuition',
            'installment_tranche' => 1,
            'amount' => 10000,
            'is_active' => true,
        ]);
        $ict = FeeItem::query()->create([
            'name' => 'ICT 2nd 25%',
            'category' => 'ict',
            'installment_tranche' => 2,
            'amount' => 5000,
            'is_active' => true,
        ]);
        $lab = FeeItem::query()->create([
            'name' => 'Laboratory 3rd 25%',
            'category' => 'laboratory',
            'installment_tranche' => 3,
            'amount' => 8000,
            'is_active' => true,
        ]);
        foreach ([$tuition, $ict, $lab] as $fee) {
            $student->program->programmeFees()->create([
                'fee_item_id' => $fee->id,
                'amount' => null,
                'level_code' => 'all',
                'semester' => 'both',
                'is_active' => true,
            ]);
        }

        $service = app(InvoiceService::class);
        $first = $service->createTuitionInvoice($student->fresh(['program']), 25);
        $this->assertEquals(10000.0, (float) $first->amount);
        $this->assertCount(1, $first->items);
        $this->assertEquals($tuition->id, $first->items->first()->fee_item_id);

        $first->update(['status' => 'paid', 'balance' => 0]);
        $second = $service->createTuitionInvoice($student->fresh(['program']), 50);
        $this->assertEquals(5000.0, (float) $second->amount);
        $this->assertCount(1, $second->items);
        $this->assertEquals($ict->id, $second->items->first()->fee_item_id);

        $second->update(['status' => 'paid', 'balance' => 0]);
        $third = $service->createTuitionInvoice($student->fresh(['program']), 75);
        $this->assertEquals(8000.0, (float) $third->amount);
        $this->assertEquals($lab->id, $third->items->first()->fee_item_id);
    }

    public function test_one_catalog_item_can_cover_every_installment_slice(): void
    {
        $student = $this->activeStudent();
        $tuition = FeeItem::query()->create([
            'name' => 'Tuition',
            'category' => 'tuition',
            'installment_tranche' => 1,
            'amount' => 10000,
            'is_active' => true,
        ]);
        foreach ([1, 2, 3, 4] as $tranche) {
            $student->program->programmeFees()->create([
                'fee_item_id' => $tuition->id,
                'amount' => 10000,
                'installment_tranche' => $tranche,
                'level_code' => 'all',
                'semester' => 'both',
                'is_active' => true,
            ]);
        }

        $service = app(InvoiceService::class);
        $first = $service->createTuitionInvoice($student->fresh(['program']), 25);
        $this->assertEquals(10000.0, (float) $first->amount);
        $this->assertCount(1, $first->items);
        $this->assertEquals($tuition->id, $first->items->first()->fee_item_id);
        $this->assertNotNull($first->items->first()->programme_fee_id);

        $first->update(['status' => 'paid', 'balance' => 0]);
        $second = $service->createTuitionInvoice($student->fresh(['program']), 50);
        $this->assertEquals(10000.0, (float) $second->amount);
        $this->assertCount(1, $second->items);
        $this->assertEquals($tuition->id, $second->items->first()->fee_item_id);
        $this->assertNotEquals($first->items->first()->programme_fee_id, $second->items->first()->programme_fee_id);
    }

    /**
     * @return array{0: Student, 1: array<int, FeeItem>}
     */
    private function studentWithTrancheSchedule(bool $withFullPackage = false): array
    {
        $student = $this->activeStudent();
        $items = [];
        foreach ([1 => 10000, 2 => 10000, 3 => 10000, 4 => 10000] as $tranche => $amount) {
            $items[$tranche] = FeeItem::query()->create([
                'name' => "Tuition {$tranche}",
                'category' => 'tuition',
                'installment_tranche' => $tranche,
                'amount' => $amount,
                'is_active' => true,
            ]);
            $student->program->programmeFees()->create([
                'fee_item_id' => $items[$tranche]->id,
                'amount' => null,
                'level_code' => 'all',
                'semester' => 'both',
                'is_active' => true,
            ]);
        }

        if ($withFullPackage) {
            $items[100] = FeeItem::query()->create([
                'name' => 'Tuition full',
                'category' => 'tuition',
                'installment_tranche' => 100,
                'amount' => 38000,
                'is_active' => true,
            ]);
            $student->program->programmeFees()->create([
                'fee_item_id' => $items[100]->id,
                'amount' => null,
                'level_code' => 'all',
                'semester' => 'both',
                'is_active' => true,
            ]);
        }

        return [$student->fresh(['program']), $items];
    }

    private function activeStudent(): Student
    {
        $campus = Campus::query()->create(['name' => 'Main', 'is_active' => true]);
        $faculty = Faculty::query()->create(['campus_id' => $campus->id, 'name' => 'Science']);
        $department = Department::query()->create(['faculty_id' => $faculty->id, 'name' => 'CS']);
        $program = Program::query()->create([
            'department_id' => $department->id,
            'name' => 'B.Sc CS',
            'code' => 'BSC-CS',
            'award_type' => 'B.Sc',
            'study_level' => 'undergraduate',
            'entry_modes' => ['utme'],
            'duration_years' => 4,
            'is_active' => true,
        ]);

        $user = User::factory()->create(['status' => 'active']);
        $student = Student::query()->create([
            'user_id' => $user->id,
            'program_id' => $program->id,
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'matric_number' => 'BUT/2026/T001',
            'status' => 'active',
            'current_level' => '100',
        ]);
        Wallet::query()->create(['student_id' => $student->id, 'balance' => 0]);

        return $student->fresh(['program']);
    }
}
