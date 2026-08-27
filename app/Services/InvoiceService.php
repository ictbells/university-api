<?php

namespace App\Services;

use App\Models\AcademicSession;
use App\Models\AcademicTerm;
use App\Models\Application;
use App\Models\FeeItem;
use App\Models\Intake;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\MedicalBill;
use App\Models\ProgrammeFee;
use App\Models\Student;
use App\Models\User;
use App\Support\FeeSchedule;
use App\Support\ProgrammeFeeResolver;
use App\Support\Studentship;
use App\Support\TuitionProgress;
use Illuminate\Support\Collection;
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
            'academic_session_id' => $this->currentSessionId(),
            'level_code' => $this->levelCodeForStudentId($studentId),
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
            'academic_session_id' => $this->currentSessionId(),
            'level_code' => $this->levelCodeForStudentId($studentId),
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
     * Invoice clinic visit charges. Lines snapshot the visit catalog prices;
     * the invoice total is the student-payable share after NHIS.
     *
     * @param  iterable<\App\Models\ClinicVisitItem>  $visitItems
     */
    public function createClinicVisitInvoice(
        Student $student,
        iterable $visitItems,
        float $payable,
        float $nhisCovered = 0,
    ): Invoice {
        $student->loadMissing('user');
        if (! $student->user) {
            throw new RuntimeException('This student record has no login account.');
        }

        $amount = round($payable, 2);
        if ($amount <= 0) {
            throw new InvalidArgumentException('Charge amount must be greater than zero.');
        }

        $number = 'INV-'.now()->format('Ymd').'-'.str_pad((string) (Invoice::query()->count() + 1), 5, '0', STR_PAD_LEFT);
        $invoice = Invoice::query()->create([
            'number' => $number,
            'user_id' => $student->user_id,
            'student_id' => $student->id,
            'application_id' => $student->application_id,
            'category' => 'clinic',
            'amount' => $amount,
            'full_amount' => $amount,
            'balance' => $amount,
            'status' => 'unpaid',
            'wallet_allowed' => FeeSchedule::walletAllowed('clinic'),
        ]);

        foreach ($visitItems as $item) {
            $invoice->items()->create([
                'fee_item_id' => $item->fee_item_id,
                'description' => $item->description,
                'amount' => round((float) $item->line_total, 2),
            ]);
        }

        $covered = round($nhisCovered, 2);
        if ($covered > 0) {
            $invoice->items()->create([
                'fee_item_id' => null,
                'description' => 'NHIS coverage',
                'amount' => -$covered,
            ]);
        }

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
            // Tranche-tagged schedule items already represent a fixed share; do not pro-rate again.
            $tagged = FeeSchedule::allowsInstallmentTranche((string) $fee->category)
                && $fee->installment_tranche !== null;
            if (FeeSchedule::allowsInstallmentTranche((string) $fee->category) && $percent < 100 && $fee->installment_tranche === null) {
                $amount = round($amount * ($percent / 100), 2);
                $description .= " ({$percent}%)";
            } elseif ($tagged) {
                $label = FeeSchedule::installmentTrancheLabel((int) $fee->installment_tranche);
                if ($label) {
                    $description .= " ({$label})";
                }
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
            'installment_percent' => collect($fees)->contains(
                fn (FeeItem $fee) => FeeSchedule::allowsInstallmentTranche((string) $fee->category)
            ) ? $percent : null,
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
        $amount = $this->resolveApplicationFeeAmount($intake);
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

    public function resolveApplicationFeeAmount(Intake $intake): float
    {
        $catalog = $this->catalogFeeForEntryMode('application_fee', $intake->entry_mode);
        if ($catalog && (float) $catalog->amount > 0) {
            return (float) $catalog->amount;
        }

        if ($intake->application_fee_amount !== null) {
            return (float) $intake->application_fee_amount;
        }

        throw new RuntimeException('Set an application fee in the fee catalog for this entry mode, or on the application session, before applicants can apply.');
    }

    public function createAcceptanceFeeInvoice(
        User $user,
        Intake $intake,
        int $applicationId,
        ?float $amountOverride = null,
    ): Invoice {
        $amount = $amountOverride ?? $this->resolveAcceptanceFeeAmount($intake);
        if ($amount <= 0) {
            throw new RuntimeException('Set an acceptance fee amount in the fee catalog for this entry mode, or on the application session.');
        }
        $fee = $this->catalogFeeForEntryMode('acceptance_fee', $intake->entry_mode);

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

        $fee = $this->catalogFeeForEntryMode('acceptance_fee', $intake?->entry_mode);
        if ($fee && (float) $fee->amount > 0) {
            return (float) $fee->amount;
        }

        $fromIntake = $intake?->acceptanceFeeAmount();
        if ($fromIntake !== null && $fromIntake > 0) {
            return $fromIntake;
        }

        throw new RuntimeException('Set an acceptance fee in the fee catalog for this entry mode, or on the application session, before students can pay.');
    }

    public function ensureAcceptanceInvoiceIfOffered(Application $application): ?Invoice
    {
        if (! in_array($application->stage, ['offer_issued', 'awaiting_acceptance_fee', 'admission'], true)) {
            return $application->acceptanceFeeInvoice;
        }

        try {
            $invoice = $this->ensureAcceptanceFeeInvoice($application);
            $application->unsetRelation('acceptanceFeeInvoice');
            $application->setRelation('acceptanceFeeInvoice', $invoice);

            return $invoice;
        } catch (\Throwable $e) {
            report($e);

            return $application->acceptanceFeeInvoice;
        }
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
            $fee = $this->catalogFeeForEntryMode('acceptance_fee', $application->entry_mode);
            if (! $fee) {
                throw new RuntimeException('Add an active Acceptance fee in the fee catalog for this entry mode.');
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
        $application->unsetRelation('acceptanceFeeInvoice');
        $application->setRelation('acceptanceFeeInvoice', $invoice);

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

    private function catalogFeeForEntryMode(string $category, ?string $entryMode): ?FeeItem
    {
        $base = FeeItem::query()
            ->where('category', $category)
            ->where('is_active', true)
            ->orderBy('display_order')
            ->orderBy('id');

        if ($entryMode) {
            $match = (clone $base)->where('entry_mode', $entryMode)->first();
            if ($match && (float) $match->amount > 0) {
                return $match;
            }
        }

        $generic = (clone $base)
            ->where(function ($query) {
                $query->whereNull('entry_mode')->orWhere('entry_mode', '');
            })
            ->first();
        if ($generic && (float) $generic->amount > 0) {
            return $generic;
        }

        return null;
    }

    public function createTuitionInvoice(
        Student $student,
        int $percent = 100,
        ?string $semester = null,
        ?AcademicSession $forSession = null,
        ?string $levelCode = null,
    ): Invoice {
        if (! in_array($percent, FeeSchedule::INSTALLMENT_PERCENTS, true)) {
            throw new InvalidArgumentException('Tuition installment must be 25%, 50%, 75%, or 100%.');
        }

        if (! Studentship::canRegisterCourses($student)) {
            throw new RuntimeException($student->status === Studentship::STATUS_GRADUATED
                ? 'Graduated students cannot generate a new tuition invoice.'
                : 'Studentship is not current; tuition billing is closed.');
        }

        $sessionId = $forSession?->id ?? $this->currentSessionId();
        $paidPercent = TuitionProgress::percentPaid($student, $sessionId);
        if ($percent <= $paidPercent) {
            throw new RuntimeException($paidPercent >= 100
                ? 'Tuition is already paid in full.'
                : 'This installment has already been paid. Choose the next unpaid share.');
        }

        $student->loadMissing(['user', 'program']);
        $resolvedLevel = $levelCode !== null && $levelCode !== ''
            ? $levelCode
            : ($student->current_level !== null ? (string) $student->current_level : null);
        $lines = $student->program_id && $resolvedLevel
            ? ProgrammeFeeResolver::forProgram((int) $student->program_id, $resolvedLevel, $semester)
            : ProgrammeFeeResolver::forStudent($student, $semester);
        $fullAmount = ProgrammeFeeResolver::scheduleFullAmount($lines);

        if ($lines->isEmpty() || $fullAmount <= 0) {
            throw new RuntimeException('Programme school fees have not been set for this programme and level. Contact the bursary.');
        }

        $hasTranches = $lines->contains(fn (ProgrammeFee $fee) => FeeSchedule::allowsInstallmentTranche((string) ($fee->feeItem?->category ?? ''))
            && $fee->effective_installment_tranche !== null);
        if ($hasTranches) {
            return $this->createTuitionInvoiceFromTranches(
                $student,
                $lines,
                $percent,
                $fullAmount,
                $paidPercent,
                $sessionId,
                $resolvedLevel,
                $forSession,
            );
        }

        $deltaPercent = $percent - $paidPercent;
        if ($deltaPercent <= 0) {
            throw new RuntimeException($paidPercent >= 100
                ? 'Tuition is already paid in full.'
                : 'This installment has already been paid. Choose the next unpaid share.');
        }

        $amount = round($fullAmount * ($deltaPercent / 100), 2);
        $number = 'INV-'.now()->format('Ymd').'-'.str_pad((string) (Invoice::query()->count() + 1), 5, '0', STR_PAD_LEFT);
        $invoice = Invoice::query()->create([
            'number' => $number,
            'user_id' => $student->user_id,
            'student_id' => $student->id,
            'application_id' => $student->application_id,
            'academic_session_id' => $sessionId,
            'level_code' => $resolvedLevel,
            'category' => 'tuition',
            'installment_percent' => $percent,
            'amount' => $amount,
            'full_amount' => $fullAmount,
            'balance' => $amount,
            'status' => 'unpaid',
            'wallet_allowed' => true,
        ]);

        $arrearsSuffix = $this->arrearsDescriptionSuffix($forSession, $resolvedLevel, $student);
        $scale = $deltaPercent / 100;
        foreach ($lines as $line) {
            $lineAmount = round($line->effective_amount * $scale, 2);
            if ($lineAmount <= 0) {
                continue;
            }
            $base = sprintf(
                '%s%s',
                $line->feeItem?->name ?: FeeSchedule::label((string) ($line->feeItem?->category ?? 'other')),
                $percent < 100
                    ? ($paidPercent > 0 ? " ({$deltaPercent}% remaining)" : " ({$percent}%)")
                    : ''
            );
            $invoice->items()->create([
                'fee_item_id' => $line->fee_item_id,
                'programme_fee_id' => $line->id,
                'description' => $arrearsSuffix ? "{$base} {$arrearsSuffix}" : $base,
                'amount' => $lineAmount,
            ]);
        }

        return $invoice->fresh('items');
    }

    /**
     * Bill fixed fee-item amounts for the chosen installment (1st/2nd/3rd/4th 25%, or full package).
     *
     * @param  Collection<int, ProgrammeFee>  $lines
     */
    private function createTuitionInvoiceFromTranches(
        Student $student,
        Collection $lines,
        int $percent,
        float $fullAmount,
        float $paidPercent = 0,
        ?int $sessionId = null,
        ?string $levelCode = null,
        ?AcademicSession $forSession = null,
    ): Invoice {
        $paidProgrammeFeeIds = InvoiceItem::query()
            ->whereNotNull('programme_fee_id')
            ->whereHas('invoice', function ($query) use ($student, $sessionId) {
                $query->where('student_id', $student->id)
                    ->where('category', 'tuition')
                    ->whereIn('status', ['paid', 'partial', 'unpaid']);
                $this->constrainInvoiceSession($query, $sessionId);
            })
            ->pluck('programme_fee_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->all();
        $legacyPaidFeeItemIds = InvoiceItem::query()
            ->whereNull('programme_fee_id')
            ->whereNotNull('fee_item_id')
            ->whereHas('invoice', function ($query) use ($student, $sessionId) {
                $query->where('student_id', $student->id)
                    ->where('category', 'tuition')
                    ->whereIn('status', ['paid', 'partial', 'unpaid']);
                $this->constrainInvoiceSession($query, $sessionId);
            })
            ->pluck('fee_item_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->all();

        $hasFullPackage = $lines->contains(
            fn (ProgrammeFee $fee) => FeeSchedule::allowsInstallmentTranche((string) ($fee->feeItem?->category ?? ''))
                && (int) ($fee->effective_installment_tranche ?? 0) === 100
        );
        $hasPriorPaidSlice = $paidPercent >= 25 || $lines->contains(function (ProgrammeFee $fee) use ($paidProgrammeFeeIds, $legacyPaidFeeItemIds) {
            $tranche = $fee->effective_installment_tranche;
            if (! FeeSchedule::allowsInstallmentTranche((string) ($fee->feeItem?->category ?? ''))
                || $tranche === null || (int) $tranche === 100) {
                return false;
            }

            return $this->trancheLineAlreadyPaid($fee, $paidProgrammeFeeIds, $legacyPaidFeeItemIds);
        });

        $useFullPackage = $percent === 100 && $hasFullPackage && ! $hasPriorPaidSlice;
        $wanted = FeeSchedule::remainingTranchesForInstallmentPercent($percent, $paidPercent, $useFullPackage);

        $billable = $lines->filter(function (ProgrammeFee $line) use ($wanted, $paidProgrammeFeeIds, $legacyPaidFeeItemIds) {
            if ($this->trancheLineAlreadyPaid($line, $paidProgrammeFeeIds, $legacyPaidFeeItemIds)) {
                return false;
            }

            $tranche = $line->effective_installment_tranche;
            $isTaggedSlice = FeeSchedule::allowsInstallmentTranche((string) ($line->feeItem?->category ?? ''))
                && $tranche !== null;
            if (! $isTaggedSlice) {
                // Untagged schedule lines ride with the first installment or the full package.
                return in_array(1, $wanted, true) || in_array(100, $wanted, true);
            }

            return in_array((int) $tranche, $wanted, true);
        });

        if ($billable->isEmpty()) {
            throw new RuntimeException('No unpaid fee items remain for this installment. You may already have paid this share.');
        }

        $amount = round((float) $billable->sum(fn (ProgrammeFee $fee) => $fee->effective_amount), 2);
        if ($amount <= 0) {
            throw new RuntimeException('No unpaid fee items remain for this installment. You may already have paid this share.');
        }

        $number = 'INV-'.now()->format('Ymd').'-'.str_pad((string) (Invoice::query()->count() + 1), 5, '0', STR_PAD_LEFT);
        $invoice = Invoice::query()->create([
            'number' => $number,
            'user_id' => $student->user_id,
            'student_id' => $student->id,
            'application_id' => $student->application_id,
            'academic_session_id' => $sessionId,
            'level_code' => $levelCode,
            'category' => 'tuition',
            'installment_percent' => $percent,
            'amount' => $amount,
            'full_amount' => $fullAmount,
            'balance' => $amount,
            'status' => 'unpaid',
            'wallet_allowed' => true,
        ]);

        $arrearsSuffix = $this->arrearsDescriptionSuffix($forSession, $levelCode, $student);
        foreach ($billable as $line) {
            $lineAmount = round((float) $line->effective_amount, 2);
            if ($lineAmount <= 0) {
                continue;
            }
            $name = $line->feeItem?->name ?: FeeSchedule::label((string) ($line->feeItem?->category ?? 'other'));
            $tranche = $line->effective_installment_tranche;
            $isTaggedSlice = FeeSchedule::allowsInstallmentTranche((string) ($line->feeItem?->category ?? ''))
                && $tranche !== null;
            $suffix = $isTaggedSlice
                ? FeeSchedule::installmentTrancheLabel((int) $tranche)
                : ($percent < 100 ? "{$percent}%" : null);
            $invoice->items()->create([
                'fee_item_id' => $line->fee_item_id,
                'programme_fee_id' => $line->id,
                'description' => $suffix
                    ? ($arrearsSuffix ? "{$name} ({$suffix}) {$arrearsSuffix}" : "{$name} ({$suffix})")
                    : ($arrearsSuffix ? "{$name} {$arrearsSuffix}" : $name),
                'amount' => $lineAmount,
            ]);
        }

        return $invoice->fresh('items');
    }

    /**
     * @param  list<int>  $paidProgrammeFeeIds
     * @param  list<int>  $legacyPaidFeeItemIds
     */
    private function trancheLineAlreadyPaid(
        ProgrammeFee $line,
        array $paidProgrammeFeeIds,
        array $legacyPaidFeeItemIds,
    ): bool {
        if (in_array((int) $line->id, $paidProgrammeFeeIds, true)) {
            return true;
        }

        return $paidProgrammeFeeIds === []
            && in_array((int) $line->fee_item_id, $legacyPaidFeeItemIds, true);
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
        MedicalBill::syncStatusFromInvoice($invoice);

        return $invoice->fresh();
    }

    public function enable(Invoice $invoice): Invoice
    {
        if ($invoice->status !== 'cancelled') {
            throw new RuntimeException('Only disabled invoices can be enabled.');
        }

        if (in_array($invoice->category, ['medical', 'clinic'], true)
            && ! MedicalBill::query()->where('invoice_id', $invoice->id)->exists()) {
            throw new RuntimeException('This clinic invoice was replaced. Finalize the visit again from the clinic.');
        }

        $invoice->status = 'unpaid';
        $invoice->disabled_reason = null;
        $invoice->save();
        MedicalBill::syncStatusFromInvoice($invoice);

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

        MedicalBill::syncStatusFromInvoice($invoice);

        if ($invoice->category === 'course_registration_extension' && $invoice->status === 'paid') {
            app(CourseRegistrationService::class)->markExtensionPaid($invoice);
        }

        if ($invoice->category === 'transcript' && $invoice->status === 'paid') {
            app(TranscriptRequestService::class)->markPaid($invoice);
        }

        return $invoice->fresh();
    }

    private function currentSessionId(): ?int
    {
        return TuitionProgress::currentSessionId();
    }

    private function levelCodeForStudentId(?int $studentId): ?string
    {
        if (! $studentId) {
            return null;
        }
        $level = Student::query()->whereKey($studentId)->value('current_level');

        return $level !== null && $level !== '' ? (string) $level : null;
    }

    private function constrainInvoiceSession($query, ?int $sessionId): void
    {
        if ($sessionId) {
            $query->where('academic_session_id', $sessionId);
        }
    }

    private function arrearsDescriptionSuffix(?AcademicSession $session, ?string $levelCode, Student $student): ?string
    {
        $currentLevel = $student->current_level !== null ? (string) $student->current_level : null;
        $isPriorLevel = $levelCode && $currentLevel && $levelCode !== 'all' && $levelCode !== $currentLevel;
        $isClosedSession = $session?->closed_at !== null;
        if (! $isPriorLevel && ! $isClosedSession) {
            return null;
        }

        $parts = [];
        if ($session?->label) {
            $parts[] = $session->label;
        }
        if ($levelCode && $levelCode !== 'all') {
            $parts[] = $levelCode.' level';
        }
        $parts[] = 'arrears';

        return '('.implode(' · ', $parts).')';
    }
}
