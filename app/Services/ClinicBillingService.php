<?php

namespace App\Services;

use App\Models\ClinicVisit;
use App\Models\MedicalBill;
use App\Models\MedicalProfile;
use App\Models\Student;
use App\Support\ClinicSettings;
use Illuminate\Support\Facades\DB;

class ClinicBillingService
{
    public function __construct(private InvoiceService $invoices) {}

    /**
     * @return array{mode: 'none'|'percent'|'amount', percent: float, amount: ?float}
     */
    public function describeCoverage(MedicalProfile $profile, ?float $visitOverride = null): array
    {
        if (! ClinicSettings::nhisEnabled() || ! $profile->nhis_enrolled) {
            return ['mode' => 'none', 'percent' => 0.0, 'amount' => null];
        }
        if ($visitOverride !== null) {
            return [
                'mode' => 'percent',
                'percent' => max(0, min(100, $visitOverride)),
                'amount' => null,
            ];
        }
        if ($profile->nhis_coverage_amount !== null) {
            return [
                'mode' => 'amount',
                'percent' => 0.0,
                'amount' => max(0, round((float) $profile->nhis_coverage_amount, 2)),
            ];
        }
        $percent = $profile->nhis_coverage_percent !== null
            ? max(0, min(100, (float) $profile->nhis_coverage_percent))
            : ClinicSettings::nhisDefaultCoveragePercent();

        return ['mode' => 'percent', 'percent' => $percent, 'amount' => null];
    }

    public function resolveCoveragePercent(MedicalProfile $profile, ?float $visitOverride = null): float
    {
        return $this->describeCoverage($profile, $visitOverride)['percent'];
    }

    /**
     * @return array{gross: float, covered: float, payable: float, nhis_applied: bool, coverage_percent: float, coverage_amount: ?float, coverage_mode: string}
     */
    public function splitAmounts(ClinicVisit $visit, MedicalProfile $profile, ?float $visitOverride = null): array
    {
        $items = $visit->items;
        $gross = round((float) $items->sum('line_total'), 2);
        $cover = $this->describeCoverage($profile, $visitOverride);
        $empty = [
            'gross' => $gross,
            'covered' => 0.0,
            'payable' => $gross,
            'nhis_applied' => false,
            'coverage_percent' => 0.0,
            'coverage_amount' => null,
            'coverage_mode' => 'none',
        ];

        if ($cover['mode'] === 'none') {
            return $empty;
        }
        if ($cover['mode'] === 'percent' && $cover['percent'] <= 0) {
            return $empty;
        }
        if ($cover['mode'] === 'amount' && ($cover['amount'] ?? 0) <= 0) {
            return $empty;
        }

        $coveredGross = round((float) $items->where('nhis_covered', true)->sum('line_total'), 2);
        $uncoveredGross = round($gross - $coveredGross, 2);
        $nhisCovered = $cover['mode'] === 'amount'
            ? round(min((float) $cover['amount'], $coveredGross), 2)
            : round($coveredGross * ($cover['percent'] / 100), 2);
        $payable = round(($coveredGross - $nhisCovered) + $uncoveredGross, 2);

        return [
            'gross' => $gross,
            'covered' => $nhisCovered,
            'payable' => max(0, $payable),
            'nhis_applied' => $nhisCovered > 0,
            'coverage_percent' => $cover['percent'],
            'coverage_amount' => $cover['amount'],
            'coverage_mode' => $cover['mode'],
        ];
    }

    public function finalize(ClinicVisit $visit, ?float $coverageOverride = null): MedicalBill
    {
        return DB::transaction(function () use ($visit, $coverageOverride) {
            $visit->load(['items', 'bill.invoice', 'student.user']);
            abort_if($visit->items->isEmpty(), 422, 'Add at least one charge line before finalizing.');
            $existing = $visit->bill;
            abort_if($existing?->isLive(), 422, 'This visit already has a finalized bill.');

            /** @var Student $student */
            $student = $visit->student;
            $profile = MedicalProfile::query()->firstOrCreate(['student_id' => $student->id]);
            $split = $this->splitAmounts($visit, $profile, $coverageOverride);

            $invoice = null;
            $status = 'covered';
            if ($split['payable'] > 0) {
                $invoice = $this->invoices->createClinicVisitInvoice(
                    $student,
                    $visit->items,
                    $split['payable'],
                    $split['covered'],
                );
                $status = 'unpaid';
            }

            $payload = [
                'invoice_id' => $invoice?->id,
                'gross_amount' => $split['gross'],
                'nhis_covered_amount' => $split['covered'],
                'student_payable_amount' => $split['payable'],
                'nhis_applied' => $split['nhis_applied'],
                'amount' => $split['payable'],
                'status' => $status,
            ];

            if ($existing) {
                $existing->update($payload);

                return $existing->fresh('invoice');
            }

            return $visit->bill()->create($payload);
        });
    }
}
