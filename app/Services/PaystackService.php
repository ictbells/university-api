<?php

namespace App\Services;

use App\Contracts\PaymentGateway;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Models\WebhookLog;
use App\Support\PaymentGatewaySettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class PaystackService implements PaymentGateway
{
    public function __construct(private PaymentFulfillmentService $fulfillment) {}

    public function key(): string
    {
        return PaymentGatewaySettings::PAYSTACK;
    }

    public function initializeInvoice(User $user, Invoice $invoice, ?string $callbackUrl = null): array
    {
        $this->fulfillment->assertInvoicePayable($invoice);
        $reference = 'PSK-'.Str::upper(Str::random(12));
        $payment = $this->fulfillment->createPendingPayment(
            $user,
            $this->key(),
            $reference,
            (float) $invoice->balance,
            $invoice->id,
            $invoice->category,
        );

        $secret = config('services.paystack.secret');
        if (! $secret) {
            if (! PaymentGatewaySettings::demoAllowed()) {
                throw new RuntimeException('Online payments are not configured. Please pay at the admissions office.');
            }

            return [
                'authorization_url' => null,
                'access_code' => 'demo',
                'reference' => $reference,
                'demo' => true,
                'payment_id' => $payment->id,
                'provider' => $this->key(),
            ];
        }

        $response = Http::withToken($secret)->post('https://api.paystack.co/transaction/initialize', [
            'email' => $user->email,
            'amount' => (int) round(((float) $invoice->balance) * 100),
            'reference' => $reference,
            'callback_url' => $callbackUrl ?: $this->fulfillment->callbackUrl('staff'),
            'metadata' => ['invoice_id' => $invoice->id, 'purpose' => $invoice->category],
        ]);
        if (! $response->successful()) {
            throw new RuntimeException($response->json('message') ?: 'Paystack initialize failed.');
        }

        return [
            ...$response->json('data'),
            'demo' => false,
            'payment_id' => $payment->id,
            'provider' => $this->key(),
        ];
    }

    public function initializeWalletTopup(User $user, float $amount, string $portal = 'student'): array
    {
        $user->loadMissing('student.wallet');
        if (! $user->student?->wallet) {
            throw new RuntimeException('Wallet is only available after student creation.');
        }
        $reference = 'PSK-W-'.Str::upper(Str::random(12));
        $payment = $this->fulfillment->createPendingPayment(
            $user,
            $this->key(),
            $reference,
            $amount,
            null,
            'wallet_topup',
        );

        $secret = config('services.paystack.secret');
        if (! $secret) {
            return [
                'authorization_url' => null,
                'reference' => $reference,
                'demo' => true,
                'payment_id' => $payment->id,
                'provider' => $this->key(),
            ];
        }

        $response = Http::withToken($secret)->post('https://api.paystack.co/transaction/initialize', [
            'email' => $user->email,
            'amount' => (int) round($amount * 100),
            'reference' => $reference,
            'callback_url' => $this->fulfillment->callbackUrl($portal === 'staff' ? 'staff' : 'student'),
            'metadata' => ['purpose' => 'wallet_topup', 'portal' => $portal],
        ]);
        if (! $response->successful()) {
            throw new RuntimeException($response->json('message') ?: 'Paystack initialize failed.');
        }

        return [
            ...$response->json('data'),
            'demo' => false,
            'payment_id' => $payment->id,
            'provider' => $this->key(),
        ];
    }

    public function verify(string $reference, ?string $transactionId = null): Payment
    {
        $secret = config('services.paystack.secret');
        $lookup = $transactionId ?: $reference;
        $payment = Payment::query()
            ->where('reference', $lookup)
            ->orWhere('paystack_reference', $lookup)
            ->orWhere('reference', $reference)
            ->orWhere('paystack_reference', $reference)
            ->firstOrFail();
        if ($payment->status === 'successful') {
            return $payment;
        }

        if ($secret) {
            $response = Http::withToken($secret)->get('https://api.paystack.co/transaction/verify/'.$reference);
            if (! $response->json('status') || ($response->json('data.status') !== 'success')) {
                throw new RuntimeException('Payment has not been confirmed by Paystack.');
            }
        } elseif (! PaymentGatewaySettings::demoAllowed()) {
            throw new RuntimeException('Online payments are not configured. Please pay at the admissions office.');
        }

        return $this->fulfillment->fulfill($payment, 'Paystack');
    }

    public function handleWebhook(array $payload, ?string $signature): void
    {
        WebhookLog::query()->create([
            'provider' => $this->key(),
            'event' => $payload['event'] ?? null,
            'payload' => $payload,
            'status' => 'received',
        ]);
        $secret = config('services.paystack.secret');
        if ($secret && $signature) {
            $computed = hash_hmac('sha512', json_encode($payload), $secret);
            if (! hash_equals($computed, $signature)) {
                throw new RuntimeException('Invalid Paystack signature.');
            }
        }
        $reference = data_get($payload, 'data.reference');
        if ($reference) {
            $this->verify($reference);
        }
    }
}
