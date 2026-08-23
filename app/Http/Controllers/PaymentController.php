<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Payment;
use App\Services\AuditWriter;
use App\Services\InvoiceService;
use App\Services\PaystackService;
use App\Services\StudentCreationService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PaymentController extends Controller
{
    public function __construct(
        private PaystackService $paystack,
        private InvoiceService $invoices,
        private AuditWriter $audit,
        private StudentCreationService $students,
    ) {}

    public function index(Request $request)
    {
        $query = Payment::query()->with(['invoice', 'user'])->latest();
        if (! $request->user()->hasPermission('finance.payments.record')) {
            $query->where('user_id', $request->user()->id);
        }

        return $query->paginate(25);
    }

    public function initialize(Request $request)
    {
        $data = $request->validate([
            'invoice_id' => 'nullable|exists:invoices,id',
            'type' => 'nullable|in:invoice,wallet_topup',
            'amount' => 'nullable|numeric|min:100',
        ]);
        if (($data['type'] ?? 'invoice') === 'wallet_topup') {
            return $this->paystack->initializeWalletTopup($request->user(), (float) $data['amount']);
        }
        $invoice = Invoice::query()->findOrFail($data['invoice_id']);

        return $this->paystack->initializeInvoice($request->user(), $invoice);
    }

    public function verify(string $reference)
    {
        return $this->paystack->verify($reference);
    }

    public function webhook(Request $request)
    {
        $this->paystack->handleWebhook($request->all(), $request->header('x-paystack-signature'));

        return response()->json(['status' => 'ok']);
    }

    public function record(Request $request)
    {
        $data = $request->validate([
            'invoice_id' => 'required|exists:invoices,id',
            'method' => 'required|in:cash,bank,transfer',
            'amount' => 'required|numeric|min:1',
            'reference' => 'nullable|string',
            'reason' => 'nullable|string',
        ]);
        $invoice = Invoice::query()->findOrFail($data['invoice_id']);
        if ($data['method'] === 'wallet') {
            return response()->json(['message' => 'Use the wallet pay endpoint.'], 422);
        }
        $payment = Payment::query()->create([
            'invoice_id' => $invoice->id,
            'user_id' => $invoice->user_id,
            'method' => $data['method'],
            'amount' => $data['amount'],
            'status' => 'successful',
            'reference' => $data['reference'] ?: 'CASH-'.Str::upper(Str::random(8)),
            'receipt_no' => 'RCP-'.Str::upper(Str::random(8)),
            'purpose' => $invoice->category,
        ]);
        $this->invoices->applyPayment($invoice, (float) $data['amount']);
        $invoice = $invoice->fresh();
        $this->audit->record('payment.recorded', 'Cashier payment '.$payment->reference, 'payments', 'payment', $payment->id, null, $payment, $data['reason'] ?? 'Cashier record');
        if ($invoice->status === 'paid' && $invoice->category === 'application_fee' && $invoice->application_id) {
            $app = $invoice->application;
            if ($app && in_array($app->stage, ['started', 'awaiting_application_fee'], true)) {
                $app->update(['stage' => 'fee_paid', 'current_step' => 'biodata']);
            }
        }
        if ($invoice->status === 'paid' && $invoice->category === 'acceptance_fee' && $invoice->application_id) {
            $app = $invoice->application;
            if ($app && ! $app->student_id) {
                $this->students->createFromApplication($app->fresh());
            }
        }

        return $payment->fresh('invoice');
    }
}
