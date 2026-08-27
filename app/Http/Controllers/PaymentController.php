<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Payment;
use App\Services\FeeArrearsService;
use App\Services\PaystackService;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function __construct(
        private PaystackService $paystack,
        private FeeArrearsService $arrears,
    ) {}

    public function index(Request $request)
    {
        $perPage = min(50, max(10, (int) $request->input('per_page', 25)));
        $query = Payment::query()
            ->with([
                'invoice:id,number,student_id,user_id,application_id',
                'invoice.student:id,user_id,first_name,last_name,matric_number,student_number',
                'invoice.application:id,user_id,application_number,jamb_registration',
                'user:id,name,jamb_registration',
                'user.student:id,user_id,first_name,last_name,matric_number,student_number',
                'user.latestApplication',
            ])
            ->latest();
        if (! $request->user()->hasPermission('finance.invoices.manage')) {
            $query->where('user_id', $request->user()->id);
        }

        return $query->paginate($perPage);
    }

    public function initialize(Request $request)
    {
        $data = $request->validate([
            'invoice_id' => 'nullable|exists:invoices,id',
            'type' => 'nullable|in:invoice,wallet_topup',
            'amount' => 'nullable|numeric|min:100',
            'portal' => 'nullable|in:student,staff',
        ]);
        if (($data['type'] ?? 'invoice') === 'wallet_topup') {
            return $this->paystack->initializeWalletTopup(
                $request->user(),
                (float) $data['amount'],
                $data['portal'] ?? 'student',
            );
        }
        $invoice = Invoice::query()->findOrFail($data['invoice_id']);
        abort_unless(
            $invoice->user_id === $request->user()->id,
            403,
            'Staff cannot pay invoices. Students pay from the student portal.'
        );
        $student = $request->user()->student;
        if ($student) {
            $this->arrears->ensureForStudent($student);
            try {
                $this->arrears->assertCanPay($student, $invoice);
            } catch (\RuntimeException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
        }
        $callbackUrl = ($data['portal'] ?? null) === 'student'
            ? rtrim((string) config('app.student_url'), '/').'/payments/callback'
            : null;

        try {
            return $this->paystack->initializeInvoice($request->user(), $invoice, $callbackUrl);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function verify(string $reference)
    {
        return $this->paystack->verify($reference)->load('invoice');
    }

    public function webhook(Request $request)
    {
        $this->paystack->handleWebhook($request->all(), $request->header('x-paystack-signature'));

        return response()->json(['status' => 'ok']);
    }
}
