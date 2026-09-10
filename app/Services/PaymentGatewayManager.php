<?php

namespace App\Services;

use App\Contracts\PaymentGateway;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Support\PaymentGatewaySettings;
use RuntimeException;

class PaymentGatewayManager
{
    public function __construct(
        private PaystackService $paystack,
        private AlatpayService $alatpay,
        private PaygateService $paygate,
    ) {}

    public function activeKey(): string
    {
        return PaymentGatewaySettings::active();
    }

    public function driver(?string $key = null): PaymentGateway
    {
        $key = $key ?? $this->activeKey();

        return match ($key) {
            PaymentGatewaySettings::WEMA => $this->alatpay,
            PaymentGatewaySettings::PAYGATE => $this->paygate,
            default => $this->paystack,
        };
    }

    public function driverFor(Payment $payment): PaymentGateway
    {
        $method = strtolower((string) $payment->method);

        return $this->driver(match ($method) {
            PaymentGatewaySettings::WEMA => PaymentGatewaySettings::WEMA,
            PaymentGatewaySettings::PAYGATE => PaymentGatewaySettings::PAYGATE,
            default => PaymentGatewaySettings::PAYSTACK,
        });
    }

    public function initializeInvoice(User $user, Invoice $invoice, ?string $callbackUrl = null): array
    {
        return $this->driver()->initializeInvoice($user, $invoice, $callbackUrl);
    }

    public function initializeWalletTopup(User $user, float $amount, string $portal = 'student'): array
    {
        return $this->driver()->initializeWalletTopup($user, $amount, $portal);
    }

    public function verify(string $reference, ?string $transactionId = null): Payment
    {
        $payment = $this->findPayment($reference, $transactionId);

        return $this->driverFor($payment)->verify($reference, $transactionId);
    }

    /**
     * Re-check pending online payment(s) for a payable invoice against the gateway.
     */
    public function requeryInvoice(Invoice $invoice): Payment
    {
        if (! $invoice->isPayable()) {
            throw new RuntimeException('This invoice is not awaiting payment.');
        }

        $payments = Payment::query()
            ->where('invoice_id', $invoice->id)
            ->where('status', 'pending')
            ->whereIn('method', [
                PaymentGatewaySettings::WEMA,
                PaymentGatewaySettings::PAYSTACK,
                PaymentGatewaySettings::PAYGATE,
            ])
            ->latest('id')
            ->get();

        if ($payments->isEmpty()) {
            throw new RuntimeException('No pending online payment found for this invoice.');
        }

        $lastMessage = 'Payment is still pending with the gateway.';

        foreach ($payments as $payment) {
            $txId = trim((string) $payment->paystack_reference);
            if ($txId === '' || preg_match('/^(WEMA|PSK|UPG)-/i', $txId) === 1) {
                $txId = '';
            }

            try {
                $result = $this->driverFor($payment)->verify(
                    (string) $payment->reference,
                    $txId !== '' ? $txId : null,
                );
                if ($result->status === 'successful') {
                    return $result->load('invoice');
                }
            } catch (RuntimeException $e) {
                $lastMessage = $e->getMessage();
            }
        }

        throw new RuntimeException($lastMessage);
    }

    private function findPayment(string $reference, ?string $transactionId = null): Payment
    {
        return Payment::query()
            ->where(function ($query) use ($reference, $transactionId) {
                $query->where('reference', $reference)
                    ->orWhere('paystack_reference', $reference);
                if ($transactionId) {
                    $query->orWhere('paystack_reference', $transactionId)
                        ->orWhere('reference', $transactionId);
                }
            })
            ->firstOrFail();
    }
}
