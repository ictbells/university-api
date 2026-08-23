<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Student;
use App\Services\PaystackService;
use App\Services\WalletService;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    public function __construct(private WalletService $wallets, private PaystackService $paystack) {}

    public function show(Request $request)
    {
        $student = $this->resolveStudent($request);
        abort_unless($student?->wallet, 404, 'Wallet is created after acceptance fee and student creation.');

        return $student->wallet->load(['transactions' => fn ($q) => $q->latest(), 'credentials']);
    }

    public function payInvoice(Request $request, Invoice $invoice)
    {
        $student = $this->resolveStudent($request);
        abort_unless($student, 403);

        return $this->wallets->payInvoice($student, $invoice);
    }

    public function topup(Request $request)
    {
        $data = $request->validate(['amount' => 'required|numeric|min:100']);

        return $this->paystack->initializeWalletTopup($request->user(), (float) $data['amount']);
    }

    private function resolveStudent(Request $request): ?Student
    {
        if ($request->filled('student_id') && $request->user()->hasPermission('wallet.view_any')) {
            return Student::query()->with('wallet')->findOrFail($request->student_id);
        }

        return $request->user()->student?->load('wallet');
    }
}
