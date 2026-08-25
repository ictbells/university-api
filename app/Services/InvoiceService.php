<?php

namespace App\Services;

use App\Models\Application;
use App\Models\FeeItem;
use App\Models\Intake;
use App\Models\Invoice;
use App\Models\MedicalBill;
use App\Models\ProgrammeFee;
use App\Models\Student;
use App\Models\User;
use App\Support\FeeSchedule;
use App\Support\ProgrammeFeeResolver;
use App\Support\Studentship;
use InvalidArgumentException;
use RuntimeException;

class InvoiceService
{
    public function createForFee(
        User $user,
        FeeItem $fee,
        ?int $applicationId = null,
        ?int $studentId = null,
        ?float $amountOverride = null,
        ?string $description = null,
    ): Invoice {
        $amount = $amountOverride !== null ? round($amountOverride, 2) : (float) $fee->amount;
        $walletAllowed = FeeSchedule::walletAllowed($fee->category);

        $number = 'INV-'.now()->format('Ymd').'-'.str_pad((string) (Invoice::query()->count() + 1), 5, '0', STR_PAD_LEFT);
        $invoice = Invoice::query()->create([
            'number' => $number,
            'user_id' => $user->id,
            'student_id' => $studentId,
            'application_id' => $applicationId,
            'category' => $fee->category,
            'amount' => $amount,
            'full_amount' => $amount,
            'balance' => $amount,
            'status' => 'unpaid',
            'wallet_allowed' => $walletAllowed,
        ]);
        $invoice->items()->create([
            'fee_item_id' => $fee->id,
            'description' => $description ?: $fee->name,
            'amount' => $amount,
        ]);

        return $invoice->fresh('items');
    }

    public function createForCharge(
        User $user,
        string $category,
        float $amount,
        string $description,
        ?int $applicationId = null,
        ?int $studentId = null,
        ?int $feeItemId = null,
    ): Invoice {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw new InvalidArgumentException('Charge amount must be greater than zero.');
        }

        $number = 'INV-'.now()->format('Ymd').'-'.str_pad((string) (Invoice::query()->count() + 1), 5, '0', STR_PAD_LEFT);
        $invoice = Invoice::query()->create([
            'number' => $number,
            'user_id' => $user->id,
            'student_id' => $studentId,
            'application_id' => $applicationId,
            'category' => $category,
            'amount' => $amount,
            'full_amount' => $amount,
            'balance' => $amount,
            'status' => 'unpaid',
            'wallet_allowed' => FeeSchedule::walletAllowed($category),
        ]);
        $invoice->items()->create([
            'fee_item_id' => $feeItemId,
            'description' => $description,
            'amount' => $amount,
        ]);

        return $invoice->fresh('items');
    }

    /**
     * @param  list<FeeItem>  $fees
     */
    public function createFromFeeItems(Student $student, array $fees, int $percent = 100): Invoice
    {
        $student->loadMissing('user');
        if (! $student->user) {
            throw new RuntimeException('This student record has no login account.');
        }
        if ($fees === []) {
            throw new RuntimeException('Select at least one fee item.');
        }
        if (! in_array($percent, FeeSchedule::INSTALLMENT_PERCENTS, true)) {
            $percent = 100;
        }

        $walletFlags = [];
        $categories = [];
        foreach ($fees as $fee) {
            if (! $fee->is_active) {
                throw new RuntimeException($fee->name.' is not an active fee item.');
            }
            $categories[] = $fee->category;
            $walletFlags[] = FeeSchedule::walletAllowed($fee->category);
        }
        if (in_array(true, $walletFlags, true) && in_array(false, $walletFlags, true)) {
            throw new RuntimeException('Application and acceptance fees cannot be combined with other charges on the same invoice.');
        }

        $uniqueCategories = array_values(array_unique($categories));
        $category = count($uniqueCategories) === 1 ? $uniqueCategories[0] : 'sundry';
        $walletAllowed = ! in_array(false, $walletFlags, true);

        $lines = [];
        foreach ($fees as $fee) {
            $amount = round((float) $fee->amount, 2);
            $description = $fee->name;
            if ($fee->category === 'tuition' && $percent < 100) {
                $amount = round($amount * ($percent / 100), 2);
                $description .= " ({$percent}%)";
            }
            if ($amount <= 0) {
                throw new RuntimeException($fee->name.' has no amount set.');
            }
            $lines[] = [
                'fee' => $fee,
                'description' => $description,
                'amount' => $amount,
            ];
        }

        $total = round(array_sum(array_column($lines, 'amount')), 2);
        $number = 'INV-'.now()->format('Ymd').'-'.str_pad((string) (Invoice::query()->count() + 1), 5, '0', STR_PAD_LEFT);
        $invoice = Invoice::query()->create([
            'number' => $number,
            'user_id' => $student->user_id,
            'student_id' => $student->id,
            'application_id' => $student->application_id,
            'category' => $category,
            'installment_percent' => collect($fees)->contains(fn (FeeItem $fee) => $fee->category === 'tuition') ? $percent : null,
            'amount' => $total,
            'full_amount' => $total,
            'balance' => $total,
            'status' => 'unpaid',
            'wallet_allowed' => $walletAllowed,
        ]);

        foreach ($lines as $line) {
            $invoice->items()->create([
                'fee_item_id' => $line['fee']->id,
                'description' => $line['description'],
                'amount' => $line['amount'],
            ]);
        }

        return $invoice->fresh('items');
    }

    public function createApplicationFeeInvoice(User $user, Intake $intake, int $applicationId): Invoice
    {
        $amount = $intake->applicationFeeAmount();
        $number = 'INV-'.now()->format('Ymd').'-'.str_pad((string) (Invoice::query()->count() + 1), 5, '0', STR_PAD_LEFT);
        $invoice = Invoice::query()->create([
            'number' => $number,
            'user_id' => $user->id,
            'application_id' => $applicationId,
            'category' => 'application_fee',
            'amount' => $amount,
            'full_amount' => $amount,
            'balance' => $amount,
            'status' => 'unpaid',
            'wallet_allowed' => false,
        ]);
        $invoice->items()->create([
            'description' => $intake->name.' application fee',
            'amount' => $amount,
        ]);

        return $invoice->fresh('items');
    }

    public function createAcceptanceFeeInvoice(
        User $user,
        Intake $intake,
        int $applicationId,
        ?float $amountOverride = null,
    ): Invoice {
        $amount = $amountOverride ?? $this->resolveAcceptanceFeeAmount($intake);
        if ($amount <= 0) {
            throw new RuntimeException('Set an acceptance fee amount in the fee catalog or application session.');
        }
        $fee = FeeItem::query()->where('category', 'acceptance_fee')->where('is_active', true)->first();

        $number = 'INV-'.now()->format('Ymd').'-'.str_pad((string) (Invoice::query()->count() + 1), 5, '0', STR_PAD_LEFT);
        $invoice = Invoice::query()->create([
            'number' => $number,
            'user_id' => $user->id,
            'application_id' => $applicationId,
            'category' => 'acceptance_fee',
            'amount' => $amount,
            'full_amount' => $amount,
            'balance' => $amount,
            'status' => 'unpaid',
            'wallet_allowed' => false,
        ]);
        $invoice->items()->create([
            'fee_item_id' => $fee?->id,
            'description' => $intake->name.' acceptance fee',
            'amount' => $amount,
        ]);

        return $invoice->fresh('items');
    }

    public function resolveAcceptanceFeeAmount(?Intake $intake, ?float $override = null): float
    {
        if ($override !== null) {
            $amount = round($override, 2);
            if ($amount <= 0) {
                throw new RuntimeException('Acceptance fee must be greater than zero.');
            }

            return $amount;
        }

        $fromIntake = $intake?->acceptanceFeeAmount();
        if ($fromIntake !== null && $fromIntake > 0) {
            return $fromIntake;
        }

        $fee = FeeItem::query()->where('category', 'acceptance_fee')->where('is_active', true)->first();
        if ($fee && (float) $fee->amount > 0) {
            return (float) $fee->amount;
        }

        throw new RuntimeException('Set an acceptance fee amount in the fee catalog or application session before students can pay.');
    }

    public function ensureAcceptanceFeeInvoice(Application $application, ?float $amountOverride = null): Invoice
    {
        $application->loadMissing(['user', 'intake', 'acceptanceFeeInvoice']);
        $existing = $application->acceptanceFeeInvoice;
        if ($existing && in_array($existing->status, ['unpaid', 'partial', 'paid'], true)) {
            if ($amountOverride !== null && $existing->status !== 'paid') {
                return $this->updateAcceptanceFeeInvoice($existing, $amountOverride);
            }

            return $existing;
        }

        $user = $application->user;
        if (! $user) {
            throw new RuntimeException('This application has no applicant account.');
        }

        $amount = $this->resolveAcceptanceFeeAmount($application->intake, $amountOverride);
        $intake = $application->intake;
        if ($intake) {
            $invoice = $this->createAcceptanceFeeInvoice($user, $intake, $application->id, $amount);
        } else {
            $fee = FeeItem::query()->where('category', 'acceptance_fee')->where('is_active', true)->first();
            if (! $fee) {
                throw new RuntimeException('Add an active Acceptance fee in the fee catalog.');
            }
            $invoice = $this->createForFee($user, $fee, $application->id, null, $amount);
        }

        $stage = $application->stage;
        if (in_array($stage, ['offer_issued', 'admission'], true)) {
            $stage = 'awaiting_acceptance_fee';
        }

        $application->update([
            'acceptance_fee_invoice_id' => $invoice->id,
            'stage' => $stage,
        ]);

        return $invoice->fresh();
    }

    public function updateAcceptanceFeeInvoice(Invoice $invoice, float $amount): Invoice
    {
        if ($invoice->category !== 'acceptance_fee') {
            throw new InvalidArgumentException('Only acceptance fee invoices can be updated here.');
        }
        if ($invoice->status === 'paid') {
            throw new RuntimeException('Paid acceptance fee invoices cannot be changed.');
        }

        $amount = round($amount, 2);
        $invoice->update([
            'amount' => $amount,
            'full_amount' => $amount,
            'balance' => $amount,
        ]);
        foreach ($invoice->items as $item) {
            $item->update(['amount' => $amount]);
        }

        return $invoice->fresh('items');
    }

    public function createTuitionInvoice(Student $student, int $percent = 100, ?string $semester = null): Invoice
    {
        if (! in_array($percent, FeeSchedule::INSTALLMENT_PERCENTS, true)) {
            throw new InvalidArgumentException('Tuition installment must be 25%, 50%, 75%, or 100%.');
        }

        if (! Studentship::canRegisterCourses($student)) {
            throw new RuntimeException($student->status === Studentship::STATUS_GRADUATED
                ? 'Graduated students cannot generate a new tuition invoice.'
                : 'Studentship is not current; tuition billing is closed.');
        }

        $student->loadMissing(['user', 'program']);
        $lines = ProgrammeFeeResolver::forStudent($student, $semester);
        $fullAmount = round((float) $lines->sum(fn (ProgrammeFee $fee) => $fee->effective_amount), 2);

        if ($lines->isEmpty() || $fullAmount <= 0) {
            throw new RuntimeException('Programme school fees have not been set for this programme and level. Contact the bursary.');
        }

        $amount = round($fullAmount * ($percent / 100), 2);
        $number = 'INV-'.now()->format('Ymd').'-'.str_pad((string) (Invoice::query()->count() + 1), 5, '0', STR_PAD_LEFT);
        $invoice = Invoice::query()->create([
            'number' => $number,
            'user_id' => $student->user_id,
            'student_id' => $student->id,
            'application_id' => $student->application_id,
            'category' => 'tuition',
            'installment_percent' => $percent,
            'amount' => $amount,
            'full_amount' => $fullAmount,
            'balance' => $amount,
            'status' => 'unpaid',
            'wallet_allowed' => true,
        ]);

        $scale = $percent / 100;
        foreach ($lines as $line) {
            $lineAmount = round($line->effective_amount * $scale, 2);
            if ($lineAmount <= 0) {
                continue;
            }
            $invoice->items()->create([
                'fee_item_id' => $line->fee_item_id,
                'description' => sprintf(
                    '%s%s',
                    $line->feeItem?->name ?: FeeSchedule::label((string) ($line->feeItem?->category ?? 'other')),
                    $percent < 100 ? " ({$percent}%)" : ''
                ),
                'amount' => $lineAmount,
            ]);
        }

        return $invoice->fresh('items');
    }

    public function resolveTuitionAmount(Student $student, ?string $semester = null): float
    {
        $total = ProgrammeFeeResolver::totalForStudent($student, $semester);
        if ($total <= 0) {
            throw new RuntimeException('Programme school fees have not been set for this programme and level. Contact the bursary.');
        }

        return $total;
    }

    public function disable(Invoice $invoice, string $reason): Invoice
    {
        if ($invoice->status !== 'unpaid') {
            throw new RuntimeException('Only unpaid invoices can be disabled.');
        }

        $invoice->status = 'cancelled';
        $invoice->disabled_reason = $reason;
        $invoice->save();

        return $invoice->fresh();
    }

    public function enable(Invoice $invoice): Invoice
    {
        if ($invoice->status !== 'cancelled') {
            throw new RuntimeException('Only disabled invoices can be enabled.');
        }

        $invoice->status = 'unpaid';
        $invoice->disabled_reason = null;
        $invoice->save();

        return $invoice->fresh();
    }

    public function applyPayment(Invoice $invoice, float $amount): Invoice
    {
        if (! $invoice->isPayable()) {
            throw new RuntimeException('This invoice cannot be paid.');
        }

        $invoice->balance = max(0, (float) $invoice->balance - $amount);
        $invoice->status = $invoice->balance <= 0 ? 'paid' : 'partial';
        $invoice->save();

        if ($invoice->category === 'medical') {
            MedicalBill::query()
                ->where('invoice_id', $invoice->id)
                ->update(['status' => $invoice->status]);
        }

        if ($invoice->category === 'course_registration_extension' && $invoice->status === 'paid') {
            app(CourseRegistrationService::class)->markExtensionPaid($invoice);
        }

        return $invoice->fresh();
    }
}
