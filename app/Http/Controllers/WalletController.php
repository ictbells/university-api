<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Student;
use App\Services\FeeArrearsService;
use App\Services\PaymentGatewayManager;
use App\Services\WalletService;
use App\Support\SchoolFeeAccess;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    public function __construct(
        private WalletService $wallets,
        private PaymentGatewayManager $gateways,
        private FeeArrearsService $arrears,
    ) {}

    public function show(Request $request)
    {
        $student = $this->resolveStudent($request);
        abort_unless($student?->wallet, 404, 'Wallet is created after acceptance fee and student creation.');

        $this->arrears->ensureForStudent($student);

        $wallet = $student->wallet;
        $wallet->load([
            'transactions' => fn ($q) => $q->latest('id')->limit(25),
        ]);
        $prior = $this->arrears->priorUnpaid($student);

        return [
            'id' => $wallet->id,
            'balance' => $wallet->balance,
            'transactions' => $wallet->transactions,
            'outstanding' => $this->arrears->outstandingAmount($student),
            'open_invoice_count' => $this->arrears->openCount($student),
            'prior_unpaid_count' => $prior->count(),
            'prior_unpaid_amount' => round((float) $prior->sum('balance'), 2),
        ];
    }

    public function payInvoice(Request $request, Invoice $invoice)
    {
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

        $student = Student::query()
            ->with('wallet')
            ->where('user_id', $request->user()->id)
            ->first();
        abort_unless($student, 403);

        try {
            $this->arrears->assertCanPay($student, $invoice);
            return $this->wallets->payInvoice($student, $invoice);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function topup(Request $request)
    {
        $data = $request->validate([
            'amount' => 'required|numeric|min:100',
            'portal' => 'nullable|in:student,staff',
        ]);

        try {
            return $this->gateways->initializeWalletTopup(
                $request->user(),
                (float) $data['amount'],
                $data['portal'] ?? 'student',
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    private function resolveStudent(Request $request): ?Student
    {
        if ($request->filled('student_id') && $request->user()->hasPermission('wallet.view_any')) {
            return Student::query()->with('wallet')->findOrFail($request->student_id);
        }

        return Student::query()
            ->with('wallet')
            ->where('user_id', $request->user()->id)
            ->first();
    }
}
