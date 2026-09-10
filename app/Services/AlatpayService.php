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
        $payment = $this->findPendingOrAnyPayment($reference, $transactionId);
        if ($payment->status === 'successful') {
            return $payment;
        }

        $txId = $this->resolveTransactionId($payment, $transactionId, $reference);
        if (! $txId) {
            $txId = $this->findTransactionIdByOrderReference($payment);
        }
        if ($txId && $txId !== $payment->paystack_reference) {
            $payment->update(['paystack_reference' => $txId]);
            $payment->refresh();
        }

        $secret = (string) config('services.wema.secret');
        if ($secret) {
            if (! $txId) {
                throw new RuntimeException(
                    'Payment was started, but Wema/AlatPay has not returned a transaction ID yet. If the student was debited, confirm the transaction on the Wema dashboard and try Requery again shortly.'
                );
            }
            $this->assertAlatpaySuccess($payment, $txId);
        } elseif ($this->demoFulfillAllowed()) {
            // Local/demo only — never when Wema API keys are present or APP_ENV=production.
        } else {
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

        $this->assertValidWebhookSignature($payload, $signature);

        $transactionId = $this->stringValue($data, ['id', 'Id'])
            ?: $this->stringValue(is_array($data['customer'] ?? null) ? $data['customer'] : [], ['transactionId', 'TransactionId']);
        $orderReference = $this->resolveOurOrderReference($data, $transactionId !== '' ? $transactionId : null);

        if ($orderReference === '' && $transactionId === '') {
            return;
        }

        $this->verify($orderReference !== '' ? $orderReference : $transactionId, $transactionId !== '' ? $transactionId : null);
    }

    /**
     * When Wema API keys are configured, unsigned or wrongly signed webhooks are rejected.
     *
     * @param  array<string, mixed>  $payload
     */
    private function assertValidWebhookSignature(array $payload, ?string $signature): void
    {
        $apiSecret = (string) config('services.wema.secret');
        if ($apiSecret === '') {
            throw new RuntimeException('Wema webhooks require API configuration.');
        }

        $webhookSecret = (string) config('services.wema.webhook_secret');
        if ($webhookSecret === '') {
            throw new RuntimeException('Wema webhook secret is not configured.');
        }

        if ($signature === null || $signature === '') {
            throw new RuntimeException('Missing Wema Bank signature.');
        }

        $computed = hash_hmac('sha512', json_encode($payload), $webhookSecret);
        if (! hash_equals($computed, $signature)) {
            throw new RuntimeException('Invalid Wema Bank signature.');
        }
    }

    private function demoFulfillAllowed(): bool
    {
        return PaymentGatewaySettings::demoAllowed()
            && ! app()->isProduction()
            && (string) config('services.wema.secret') === ''
            && (string) config('services.wema.public') === '';
    }

    /**
     * Prefer our WEMA- merchant order reference over AlatPay's internal orderId.
     *
     * @param  array<string, mixed>  $data
     */
    private function resolveOurOrderReference(array $data, ?string $transactionId = null): string
    {
        $orderId = $this->stringValue($data, ['orderId', 'OrderId']);
        if (str_starts_with($orderId, 'WEMA-')) {
            return $orderId;
        }

        $metadata = $data['metadata'] ?? $data['MetaData'] ?? null;
        if (is_array($metadata)) {
            $metaOrder = $this->stringValue($metadata, ['orderId', 'OrderId']);
            if (str_starts_with($metaOrder, 'WEMA-')) {
                return $metaOrder;
            }
        }

        if ($transactionId) {
            $remote = $this->fetchAlatpayTransaction($transactionId);
            if ($remote) {
                $meta = $remote['metadata'] ?? $remote['MetaData'] ?? null;
                if (is_array($meta)) {
                    $metaOrder = $this->stringValue($meta, ['orderId', 'OrderId']);
                    if (str_starts_with($metaOrder, 'WEMA-')) {
                        return $metaOrder;
                    }
                }
                $remoteOrder = $this->stringValue($remote, ['orderId', 'OrderId']);
                if (str_starts_with($remoteOrder, 'WEMA-')) {
                    return $remoteOrder;
                }
            }
        }

        return str_starts_with($orderId, 'WEMA-') ? $orderId : '';
    }

    private function findPendingOrAnyPayment(string $reference, ?string $transactionId = null): Payment
    {
        $payment = Payment::query()
            ->where(function ($query) use ($reference, $transactionId) {
                $query->where('reference', $reference)
                    ->orWhere('paystack_reference', $reference);
                if ($transactionId) {
                    $query->orWhere('paystack_reference', $transactionId)
                        ->orWhere('reference', $transactionId);
                }
            })
            ->latest('id')
            ->first();

        if ($payment) {
            return $payment;
        }

        throw new RuntimeException('No matching payment was found for this Wema/AlatPay transaction.');
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
            if ($requireDemoFlag && ! $this->demoFulfillAllowed()) {
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

        return null;
    }

    /**
     * When callback/webhook never stored AlatPay's transaction id, search completed
     * transactions for one that carries our WEMA- order reference.
     */
    private function findTransactionIdByOrderReference(Payment $payment): ?string
    {
        $businessId = trim((string) config('services.wema.business_id'));
        $secret = (string) config('services.wema.secret');
        if ($businessId === '' || $secret === '') {
            return null;
        }

        $base = rtrim((string) config('services.wema.base', 'https://apibox.alatpay.ng'), '/');
        $startAt = optional($payment->created_at)?->copy()->subDay()->utc()->format('Y-m-d\TH:i:s.000\Z')
            ?: now()->subDays(7)->utc()->format('Y-m-d\TH:i:s.000\Z');
        $endAt = now()->addDay()->utc()->format('Y-m-d\TH:i:s.000\Z');
        $order = (string) $payment->reference;

        for ($page = 1; $page <= 5; $page++) {
            $response = Http::withHeaders([
                'Ocp-Apim-Subscription-Key' => $secret,
                'Content-Type' => 'application/json',
            ])->get($base.'/alatpaytransaction/api/v1/transactions', [
                'businessId' => $businessId,
                'page' => $page,
                'limit' => 50,
                'startAt' => $startAt,
                'endAt' => $endAt,
            ]);

            if (! $response->successful()) {
                return null;
            }

            $payload = $response->json();
            $rows = data_get($payload, 'data');
            if (! is_array($rows)) {
                $rows = data_get($payload, 'data.items') ?? data_get($payload, 'data.data') ?? [];
            }
            if (! is_array($rows)) {
                return null;
            }

            // Some AlatPay responses nest the list under data.transactions / data.result.
            if ($rows !== [] && ! array_is_list($rows)) {
                foreach (['transactions', 'result', 'items', 'records'] as $key) {
                    if (isset($rows[$key]) && is_array($rows[$key]) && array_is_list($rows[$key])) {
                        $rows = $rows[$key];
                        break;
                    }
                }
            }

            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }
                if (! $this->transactionMatchesOrder($payment, $row, $order)) {
                    continue;
                }
                $status = strtolower((string) ($row['status'] ?? ''));
                if ($status !== '' && ! in_array($status, ['completed', 'successful', 'success', 'paid'], true)) {
                    continue;
                }
                $id = $this->stringValue($row, ['id', 'Id', 'transactionId', 'TransactionId']);
                if ($id !== '' && ! str_starts_with($id, 'WEMA-')) {
                    return $id;
                }
            }

            $totalPages = (int) (data_get($payload, 'pagination.totalPages')
                ?? data_get($payload, 'data.pagination.totalPages')
                ?? 1);
            if ($page >= max(1, $totalPages)) {
                break;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function transactionMatchesOrder(Payment $payment, array $row, string $order): bool
    {
        $orderMatched = false;
        $orderId = $this->stringValue($row, ['orderId', 'OrderId']);
        if ($orderId === $order) {
            $orderMatched = true;
        }

        $metadata = $row['metadata'] ?? $row['MetaData'] ?? null;
        if (! $orderMatched && is_array($metadata) && $this->stringValue($metadata, ['orderId', 'OrderId']) === $order) {
            $orderMatched = true;
        }

        // Customer.metadata is sometimes a JSON string from AlatPay.
        $customer = $row['customer'] ?? null;
        if (! $orderMatched && is_array($customer)) {
            $raw = $customer['metadata'] ?? $customer['MetaData'] ?? null;
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded) && $this->stringValue($decoded, ['orderId', 'OrderId']) === $order) {
                    $orderMatched = true;
                }
            }
            if (! $orderMatched && is_array($raw) && $this->stringValue($raw, ['orderId', 'OrderId']) === $order) {
                $orderMatched = true;
            }
        }

        if (! $orderMatched) {
            return false;
        }

        if (is_array($metadata) && $payment->invoice_id) {
            $metaInvoice = $this->stringValue($metadata, ['invoice_id', 'invoiceId', 'InvoiceId']);
            if ($metaInvoice !== '' && $metaInvoice !== (string) $payment->invoice_id) {
                return false;
            }
        }

        // Defense in depth: when AlatPay includes an amount on the list row, it must fit this payment.
        if (isset($row['amount']) && is_numeric($row['amount'])) {
            return $this->alatpayAmountMatches($payment, (float) $row['amount'], $row);
        }

        return true;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchAlatpayTransaction(string $transactionId): ?array
    {
        $secret = (string) config('services.wema.secret');
        if ($secret === '' || $transactionId === '') {
            return null;
        }

        $base = rtrim((string) config('services.wema.base', 'https://apibox.alatpay.ng'), '/');
        $response = Http::withHeaders([
            'Ocp-Apim-Subscription-Key' => $secret,
            'Content-Type' => 'application/json',
        ])->get($base.'/alatpaytransaction/api/v1/transactions/'.$transactionId);

        if (! $response->successful()) {
            return null;
        }

        $data = $response->json('data');

        return is_array($data) ? $data : null;
    }

    private function assertAlatpaySuccess(Payment $payment, string $transactionId): void
    {
        $data = $this->fetchAlatpayTransaction($transactionId);
        if ($data === null) {
            throw new RuntimeException('Payment has not been confirmed by Wema Bank.');
        }

        $status = strtolower((string) ($data['status'] ?? ''));
        if (! in_array($status, ['completed', 'successful', 'success', 'paid'], true)) {
            throw new RuntimeException('Payment has not been confirmed by Wema Bank.');
        }

        $returnedId = $this->stringValue($data, ['id', 'Id']);
        if ($returnedId !== '' && strcasecmp($returnedId, $transactionId) !== 0) {
            throw new RuntimeException('Payment does not match this transaction.');
        }

        // Note: data.orderId in the AlatPay verify response is AlatPay's own internal
        // warehousing identifier (prefixed with the merchant name), NOT the orderId we
        // passed in metadata. Prefer metadata.orderId when AlatPay echoes our reference.
        $this->assertAlatpayReference($payment, $data);

        if (isset($data['amount']) && is_numeric($data['amount'])) {
            $paid = (float) $data['amount'];
            if (! $this->alatpayAmountMatches($payment, $paid, $data)) {
                throw new RuntimeException('Payment amount does not match this transaction.');
            }
        }
    }

    /**
     * Match the invoice/payment amount against AlatPay's reported total.
     * payments.amount stays what the student owes; AlatPay may add a variable gateway fee,
     * so a completed charge is valid when paid >= expected (or when a reported fee explains the gap).
     *
     * @param  array<string, mixed>  $data
     */
    private function alatpayAmountMatches(Payment $payment, float $paid, array $data = []): bool
    {
        $expected = (float) $payment->amount;
        $tolerance = 0.5;

        if (abs($paid - $expected) <= $tolerance) {
            return true;
        }

        // Prefer an explicit net/principal amount from AlatPay when present.
        foreach (['amountExcludingFee', 'netAmount', 'settlementAmount', 'principalAmount', 'baseAmount'] as $key) {
            if (isset($data[$key]) && is_numeric($data[$key]) && abs(((float) $data[$key]) - $expected) <= $tolerance) {
                return true;
            }
        }

        $fee = $this->alatpayReportedFee($data);
        if ($fee !== null) {
            return abs($paid - ($expected + $fee)) <= $tolerance
                || abs(($paid - $fee) - $expected) <= $tolerance;
        }

        // Fee varies and is often not returned separately: accept overpayment that covers the invoice.
        return $paid >= ($expected - $tolerance);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function alatpayReportedFee(array $data): ?float
    {
        foreach (['fee', 'Fee', 'transactionFee', 'TransactionFee', 'charges', 'Charges'] as $key) {
            if (isset($data[$key]) && is_numeric($data[$key])) {
                $fee = (float) $data[$key];
                if ($fee >= 0) {
                    return $fee;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertAlatpayReference(Payment $payment, array $data): void
    {
        $metadata = $data['metadata'] ?? $data['MetaData'] ?? null;
        if (! is_array($metadata)) {
            return;
        }

        $orderId = $this->stringValue($metadata, ['orderId', 'OrderId']);
        if ($orderId === '') {
            return;
        }

        // Only enforce when AlatPay echoed our own WEMA- reference back in metadata.
        if (str_starts_with($orderId, 'WEMA-') && $orderId !== $payment->reference) {
            throw new RuntimeException('Payment does not match this transaction.');
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
