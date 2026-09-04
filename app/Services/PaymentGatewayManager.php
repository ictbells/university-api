<?php

namespace App\Services;

use App\Contracts\PaymentGateway;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Support\PaymentGatewaySettings;

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
