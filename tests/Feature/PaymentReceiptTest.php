<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PaymentReceiptTest extends TestCase
{
    use RefreshDatabase;

    public function test_paid_invoice_receipt_shows_official_bursary_layout(): void
    {
        $user = User::factory()->create(['name' => 'Ada Okoye', 'status' => 'active']);
        $invoice = Invoice::query()->create([
            'number' => 'INV-RECEIPT-1',
            'user_id' => $user->id,
            'category' => 'application_fee',
            'amount' => 5000,
            'full_amount' => 5000,
            'balance' => 0,
            'status' => 'paid',
            'wallet_allowed' => false,
        ]);
        $invoice->items()->create([
            'description' => 'Application fee',
            'amount' => 5000,
        ]);
        $invoice->payments()->create([
            'user_id' => $user->id,
            'method' => 'paystack',
            'amount' => 5000,
            'status' => 'successful',
            'reference' => 'PSK-RCP-1',
            'receipt_no' => 'RCP-TEST01',
            'purpose' => 'application_fee',
        ]);

        Sanctum::actingAs($user);
        $html = $this->get('/api/invoices/'.$invoice->id.'/receipt')
            ->assertOk()
            ->assertHeader('content-type', 'text/html; charset=UTF-8')
            ->getContent();

        $this->assertStringContainsString('Official payment receipt', $html);
        $this->assertStringContainsString('Bursary Department', $html);
        $this->assertStringContainsString('RCP-TEST01', $html);
        $this->assertStringContainsString('Ada Okoye', $html);
        $this->assertStringContainsString('Application fee', $html);
        $this->assertStringContainsString('Five Thousand Naira Only', $html);
        $this->assertStringContainsString('INV-RECEIPT-1', $html);
    }

    public function test_wallet_receipt_shows_amount_in_words(): void
    {
        $user = User::factory()->create(['name' => 'Chidi Eze', 'status' => 'active']);
        $payment = Payment::query()->create([
            'invoice_id' => null,
            'user_id' => $user->id,
            'method' => 'paystack',
            'amount' => 10000.50,
            'status' => 'successful',
            'reference' => 'PSK-WLT-1',
            'receipt_no' => 'RCP-WLT01',
            'purpose' => 'wallet_topup',
        ]);

        Sanctum::actingAs($user);
        $html = $this->get('/api/payments/'.$payment->id.'/receipt')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Wallet funding receipt', $html);
        $this->assertStringContainsString('RCP-WLT01', $html);
        $this->assertStringContainsString('Ten Thousand Naira and Fifty Kobo Only', $html);
        $this->assertStringContainsString('Chidi Eze', $html);
    }
}
