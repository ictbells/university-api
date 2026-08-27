<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\AcademicTerm;
use App\Models\Campus;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\FeeItem;
use App\Models\Invoice;
use App\Models\Program;
use App\Models\Student;
use App\Models\StudentLevelProgression;
use App\Models\User;
use App\Models\Wallet;
use App\Services\InvoiceService;
use App\Services\SessionCloseService;
use App\Support\TuitionProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FeeArrearsTest extends TestCase
{
    use RefreshDatabase;

    public function test_session_close_invoices_remaining_100_level_shares_then_blocks_current_session_billing(): void
    {
        [$student, $oldSession, $newSession] = $this->studentPaidHalfThenPromoted();

        $this->assertSame(200, (int) $student->fresh()->current_level);

        $arrears = Invoice::query()
            ->where('student_id', $student->id)
            ->whereIn('status', ['unpaid', 'partial'])
            ->get();
        $this->assertCount(1, $arrears);
        $this->assertEquals(20000.0, (float) $arrears->first()->amount);
        $this->assertSame('100', (string) $arrears->first()->level_code);
        $this->assertSame($oldSession->id, (int) $arrears->first()->academic_session_id);

        Sanctum::actingAs($student->user);
        $this->postJson('/api/invoices/tuition-installment', ['installment_percent' => 25])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Pay unpaid invoices from previous sessions and levels before current-session fees or course registration.']);

        $arrears->first()->update(['status' => 'paid', 'balance' => 0]);

        $this->assertSame(0.0, TuitionProgress::percentPaid($student->fresh(), $newSession->id));

        $current = $this->postJson('/api/invoices/tuition-installment', ['installment_percent' => 25])
            ->assertOk()
            ->json();
        $this->assertEquals(12000.0, (float) $current['amount']);
        $this->assertSame('200', (string) $current['level_code']);
        $this->assertSame($newSession->id, (int) $current['academic_session_id']);
    }

    public function test_already_promoted_student_gets_arrears_invoice_on_wallet_load(): void
    {
        [$student] = $this->studentWithLevelSchedules();
        $old = AcademicSession::query()->create([
            'label' => '2024/2025',
            'starts_on' => '2024-10-01',
            'ends_on' => '2025-09-30',
        ]);
        AcademicTerm::query()->create([
            'academic_session_id' => $old->id,
            'name' => 'First',
            'session_label' => '2024/2025',
            'is_current' => true,
        ]);

        $service = app(InvoiceService::class);
        $first = $service->createTuitionInvoice($student->fresh(['program']), 25);
        $first->update(['status' => 'paid', 'balance' => 0]);
        $second = $service->createTuitionInvoice($student->fresh(['program']), 50);
        $second->update(['status' => 'paid', 'balance' => 0]);

        AcademicTerm::query()->update(['is_current' => false]);
        $old->update(['closed_at' => now()]);
        $student->update(['current_level' => 200]);
        StudentLevelProgression::query()->insert([
            'student_id' => $student->id,
            'academic_session_id' => $old->id,
            'program_id' => $student->program_id,
            'from_level' => 100,
            'to_level' => 200,
            'created_at' => now(),
        ]);

        $new = AcademicSession::query()->create([
            'label' => '2025/2026',
            'starts_on' => '2025-10-01',
            'ends_on' => '2026-09-30',
        ]);
        AcademicTerm::query()->create([
            'academic_session_id' => $new->id,
            'name' => 'First',
            'session_label' => '2025/2026',
            'is_current' => true,
        ]);

        Sanctum::actingAs($student->user);
        $wallet = $this->getJson('/api/wallet')->assertOk()->json();
        $this->assertEquals(20000.0, (float) $wallet['outstanding']);
        $this->assertSame(1, (int) $wallet['prior_unpaid_count']);

        $this->assertTrue(
            Invoice::query()
                ->where('student_id', $student->id)
                ->where('status', 'unpaid')
                ->where('level_code', '100')
                ->exists()
        );
    }

    public function test_remaining_invoice_uses_3rd_and_4th_shares_when_paid_invoices_have_no_session(): void
    {
        [$student] = $this->studentWithLevelSchedules();
        $old = AcademicSession::query()->create([
            'label' => '2024/2025',
            'starts_on' => '2024-10-01',
            'ends_on' => '2025-09-30',
        ]);
        AcademicTerm::query()->create([
            'academic_session_id' => $old->id,
            'name' => 'First',
            'session_label' => '2024/2025',
            'is_current' => true,
        ]);

        $service = app(InvoiceService::class);
        $first = $service->createTuitionInvoice($student->fresh(['program']), 25);
        $first->update(['status' => 'paid', 'balance' => 0, 'academic_session_id' => null, 'level_code' => null]);
        $second = $service->createTuitionInvoice($student->fresh(['program']), 50);
        $second->update(['status' => 'paid', 'balance' => 0, 'academic_session_id' => null, 'level_code' => null]);

        AcademicTerm::query()->update(['is_current' => false]);
        $old->update(['closed_at' => now()]);
        $student->update(['current_level' => 200]);
        StudentLevelProgression::query()->insert([
            'student_id' => $student->id,
            'academic_session_id' => $old->id,
            'program_id' => $student->program_id,
            'from_level' => 100,
            'to_level' => 200,
            'created_at' => now(),
        ]);
        $new = AcademicSession::query()->create([
            'label' => '2025/2026',
            'starts_on' => '2025-10-01',
            'ends_on' => '2026-09-30',
        ]);
        AcademicTerm::query()->create([
            'academic_session_id' => $new->id,
            'name' => 'First',
            'session_label' => '2025/2026',
            'is_current' => true,
        ]);

        Invoice::query()->create([
            'number' => 'INV-DUP-1',
            'user_id' => $student->user_id,
            'student_id' => $student->id,
            'category' => 'tuition',
            'installment_percent' => 100,
            'amount' => 625874,
            'full_amount' => 1437250,
            'balance' => 625874,
            'status' => 'unpaid',
            'wallet_allowed' => true,
            'academic_session_id' => $old->id,
            'level_code' => '100',
        ]);
        Invoice::query()->create([
            'number' => 'INV-DUP-2',
            'user_id' => $student->user_id,
            'student_id' => $student->id,
            'category' => 'tuition',
            'installment_percent' => 100,
            'amount' => 625874,
            'full_amount' => 1437250,
            'balance' => 625874,
            'status' => 'unpaid',
            'wallet_allowed' => true,
            'academic_session_id' => $old->id,
            'level_code' => '100',
        ]);

        Sanctum::actingAs($student->user);
        $this->getJson('/api/wallet')->assertOk();
        $this->getJson('/api/wallet')->assertOk();
        $this->getJson('/api/transactions')->assertOk();

        $arrears = Invoice::query()
            ->with('items')
            ->where('student_id', $student->id)
            ->where('status', 'unpaid')
            ->where('level_code', '100')
            ->get();
        $this->assertCount(1, $arrears);
        $this->assertEquals(20000.0, (float) $arrears->first()->amount);
        $this->assertEqualsCanonicalizing(
            ['3rd 25%', '4th 25%'],
            $arrears->first()->items->map(function ($item) {
                if (str_contains((string) $item->description, '3rd 25%')) {
                    return '3rd 25%';
                }

                return str_contains((string) $item->description, '4th 25%') ? '4th 25%' : (string) $item->description;
            })->unique()->values()->all()
        );
        $this->assertSame(2, Invoice::query()->where('student_id', $student->id)->where('status', 'cancelled')->count());
        $this->assertSame('3rd 25% + 4th 25%', $arrears->first()->shareLabel());
    }

    /**
     * @return array{0: Student, 1: AcademicSession, 2: AcademicSession}
     */
    private function studentPaidHalfThenPromoted(): array
    {
        [$student] = $this->studentWithLevelSchedules();
        $old = AcademicSession::query()->create([
            'label' => '2024/2025',
            'starts_on' => '2024-10-01',
            'ends_on' => '2025-09-30',
        ]);
        AcademicTerm::query()->create([
            'academic_session_id' => $old->id,
            'name' => 'First',
            'session_label' => '2024/2025',
            'is_current' => true,
        ]);

        $service = app(InvoiceService::class);
        $first = $service->createTuitionInvoice($student->fresh(['program']), 25);
        $first->update(['status' => 'paid', 'balance' => 0]);
        $second = $service->createTuitionInvoice($student->fresh(['program']), 50);
        $second->update(['status' => 'paid', 'balance' => 0]);

        app(SessionCloseService::class)->close($old, 'manual');

        AcademicTerm::query()->update(['is_current' => false]);
        $new = AcademicSession::query()->create([
            'label' => '2025/2026',
            'starts_on' => '2025-10-01',
            'ends_on' => '2026-09-30',
        ]);
        AcademicTerm::query()->create([
            'academic_session_id' => $new->id,
            'name' => 'First',
            'session_label' => '2025/2026',
            'is_current' => true,
        ]);

        return [$student->fresh(['user', 'program']), $old->fresh(), $new];
    }

    /**
     * @return array{0: Student}
     */
    private function studentWithLevelSchedules(): array
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
            'matric_number' => 'BUT/2026/A001',
            'status' => 'active',
            'current_level' => '100',
        ]);
        Wallet::query()->create(['student_id' => $student->id, 'balance' => 500000]);

        foreach ([1 => 10000, 2 => 10000, 3 => 10000, 4 => 10000] as $tranche => $amount) {
            $fee = FeeItem::query()->create([
                'name' => "100L slice {$tranche}",
                'category' => 'tuition',
                'installment_tranche' => $tranche,
                'amount' => $amount,
                'is_active' => true,
            ]);
            $program->programmeFees()->create([
                'fee_item_id' => $fee->id,
                'amount' => null,
                'level_code' => '100',
                'semester' => 'both',
                'is_active' => true,
            ]);
        }
        foreach ([1 => 12000, 2 => 12000, 3 => 12000, 4 => 12000] as $tranche => $amount) {
            $fee = FeeItem::query()->create([
                'name' => "200L slice {$tranche}",
                'category' => 'tuition',
                'installment_tranche' => $tranche,
                'amount' => $amount,
                'is_active' => true,
            ]);
            $program->programmeFees()->create([
                'fee_item_id' => $fee->id,
                'amount' => null,
                'level_code' => '200',
                'semester' => 'both',
                'is_active' => true,
            ]);
        }

        return [$student->fresh(['program', 'user'])];
    }
}
