<?php

namespace App\Services;

use App\Contracts\PaymentGateway;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Models\WebhookLog;
use App\Support\PaymentGatewaySettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class AlatpayService implements PaymentGateway
{
    /**
     * Shared completed-transaction rows for one reconcile/requery batch.
     * Avoids re-paging AlatPay once per pending payment.
     *
     * @var list<array<string, mixed>>|null
     */
    private ?array $sharedCompletedTransactions = null;

    /** @var array<string, array<string, mixed>|null> */
    private array $transactionDetailCache = [];

    public function __construct(private PaymentFulfillmentService $fulfillment) {}

    public function key(): string
    {
        return PaymentGatewaySettings::WEMA;
    }

    /**
     * Prefetch completed AlatPay transactions for a date window (used by bulk reconcile).
     */
    public function beginCompletedTransactionLookup(\DateTimeInterface $from, \DateTimeInterface $to): void
    {
        $startAt = \Carbon\Carbon::parse($from)->utc()->format('Y-m-d\TH:i:s.000\Z');
        $endAt = \Carbon\Carbon::parse($to)->utc()->format('Y-m-d\TH:i:s.000\Z');

        $this->sharedCompletedTransactions = $this->listAlatpayTransactions($startAt, $endAt);
    }

    public function endCompletedTransactionLookup(): void
    {
        $this->sharedCompletedTransactions = null;
        $this->transactionDetailCache = [];
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

        $this->assertValidWebhookSignature($payload, $signature, $data);

        $transactionId = $this->stringValue($data, ['id', 'Id'])
            ?: $this->stringValue(is_array($data['customer'] ?? null) ? $data['customer'] : [], ['transactionId', 'TransactionId']);
        $orderReference = $this->resolveOurOrderReference($data, $transactionId !== '' ? $transactionId : null);

        if ($orderReference === '' && $transactionId === '') {
            return;
        }

        $this->verify($orderReference !== '' ? $orderReference : $transactionId, $transactionId !== '' ? $transactionId : null);
    }

    /**
     * Prefer HMAC when AlatPay sends a signature header. If no header is present
     * (common in current AlatPay deliveries), require a transaction id in the
     * payload and authenticate later via GET /transactions/{id} inside verify().
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $data
     */
    private function assertValidWebhookSignature(array $payload, ?string $signature, array $data): void
    {
        $apiSecret = (string) config('services.wema.secret');
        if ($apiSecret === '') {
            throw new RuntimeException('Wema webhooks require API configuration.');
        }

        $webhookSecret = (string) config('services.wema.webhook_secret');
        $signature = is_string($signature) ? trim($signature) : '';

        if ($signature !== '') {
            if ($webhookSecret === '') {
                throw new RuntimeException('Wema webhook secret is not configured.');
            }

            $candidates = [
                hash_hmac('sha512', json_encode($payload), $webhookSecret),
                hash_hmac('sha256', json_encode($payload), $webhookSecret),
            ];
            foreach ($candidates as $computed) {
                if (hash_equals($computed, $signature)) {
                    return;
                }
            }

            throw new RuntimeException('Invalid Wema Bank signature.');
        }

        $transactionId = $this->stringValue($data, ['id', 'Id'])
            ?: $this->stringValue(is_array($data['customer'] ?? null) ? $data['customer'] : [], ['transactionId', 'TransactionId']);

        if ($transactionId === '' || str_starts_with($transactionId, 'WEMA-')) {
            throw new RuntimeException('Missing Wema Bank signature.');
        }

        // No signature header — authenticity is enforced by verifying this tx id
        // against AlatPay with our subscription key before fulfilling.
        Log::info('Wema webhook accepted without signature header; will verify via AlatPay API', [
            'transaction_id' => $transactionId,
        ]);
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

        $metadata = $this->decodeMetadata($data['metadata'] ?? $data['MetaData'] ?? null);
        if ($metadata !== []) {
            $metaOrder = $this->stringValue($metadata, ['orderId', 'OrderId']);
            if (str_starts_with($metaOrder, 'WEMA-')) {
                return $metaOrder;
            }
        }

        if ($transactionId) {
            $remote = $this->fetchAlatpayTransaction($transactionId);
            if ($remote) {
                $meta = $this->decodeMetadata($remote['metadata'] ?? $remote['MetaData'] ?? null);
                if ($meta !== []) {
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

        $order = (string) $payment->reference;
        $rows = $this->sharedCompletedTransactions;
        if ($rows === null) {
            $startAt = optional($payment->created_at)?->copy()->subDay()->utc()->format('Y-m-d\TH:i:s.000\Z')
                ?: now()->subDays(7)->utc()->format('Y-m-d\TH:i:s.000\Z');
            $endAt = now()->addDay()->utc()->format('Y-m-d\TH:i:s.000\Z');
            $rows = $this->listAlatpayTransactions($startAt, $endAt);
        }

        // Pass 1: match from list payload (orderId / metadata).
        foreach ($rows as $row) {
            $id = $this->completedListRowTransactionId($payment, $row, $order, enrich: false);
            if ($id !== null) {
                return $id;
            }
        }

        // Pass 2: list rows often omit usable metadata (merchant-prefixed orderId only).
        // Detail-fetch amount candidates — same path that works with --transaction-id.
        foreach ($rows as $row) {
            $id = $this->completedListRowTransactionId($payment, $row, $order, enrich: true);
            if ($id !== null) {
                return $id;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    private function listAlatpayTransactions(string $startAt, string $endAt, array $filters = []): array
    {
        $businessId = trim((string) config('services.wema.business_id'));
        $secret = (string) config('services.wema.secret');
        if ($businessId === '' || $secret === '') {
            return [];
        }

        $base = rtrim((string) config('services.wema.base', 'https://apibox.alatpay.ng'), '/');
        $all = [];

        for ($page = 1; $page <= 50; $page++) {
            $query = array_merge([
                'businessId' => $businessId,
                'page' => $page,
                'limit' => 100,
                'startAt' => $startAt,
                'endAt' => $endAt,
            ], $filters);

            $response = Http::withHeaders([
                'Ocp-Apim-Subscription-Key' => $secret,
                'Content-Type' => 'application/json',
            ])->get($base.'/alatpaytransaction/api/v1/transactions', $query);

            if (! $response->successful()) {
                Log::warning('AlatPay transaction list lookup failed', [
                    'status' => $response->status(),
                    'body' => $response->json('message') ?: $response->body(),
                    'page' => $page,
                    'startAt' => $startAt,
                    'endAt' => $endAt,
                ]);

                break;
            }

            $payload = $response->json();
            $rows = $this->extractTransactionListRows(is_array($payload) ? $payload : []);
            foreach ($rows as $row) {
                $all[] = $row;
            }

            $totalPages = (int) (data_get($payload, 'pagination.totalPages')
                ?? data_get($payload, 'data.pagination.totalPages')
                ?? 1);
            if ($page >= max(1, $totalPages) || $rows === []) {
                break;
            }
        }

        return $all;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function extractTransactionListRows(array $payload): array
    {
        $rows = data_get($payload, 'data');
        if (! is_array($rows)) {
            $rows = data_get($payload, 'data.items')
                ?? data_get($payload, 'data.data')
                ?? data_get($payload, 'data.transactions')
                ?? [];
        }
        if (! is_array($rows)) {
            return [];
        }

        if ($rows !== [] && ! array_is_list($rows)) {
            foreach (['transactions', 'result', 'items', 'records', 'data'] as $key) {
                if (isset($rows[$key]) && is_array($rows[$key]) && array_is_list($rows[$key])) {
                    $rows = $rows[$key];
                    break;
                }
            }
        }

        // Docs sample a single object under data; treat a transaction-shaped object as one row.
        if ($rows !== [] && ! array_is_list($rows)) {
            if (isset($rows['id']) || isset($rows['Id']) || isset($rows['orderId']) || isset($rows['OrderId'])) {
                return [$rows];
            }

            return [];
        }

        return array_values(array_filter($rows, 'is_array'));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function completedListRowTransactionId(Payment $payment, array $row, string $order, bool $enrich): ?string
    {
        $status = strtolower((string) ($row['status'] ?? ''));
        if ($status !== '' && ! in_array($status, ['completed', 'successful', 'success', 'paid'], true)) {
            return null;
        }

        $id = $this->stringValue($row, ['id', 'Id', 'transactionId', 'TransactionId']);
        if ($id === '' || str_starts_with($id, 'WEMA-')) {
            return null;
        }

        $candidate = $row;
        if ($enrich) {
            // Only detail-fetch rows that could plausibly be this payment (amount / no amount).
            if (isset($row['amount']) && is_numeric($row['amount'])
                && ! $this->alatpayAmountMatches($payment, (float) $row['amount'], $row)) {
                return null;
            }
            if ($this->transactionMatchesOrder($payment, $row, $order)) {
                return $id;
            }
            $detail = $this->fetchAlatpayTransactionCached($id);
            if (! is_array($detail)) {
                return null;
            }
            $candidate = $detail;
        }

        if (! $this->transactionMatchesOrder($payment, $candidate, $order)) {
            return null;
        }

        return $id;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchAlatpayTransactionCached(string $transactionId): ?array
    {
        if (array_key_exists($transactionId, $this->transactionDetailCache)) {
            return $this->transactionDetailCache[$transactionId];
        }

        $detail = $this->fetchAlatpayTransaction($transactionId);
        $this->transactionDetailCache[$transactionId] = $detail;

        return $detail;
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

        // AlatPay often returns metadata as a JSON string (same as merchant dashboard).
        $metadata = $this->decodeMetadata($row['metadata'] ?? $row['MetaData'] ?? null);
        if (! $orderMatched && $metadata !== [] && $this->stringValue($metadata, ['orderId', 'OrderId']) === $order) {
            $orderMatched = true;
        }

        $customer = $row['customer'] ?? null;
        if (! $orderMatched && is_array($customer)) {
            $customerMeta = $this->decodeMetadata($customer['metadata'] ?? $customer['MetaData'] ?? null);
            if ($customerMeta !== [] && $this->stringValue($customerMeta, ['orderId', 'OrderId']) === $order) {
                $orderMatched = true;
            }
        }

        if (! $orderMatched) {
            return false;
        }

        if ($metadata !== [] && $payment->invoice_id) {
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
     * AlatPay returns metadata as either an object or a JSON-encoded string.
     *
     * @return array<string, mixed>
     */
    private function decodeMetadata(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
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
        foreach (['fee', 'Fee', 'feeAmount', 'FeeAmount', 'transactionFee', 'TransactionFee', 'charges', 'Charges'] as $key) {
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
        $metadata = $this->decodeMetadata($data['metadata'] ?? $data['MetaData'] ?? null);
        if ($metadata === []) {
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
