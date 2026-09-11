<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Payment;
use App\Services\FeeArrearsService;
use App\Services\PaymentGatewayManager;
use App\Support\SchoolFeeAccess;
use Illuminate\Http\Request;
use RuntimeException;

class PaymentController extends Controller
{
    public function __construct(
        private PaymentGatewayManager $gateways,
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

        $status = strtolower(trim((string) $request->input('status', '')));
        if ($status === 'success') {
            $status = 'successful';
        }
        if (in_array($status, ['pending', 'successful', 'failed', 'abandoned'], true)) {
            $query->where('status', $status);
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
            return $this->gateways->initializeWalletTopup(
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
        try {
            SchoolFeeAccess::assertCanPayInvoice($request->user(), $invoice);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
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
            return $this->gateways->initializeInvoice($request->user(), $invoice, $callbackUrl);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function verify(Request $request, string $reference)
    {
        $transactionId = $request->query('transactionId') ?: $request->query('transaction_id');

        try {
            return $this->gateways->verify($reference, $transactionId ? (string) $transactionId : null)->load('invoice');
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function webhook(Request $request)
    {
        $this->gateways->driver('paystack')->handleWebhook($request->all(), $request->header('x-paystack-signature'));

        return response()->json(['status' => 'ok']);
    }

    public function wemaWebhook(Request $request)
    {
        $signature = $this->wemaWebhookSignature($request);

        try {
            $this->gateways->driver('wema')->handleWebhook($request->all(), $signature);
        } catch (RuntimeException $e) {
            $message = $e->getMessage();
            if (
                str_contains($message, 'signature')
                || str_contains($message, 'webhook secret')
                || str_contains($message, 'webhooks require')
            ) {
                return response()->json(['message' => $message], 401);
            }

            return response()->json(['message' => $message], 422);
        }

        return response()->json(['status' => 'ok']);
    }

    private function wemaWebhookSignature(Request $request): ?string
    {
        foreach ([
            'x-alatpay-signature',
            'x-wema-signature',
            'x-webhook-signature',
            'x-signature',
            'signature',
            'alatpay-signature',
        ] as $header) {
            $value = trim((string) $request->header($header, ''));
            if ($value !== '') {
                return $value;
            }
        }

        $authorization = trim((string) $request->header('Authorization', ''));
        if (preg_match('/^(?:Bearer|Signature)\s+(.+)$/i', $authorization, $matches) === 1) {
            return trim($matches[1]);
        }

        return null;
    }

    public function paygateWebhook(Request $request)
    {
        $this->gateways->driver('paygate')->handleWebhook(
            $request->all(),
            $request->input('hash') ? (string) $request->input('hash') : null,
        );

        return response()->json(['code' => '00', 'message' => 'Successful']);
    }
}
