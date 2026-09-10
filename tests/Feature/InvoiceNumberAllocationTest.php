<?php

namespace Tests\Feature;

use App\Models\FeeItem;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Services\BursaryDocumentSequence;
use App\Services\InvoiceService;
use App\Services\PaymentFulfillmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceNumberAllocationTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoice_and_receipt_numbers_use_but_year_serial_format(): void
    {
        config([
            'sis.bursary_doc_prefix' => 'BUT',
            'sis.bursary_doc_year' => 2026,
            'sis.bursary_doc_digits' => 4,
            'sis.bursary_doc_last' => '',
        ]);

        $user = User::factory()->create(['status' => 'active']);
        $fee = FeeItem::query()->create([
            'name' => 'Sundry charge',
            'category' => 'sundry',
            'amount' => 1000,
            'wallet_allowed' => true,
            'is_active' => true,
        ]);

        $service = app(InvoiceService::class);
        $first = $service->createForFee($user, $fee);
        $this->assertSame('BUT/2026/0001', $first->number);

        $first->delete();
        $this->assertSoftDeleted('invoices', ['id' => $first->id]);

        $second = $service->createForFee($user, $fee);
        $this->assertSame('BUT/2026/0002', $second->number);
        $this->assertNotSame($first->number, $second->number);

        $payment = Payment::query()->create([
            'invoice_id' => $second->id,
            'user_id' => $user->id,
            'method' => 'paystack',
            'amount' => 1000,
            'status' => 'pending',
            'reference' => 'PSK-BUT-1',
            'purpose' => 'sundry',
        ]);
        $fulfilled = app(PaymentFulfillmentService::class)->fulfill($payment, 'Test');
        $this->assertSame('BUT/2026/0002', $fulfilled->receipt_no);
        $this->assertSame($second->number, $fulfilled->receipt_no);
    }

    public function test_wallet_topup_receipt_still_allocates_bursary_serial(): void
    {
        config([
            'sis.bursary_doc_prefix' => 'BUT',
            'sis.bursary_doc_year' => 2026,
            'sis.bursary_doc_digits' => 4,
            'sis.bursary_doc_last' => '',
        ]);

        $user = User::factory()->create(['status' => 'active']);
        $payment = Payment::query()->create([
            'invoice_id' => null,
            'user_id' => $user->id,
            'method' => 'paystack',
            'amount' => 5000,
            'status' => 'pending',
            'reference' => 'PSK-TOPUP-1',
            'purpose' => 'wallet_topup',
        ]);

        $fulfilled = app(PaymentFulfillmentService::class)->fulfill($payment, 'Test');
        $this->assertSame('BUT/2026/0001', $fulfilled->receipt_no);
    }

    public function test_note_issued_advances_shared_sequence(): void
    {
        config([
            'sis.bursary_doc_prefix' => 'BUT',
            'sis.bursary_doc_year' => 2026,
            'sis.bursary_doc_digits' => 4,
            'sis.bursary_doc_last' => '',
        ]);

        $seq = app(BursaryDocumentSequence::class);
        $seq->noteIssued('BUT/2026/0042');
        $this->assertSame('BUT/2026/0043', $seq->allocate());
    }
}
