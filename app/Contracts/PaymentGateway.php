<?php

namespace App\Contracts;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;

interface PaymentGateway
{
    public function key(): string;

    public function initializeInvoice(User $user, Invoice $invoice, ?string $callbackUrl = null): array;

    public function initializeWalletTopup(User $user, float $amount, string $portal = 'student', string $currency = 'NGN'): array;

    public function verify(string $reference, ?string $transactionId = null): Payment;

    public function handleWebhook(array $payload, ?string $signature): void;
}
