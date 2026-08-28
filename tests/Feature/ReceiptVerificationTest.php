<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Support\ReceiptQr;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReceiptVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_signed_verify_url_confirms_a_successful_payment(): void
    {
        $user = User::factory()->create(['name' => 'Ada Okoye', 'status' => 'active']);
        $invoice = Invoice::query()->create([
            'number' => 'INV-VERIFY-1',
            'user_id' => $user->id,
            'category' => 'application_fee',
            'amount' => 5000,
            'full_amount' => 5000,
            'balance' => 0,
            'status' => 'paid',
            'wallet_allowed' => false,
        ]);
        $invoice->payments()->create([
            'user_id' => $user->id,
            'method' => 'paystack',
            'amount' => 5000,
            'status' => 'successful',
            'reference' => 'PSK-VERIFY-1',
            'receipt_no' => 'RCP-VERIFY1',
            'purpose' => 'application_fee',
        ]);

        $html = $this->get(ReceiptQr::verifyUrl('RCP-VERIFY1'))
            ->assertOk()
            ->assertHeader('content-type', 'text/html; charset=UTF-8')
            ->getContent();

        $this->assertStringContainsString('Receipt verified', $html);
        $this->assertStringContainsString('RCP-VERIFY1', $html);
        $this->assertStringContainsString('Ada Okoye', $html);
        $this->assertStringContainsString('5,000.00', $html);
        $this->assertStringContainsString('PAID', $html);
        $this->assertStringContainsString('Application fee', $html);
    }

    public function test_unsigned_or_tampered_verify_url_is_forbidden(): void
    {
        $this->get('/api/receipts/RCP-VERIFY1/verify')->assertForbidden();

        $signed = ReceiptQr::verifyUrl('RCP-VERIFY1');
        $this->get($signed.'tampered')->assertForbidden();
        $this->get(str_replace('RCP-VERIFY1', 'RCP-OTHER1', $signed))->assertForbidden();
    }

    public function test_pending_payment_is_not_verifiable(): void
    {
        $user = User::factory()->create(['name' => 'Bola Adebayo', 'status' => 'active']);
        Payment::query()->create([
            'invoice_id' => null,
            'user_id' => $user->id,
            'method' => 'paystack',
            'amount' => 8000,
            'status' => 'pending',
            'reference' => 'PSK-PEND-1',
            'receipt_no' => 'RCP-PEND01',
            'purpose' => 'wallet_topup',
        ]);

        $html = $this->get(ReceiptQr::verifyUrl('RCP-PEND01'))
            ->assertNotFound()
            ->getContent();

        $this->assertStringContainsString('This receipt could not be verified', $html);
        $this->assertStringNotContainsString('Receipt verified', $html);
        $this->assertStringNotContainsString('8,000.00', $html);
    }

    public function test_unknown_receipt_does_not_confirm_payment(): void
    {
        $html = $this->get(ReceiptQr::verifyUrl('RCP-NOSUCH'))
            ->assertNotFound()
            ->getContent();

        $this->assertStringContainsString('This receipt could not be verified', $html);
        $this->assertStringNotContainsString('Receipt verified', $html);
    }

    public function test_qr_data_uri_is_an_svg_image(): void
    {
        $uri = ReceiptQr::dataUri(ReceiptQr::verifyUrl('RCP-QRCODE'));

        $this->assertStringStartsWith('data:image/svg+xml;base64,', $uri);
        $svg = base64_decode(substr($uri, strlen('data:image/svg+xml;base64,')));
        $this->assertStringContainsString('<svg', $svg);
    }
}
