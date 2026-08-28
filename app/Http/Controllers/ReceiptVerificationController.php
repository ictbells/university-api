<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Support\FeeSchedule;
use App\Support\InstitutionLogo;
use App\Support\ReceiptInstitution;
use Illuminate\Http\Response;

class ReceiptVerificationController extends Controller
{
    public function show(string $receipt_no): Response
    {
        $payment = Payment::query()
            ->where('receipt_no', $receipt_no)
            ->where('status', 'successful')
            ->latest('id')
            ->first();

        if (! $payment) {
            return $this->html(false, $receipt_no, null);
        }

        return $this->html(true, $receipt_no, $payment);
    }

    private function html(bool $verified, string $receiptNo, ?Payment $payment): Response
    {
        $payment?->load(['user.student', 'invoice.student', 'invoice.application', 'invoice.user']);
        $payerId = $verified ? $this->payerId($payment) : ['label' => null, 'value' => null];

        $html = view('receipts.verify', [
            'institution' => ReceiptInstitution::details(),
            'logo_data_uri' => InstitutionLogo::dataUri(),
            'verified' => $verified,
            'receipt_no' => $receiptNo,
            'payer' => $verified ? $this->payerName($payment) : null,
            'payer_id' => $payerId['value'],
            'payer_id_label' => $payerId['label'],
            'category_label' => $verified ? $this->categoryLabel($payment) : null,
            'amount' => $verified ? (float) $payment->amount : null,
            'paid_at' => $verified ? (optional($payment->created_at)->format('d M Y, h:i A') ?: '—') : null,
        ])->render();

        return response($html, $verified ? 200 : 404, [
            'Content-Type' => 'text/html; charset=UTF-8',
        ]);
    }

    private function payerName(?Payment $payment): string
    {
        $invoice = $payment?->invoice;
        $student = $invoice?->student ?: $payment?->user?->student;

        return $invoice?->user?->name
            ?: $payment?->user?->name
            ?: trim(implode(' ', array_filter([$student?->first_name, $student?->last_name])))
            ?: '—';
    }

    /**
     * @return array{label: string, value: ?string}
     */
    private function payerId(?Payment $payment): array
    {
        $invoice = $payment?->invoice;
        $student = $invoice?->student ?: $payment?->user?->student;
        $application = $invoice?->application;

        $value = $student?->matric_number;
        $label = 'Matric number';
        if (! $value) {
            $value = $application?->jamb_registration
                ?: $invoice?->user?->jamb_registration
                ?: $payment?->user?->jamb_registration
                ?: $application?->application_number;
            $label = ($application?->jamb_registration || $invoice?->user?->jamb_registration || $payment?->user?->jamb_registration)
                ? 'JAMB number'
                : 'Application number';
        }

        return ['label' => $label, 'value' => $value];
    }

    private function categoryLabel(?Payment $payment): string
    {
        if (! $payment?->invoice_id || $payment->purpose === 'wallet_topup') {
            return 'Campus wallet funding';
        }

        $invoice = $payment->invoice;
        if (! $invoice) {
            return FeeSchedule::label((string) ($payment->purpose ?: 'payment'));
        }

        $label = FeeSchedule::label((string) $invoice->category);
        if ($invoice->category === 'tuition' && $invoice->installment_percent !== null) {
            $label .= ' ('.(int) $invoice->installment_percent.'%)';
        }

        return $label;
    }
}
