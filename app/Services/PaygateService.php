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

/**
 * Upperlink PayGate — standard hosted checkout.
 *
 * @see https://documenter.getpostman.com/view/3972913/2s93m33iJi
 */
class PaygateService implements PaymentGateway
{
    public function __construct(private PaymentFulfillmentService $fulfillment) {}

    public function key(): string
    {
        return PaymentGatewaySettings::PAYGATE;
    }

    public function initializeInvoice(User $user, Invoice $invoice, ?string $callbackUrl = null): array
    {
        $this->fulfillment->assertInvoicePayable($invoice);
        $reference = 'UPG-'.Str::upper(Str::random(12));
        $payment = $this->fulfillment->createPendingPayment(
            $user,
            $this->key(),
            $reference,
            (float) $invoice->balance,
            $invoice->id,
            $invoice->category,
        );

        return $this->checkoutPayload(
            $user,
            $payment,
            (float) $invoice->balance,
            $callbackUrl ?: $this->fulfillment->callbackUrl('staff'),
            requireDemoFlag: true,
        );
    }

    public function initializeWalletTopup(User $user, float $amount, string $portal = 'student'): array
    {
        $user->loadMissing('student.wallet');
        if (! $user->student?->wallet) {
            throw new RuntimeException('Wallet is only available after student creation.');
        }
        $reference = 'UPG-W-'.Str::upper(Str::random(12));
        $payment = $this->fulfillment->createPendingPayment(
            $user,
            $this->key(),
            $reference,
            $amount,
            null,
            'wallet_topup',
        );

        return $this->checkoutPayload(
            $user,
            $payment,
            $amount,
            $this->fulfillment->callbackUrl($portal === 'staff' ? 'staff' : 'student'),
            requireDemoFlag: false,
        );
    }

    public function verify(string $reference, ?string $transactionId = null): Payment
    {
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

        if ($this->credentialsReady()) {
            $this->assertPaygateSuccess($payment, $lookup);
        } elseif (! PaymentGatewaySettings::demoAllowed()) {
            throw new RuntimeException('Online payments are not configured. Please pay at the admissions office.');
        }

        return $this->fulfillment->fulfill($payment, 'PayGate');
    }

    public function handleWebhook(array $payload, ?string $signature): void
    {
        WebhookLog::query()->create([
            'provider' => $this->key(),
            'event' => (string) ($payload['transactionCompleted'] ?? $payload['transactionStatus'] ?? 'transaction'),
            'payload' => $payload,
            'status' => 'received',
        ]);

        $secret = (string) config('services.paygate.secret');
        $merchantId = (string) ($payload['merchantId'] ?? config('services.paygate.merchant_id'));
        $payGateRef = (string) ($payload['payGateRef'] ?? '');
        $transactionId = (string) ($payload['transactionId'] ?? '');
        $hash = (string) ($payload['hash'] ?? $signature ?? '');

        if ($secret !== '' && $hash !== '') {
            $computed = strtoupper(hash('sha512', $secret.$merchantId.$payGateRef.$transactionId));
            if (! hash_equals($computed, strtoupper($hash))) {
                throw new RuntimeException('Invalid PayGate webhook signature.');
            }
        }

        if (! $this->isSuccessfulStatus($payload)) {
            return;
        }

        $reference = $payGateRef !== '' ? $payGateRef : $transactionId;
        if ($reference !== '') {
            $this->verify($reference, $transactionId !== '' ? $transactionId : null);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function checkoutPayload(
        User $user,
        Payment $payment,
        float $amount,
        string $callbackUrl,
        bool $requireDemoFlag,
    ): array {
        if (! $this->credentialsReady()) {
            if ($requireDemoFlag && ! PaymentGatewaySettings::demoAllowed()) {
                throw new RuntimeException('Online payments are not configured. Please pay at the admissions office.');
            }

            return [
                'authorization_url' => null,
                'reference' => $payment->reference,
                'demo' => true,
                'payment_id' => $payment->id,
                'provider' => $this->key(),
            ];
        }

        $customer = $this->fulfillment->customer($user);
        $address = $this->payerAddress($user);
        $base = rtrim((string) config('services.paygate.base'), '/');

        $response = Http::withBasicAuth(
            (string) config('services.paygate.username'),
            (string) config('services.paygate.password'),
        )->acceptJson()->asJson()->post($base.'/api/v1/client/integration/transaction/payment', [
            'amount' => (string) round($amount, 2),
            'countryCode' => (string) config('services.paygate.country_code', 'NG'),
            'currency' => (string) config('services.paygate.currency', 'NGN'),
            'email' => (string) $user->email,
            'firstName' => $customer['first'],
            'lastName' => $customer['last'],
            'merchantId' => (string) config('services.paygate.merchant_id'),
            'payGateRef' => $payment->reference,
            'redirectUrl' => $callbackUrl,
            'phone' => $customer['phone'] ?: '08000000000',
            'city' => $address['city'],
            'address' => $address['address'],
            'meta' => json_encode([
                'payment_id' => $payment->id,
                'invoice_id' => $payment->invoice_id,
                'purpose' => $payment->purpose,
            ]),
        ]);

        if (! $response->successful()) {
            throw new RuntimeException($response->json('description') ?: $response->json('message') ?: 'PayGate initialize failed.');
        }

        $checkoutUrl = (string) (
            $response->json('data.checkOutUrl')
            ?: $response->json('data.checkoutUrl')
            ?: $response->json('checkOutUrl')
            ?: ''
        );
        if ($checkoutUrl === '') {
            throw new RuntimeException('PayGate did not return a checkout URL.');
        }

        $gatewayRef = (string) ($response->json('data.payGateRef') ?: $payment->reference);
        if ($gatewayRef !== '' && $gatewayRef !== $payment->reference) {
            $payment->update(['paystack_reference' => $gatewayRef]);
        }

        return [
            'authorization_url' => $checkoutUrl,
            'reference' => $payment->reference,
            'demo' => false,
            'payment_id' => $payment->id,
            'provider' => $this->key(),
        ];
    }

    private function assertPaygateSuccess(Payment $payment, string $lookup): void
    {
        $base = rtrim((string) config('services.paygate.base'), '/');
        $response = Http::withBasicAuth(
            (string) config('services.paygate.username'),
            (string) config('services.paygate.password'),
        )->acceptJson()->get($base.'/api/v1/client/integration/transaction/query', [
            'merchantId' => (string) config('services.paygate.merchant_id'),
            'ref' => $lookup,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException($response->json('description') ?: $response->json('message') ?: 'Payment has not been confirmed by PayGate.');
        }

        $data = $response->json();
        if (! is_array($data)) {
            throw new RuntimeException('Payment has not been confirmed by PayGate.');
        }

        if (! $this->isSuccessfulStatus($data)) {
            throw new RuntimeException('Payment has not been confirmed by PayGate.');
        }

        $payGateRef = (string) ($data['payGateRef'] ?? '');
        if ($payGateRef !== '' && ! in_array($payGateRef, [$payment->reference, (string) $payment->paystack_reference], true)) {
            throw new RuntimeException('Payment does not match this transaction.');
        }

        if (isset($data['amount']) && is_numeric($data['amount'])) {
            $paid = (float) $data['amount'];
            if (abs($paid - (float) $payment->amount) > 0.5) {
                throw new RuntimeException('Payment amount does not match this transaction.');
            }
        }

        $transactionId = (string) ($data['transactionId'] ?? '');
        if ($transactionId !== '' && $transactionId !== $payment->paystack_reference) {
            $payment->update(['paystack_reference' => $transactionId]);
            $payment->refresh();
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function isSuccessfulStatus(array $payload): bool
    {
        $status = strtoupper(trim((string) ($payload['transactionStatus'] ?? '')));
        $completed = strtoupper(trim((string) ($payload['transactionCompleted'] ?? '')));

        if (in_array($status, ['00', 'SUCCESSFUL', 'SUCCESS', 'COMPLETED', 'AUTHORIZED'], true)) {
            return true;
        }

        return in_array($completed, ['SUCCESSFUL', 'SUCCESS', 'COMPLETED', 'AUTHORIZED'], true);
    }

    private function credentialsReady(): bool
    {
        return PaymentGatewaySettings::paygateConfigured();
    }

    /**
     * @return array{city: string, address: string}
     */
    private function payerAddress(User $user): array
    {
        $user->loadMissing(['student', 'latestApplication']);
        $payload = is_array($user->latestApplication?->payload) ? $user->latestApplication->payload : [];
        $city = trim((string) (
            $payload['city']
            ?? $payload['residential_city']
            ?? $user->student?->city
            ?? config('services.paygate.default_city', 'Ota')
        ));
        $address = trim((string) (
            $payload['address']
            ?? $payload['residential_address']
            ?? $user->student?->address
            ?? config('services.paygate.default_address', 'Bells University of Technology, Ota')
        ));

        return [
            'city' => $city !== '' ? $city : 'Ota',
            'address' => $address !== '' ? $address : 'Bells University of Technology, Ota',
        ];
    }
}
