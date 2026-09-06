<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Support\FeeSchedule;
use App\Support\InstitutionLogo;
use App\Support\ReceiptDate;
use App\Support\ReceiptInstitution;
use App\Support\ReceiptPayer;
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
        $payer = $verified && $payment ? ReceiptPayer::forPayment($payment) : [
            'name' => null,
            'id' => null,
            'id_label' => null,
            'programme' => null,
            'level' => null,
        ];

        $html = view('receipts.verify', [
            'institution' => ReceiptInstitution::details(),
            'logo_data_uri' => InstitutionLogo::dataUri(),
            'verified' => $verified,
            'receipt_no' => $receiptNo,
            'payer' => $verified ? $payer['name'] : null,
            'payer_id' => $verified ? $payer['id'] : null,
            'payer_id_label' => $verified ? $payer['id_label'] : null,
            'category_label' => $verified ? $this->categoryLabel($payment) : null,
            'amount' => $verified ? (float) $payment->amount : null,
            'paid_at' => $verified ? ReceiptDate::format($payment->created_at) : null,
        ])->render();

        return response($html, $verified ? 200 : 404, [
            'Content-Type' => 'text/html; charset=UTF-8',
        ]);
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
