<?php

namespace App\Http\Controllers;

use App\Models\FeeItem;
use App\Models\Invoice;
use App\Models\Student;
use App\Services\AuditWriter;
use App\Services\InvoiceService;
use Illuminate\Http\Request;

class FinanceController extends Controller
{
    public function __construct(private InvoiceService $invoices, private AuditWriter $audit) {}

    public function fees()
    {
        return FeeItem::query()->orderBy('category')->get();
    }

    public function storeFee(Request $request)
    {
        $fee = FeeItem::query()->create($request->validate([
            'name' => 'required|string',
            'category' => 'required|string',
            'entry_mode' => 'nullable|string',
            'amount' => 'required|numeric',
            'wallet_allowed' => 'boolean',
        ]));
        if (in_array($fee->category, ['application_fee', 'acceptance_fee'], true)) {
            $fee->update(['wallet_allowed' => false]);
        }

        return $fee;
    }

    public function invoices(Request $request)
    {
        $query = Invoice::query()->with('items')->latest();
        if (! $request->user()->hasPermission('finance.invoices.manage')) {
            $query->where('user_id', $request->user()->id);
        }

        return $query->paginate(25);
    }

    public function generate(Request $request)
    {
        $data = $request->validate([
            'student_id' => 'required|exists:students,id',
            'fee_item_id' => 'required|exists:fee_items,id',
        ]);
        $student = Student::query()->findOrFail($data['student_id']);
        $fee = FeeItem::query()->findOrFail($data['fee_item_id']);
        if (in_array($fee->category, ['application_fee', 'acceptance_fee'], true)) {
            $fee->wallet_allowed = false;
        }
        $invoice = $this->invoices->createForFee($student->user, $fee, $student->application_id, $student->id);
        $this->audit->record('invoice.created', 'Invoice '.$invoice->number, 'fees', 'invoice', $invoice->id, null, $invoice);

        return $invoice;
    }
}
