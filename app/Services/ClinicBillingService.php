<?php

namespace App\Services;

use App\Models\ClinicVisit;
use App\Models\FeeItem;
use App\Models\MedicalBill;
use App\Models\MedicalProfile;
use App\Models\Student;
use App\Support\ClinicSettings;
use Illuminate\Support\Facades\DB;

class ClinicBillingService
{
    public function __construct(private InvoiceService $invoices) {}

    public function resolveCoveragePercent(MedicalProfile $profile, ?float $visitOverride = null): float
    {
        if (! ClinicSettings::nhisEnabled() || ! $profile->nhis_enrolled) {
            return 0;
        }
        if ($visitOverride !== null) {
            return max(0, min(100, $visitOverride));
        }
        if ($profile->nhis_coverage_percent !== null) {
            return max(0, min(100, (float) $profile->nhis_coverage_percent));
        }

        return ClinicSettings::nhisDefaultCoveragePercent();
    }

    /**
     * @return array{gross: float, covered: float, payable: float, nhis_applied: bool, coverage_percent: float}
     */
    public function splitAmounts(ClinicVisit $visit, MedicalProfile $profile, ?float $visitOverride = null): array
    {
        $items = $visit->items;
        $gross = round((float) $items->sum('line_total'), 2);
        $coverage = $this->resolveCoveragePercent($profile, $visitOverride);

        if ($coverage <= 0 || ! $profile->nhis_enrolled || ! ClinicSettings::nhisEnabled()) {
            return [
                'gross' => $gross,
                'covered' => 0.0,
                'payable' => $gross,
                'nhis_applied' => false,
                'coverage_percent' => 0.0,
            ];
        }

        $coveredGross = round((float) $items->where('nhis_covered', true)->sum('line_total'), 2);
        $uncoveredGross = round($gross - $coveredGross, 2);
        $nhisCovered = round($coveredGross * ($coverage / 100), 2);
        $payable = round(($coveredGross - $nhisCovered) + $uncoveredGross, 2);

        return [
            'gross' => $gross,
            'covered' => $nhisCovered,
            'payable' => max(0, $payable),
            'nhis_applied' => $nhisCovered > 0,
            'coverage_percent' => $coverage,
        ];
    }

    public function finalize(ClinicVisit $visit, ?float $coverageOverride = null): MedicalBill
    {
        return DB::transaction(function () use ($visit, $coverageOverride) {
            $visit->load(['items', 'bill', 'student.user']);
            abort_if($visit->items->isEmpty(), 422, 'Add at least one charge line before finalizing.');
            abort_if($visit->bill, 422, 'This visit already has a finalized bill.');

            /** @var Student $student */
            $student = $visit->student;
            $profile = MedicalProfile::query()->firstOrCreate(['student_id' => $student->id]);
            $split = $this->splitAmounts($visit, $profile, $coverageOverride);

            $invoice = null;
            $status = 'covered';
            if ($split['payable'] > 0) {
                $fee = FeeItem::query()->firstOrCreate(
                    ['category' => 'medical', 'name' => 'Clinic charge'],
                    ['amount' => $split['payable'], 'wallet_allowed' => true, 'is_active' => true]
                );
                $invoice = $this->invoices->createForFee(
                    $student->user,
                    $fee,
                    $student->application_id,
                    $student->id,
                    $split['payable'],
                    'Clinic charge',
                );
                $status = 'unpaid';
            }

            return $visit->bill()->create([
                'invoice_id' => $invoice?->id,
                'gross_amount' => $split['gross'],
                'nhis_covered_amount' => $split['covered'],
                'student_payable_amount' => $split['payable'],
                'nhis_applied' => $split['nhis_applied'],
                'amount' => $split['payable'],
                'status' => $status,
            ]);
        });
    }
}
