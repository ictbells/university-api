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

class AlatpayService implements PaymentGateway
{
    public function __construct(private PaymentFulfillmentService $fulfillment) {}

    public function key(): string
    {
        return PaymentGatewaySettings::WEMA;
    }

    public function initializeInvoice(User $user, Invoice $invoice, ?string $callbackUrl = null): array
    {
        $this->fulfillment->assertInvoicePayable($invoice);
        $reference = 'WEMA-'.Str::upper(Str::random(12));
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
            [
                'orderId' => $reference,
                'invoice_id' => (string) $invoice->id,
                'purpose' => $invoice->category,
            ],
            requireDemoFlag: true,
        );
    }

    public function initializeWalletTopup(User $user, float $amount, string $portal = 'student'): array
    {
        $user->loadMissing('student.wallet');
        if (! $user->student?->wallet) {
            throw new RuntimeException('Wallet is only available after student creation.');
        }
        $reference = 'WEMA-W-'.Str::upper(Str::random(12));
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
            [
                'orderId' => $reference,
                'purpose' => 'wallet_topup',
                'portal' => $portal,
            ],
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

        $txId = $this->resolveTransactionId($payment, $transactionId, $reference);
        if ($txId && $txId !== $payment->paystack_reference) {
            $payment->update(['paystack_reference' => $txId]);
            $payment->refresh();
        }

        $secret = (string) config('services.wema.secret');
        if ($secret) {
            if (! $txId) {
                throw new RuntimeException('Payment has not been confirmed by Wema Bank.');
            }
            $this->assertAlatpaySuccess($payment, $txId);
        } elseif (! PaymentGatewaySettings::demoAllowed()) {
            throw new RuntimeException('Online payments are not configured. Please pay at the admissions office.');
        }

        return $this->fulfillment->fulfill($payment, 'Wema Bank');
    }

    public function handleWebhook(array $payload, ?string $signature): void
    {
        $data = $this->extractWebhookData($payload);
        WebhookLog::query()->create([
            'provider' => $this->key(),
            'event' => $data['status'] ?? ($payload['event'] ?? 'transaction'),
            'payload' => $payload,
            'status' => 'received',
        ]);

        $secret = (string) config('services.wema.webhook_secret');
        if ($secret && $signature) {
            $computed = hash_hmac('sha512', json_encode($payload), $secret);
            if (! hash_equals($computed, $signature)) {
                throw new RuntimeException('Invalid Wema Bank signature.');
            }
        }

        $orderId = $this->stringValue($data, ['orderId', 'OrderId']);
        $transactionId = $this->stringValue($data, ['id', 'Id'])
            ?: $this->stringValue(is_array($data['customer'] ?? null) ? $data['customer'] : [], ['transactionId', 'TransactionId']);

        $reference = $orderId ?: $transactionId;
        if ($reference) {
            $this->verify($reference, $transactionId ?: null);
        }
    }

    /**
     * @param  array<string, string>  $metadata
     * @return array<string, mixed>
     */
    private function checkoutPayload(
        User $user,
        Payment $payment,
        float $amount,
        string $callbackUrl,
        array $metadata,
        bool $requireDemoFlag,
    ): array {
        $public = (string) config('services.wema.public');
        $businessId = (string) config('services.wema.business_id');
        if ($public === '' || $businessId === '') {
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

        return [
            'authorization_url' => null,
            'reference' => $payment->reference,
            'demo' => false,
            'payment_id' => $payment->id,
            'provider' => $this->key(),
            'checkout' => [
                'api_key' => $public,
                'business_id' => $businessId,
                'amount' => round($amount, 2),
                'currency' => 'NGN',
                'email' => $user->email,
                'first_name' => $customer['first'],
                'last_name' => $customer['last'],
                'phone' => $customer['phone'],
                'callback_url' => $callbackUrl,
                'metadata' => $metadata,
            ],
        ];
    }

    private function resolveTransactionId(Payment $payment, ?string $transactionId, string $reference): ?string
    {
        foreach ([$transactionId, $payment->paystack_reference, $reference] as $candidate) {
            $value = trim((string) $candidate);
            if ($value !== '' && ! str_starts_with($value, 'WEMA-')) {
                return $value;
            }
        }

        return $transactionId ?: null;
    }

    private function assertAlatpaySuccess(Payment $payment, string $transactionId): void
    {
        $base = rtrim((string) config('services.wema.base', 'https://apibox.alatpay.ng'), '/');
        $response = Http::withHeaders([
            'Ocp-Apim-Subscription-Key' => (string) config('services.wema.secret'),
            'Content-Type' => 'application/json',
        ])->get($base.'/alatpaytransaction/api/v1/transactions/'.$transactionId);

        if (! $response->successful()) {
            throw new RuntimeException($response->json('message') ?: 'Payment has not been confirmed by Wema Bank.');
        }

        $data = $response->json('data') ?? [];
        $status = strtolower((string) ($data['status'] ?? ''));
        if (! in_array($status, ['completed', 'successful', 'success', 'paid'], true)) {
            throw new RuntimeException('Payment has not been confirmed by Wema Bank.');
        }

        // Note: data.orderId in the AlatPay verify response is AlatPay's own internal
        // warehousing identifier (prefixed with the merchant name), NOT the orderId we
        // passed in metadata. We therefore cannot use it to cross-check our reference.
        // The transactionId we queried by is already sufficient to identify the transaction.

        if (isset($data['amount'])) {
            $paid = (float) $data['amount'];
            if (abs($paid - (float) $payment->amount) > 0.5) {
                throw new RuntimeException('Payment amount does not match this transaction.');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function extractWebhookData(array $payload): array
    {
        $nested = data_get($payload, 'Value.Data');
        if (is_array($nested)) {
            return $nested;
        }
        $data = data_get($payload, 'data');
        if (is_array($data)) {
            return $data;
        }
        $value = data_get($payload, 'Value');
        if (is_array($value) && isset($value['Data']) && is_array($value['Data'])) {
            return $value['Data'];
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $keys
     */
    private function stringValue(array $data, array $keys): string
    {
        foreach ($keys as $key) {
            if (isset($data[$key]) && is_scalar($data[$key]) && (string) $data[$key] !== '') {
                return (string) $data[$key];
            }
        }

        return '';
    }
}
