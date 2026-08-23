<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Models\WebhookLog;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class PaystackService
{
    public function __construct(
        private InvoiceService $invoices,
        private WalletService $wallets,
        private StudentCreationService $students,
        private AuditWriter $audit,
        private Notifier $notifier,
    ) {}

    public function initializeInvoice(User $user, Invoice $invoice): array
    {
        if (in_array($invoice->category, ['application_fee', 'acceptance_fee'], true) && $invoice->wallet_allowed) {
            throw new RuntimeException('This fee cannot use the wallet.');
        }
        $reference = 'PSK-'.Str::upper(Str::random(12));
        $payment = Payment::query()->create([
            'invoice_id' => $invoice->id,
            'user_id' => $user->id,
            'method' => 'paystack',
            'amount' => $invoice->balance,
            'status' => 'pending',
            'reference' => $reference,
            'paystack_reference' => $reference,
            'purpose' => $invoice->category,
        ]);

        $secret = config('services.paystack.secret');
        if (! $secret) {
            return [
                'authorization_url' => null,
                'access_code' => 'demo',
                'reference' => $reference,
                'demo' => true,
                'payment_id' => $payment->id,
            ];
        }

        $response = Http::withToken($secret)->post('https://api.paystack.co/transaction/initialize', [
            'email' => $user->email,
            'amount' => (int) round(((float) $invoice->balance) * 100),
            'reference' => $reference,
            'callback_url' => config('app.frontend_url').'/payments/callback',
            'metadata' => ['invoice_id' => $invoice->id, 'purpose' => $invoice->category],
        ]);
        if (! $response->successful()) {
            throw new RuntimeException($response->json('message') ?: 'Paystack initialize failed.');
        }

        return [
            ...$response->json('data'),
            'demo' => false,
            'payment_id' => $payment->id,
        ];
    }

    public function initializeWalletTopup(User $user, float $amount): array
    {
        if (! $user->student?->wallet) {
            throw new RuntimeException('Wallet is only available after student creation.');
        }
        $reference = 'PSK-W-'.Str::upper(Str::random(12));
        $payment = Payment::query()->create([
            'invoice_id' => null,
            'user_id' => $user->id,
            'method' => 'paystack',
            'amount' => $amount,
            'status' => 'pending',
            'reference' => $reference,
            'paystack_reference' => $reference,
            'purpose' => 'wallet_topup',
        ]);

        $secret = config('services.paystack.secret');
        if (! $secret) {
            return [
                'authorization_url' => null,
                'reference' => $reference,
                'demo' => true,
                'payment_id' => $payment->id,
            ];
        }

        $response = Http::withToken($secret)->post('https://api.paystack.co/transaction/initialize', [
            'email' => $user->email,
            'amount' => (int) round($amount * 100),
            'reference' => $reference,
            'callback_url' => config('app.frontend_url').'/payments/callback',
            'metadata' => ['purpose' => 'wallet_topup'],
        ]);

        return [
            ...$response->json('data'),
            'demo' => false,
            'payment_id' => $payment->id,
        ];
    }

    public function verify(string $reference): Payment
    {
        $secret = config('services.paystack.secret');
        $payment = Payment::query()->where('reference', $reference)->orWhere('paystack_reference', $reference)->firstOrFail();
        if ($payment->status === 'successful') {
            return $payment;
        }

        if ($secret) {
            $response = Http::withToken($secret)->get('https://api.paystack.co/transaction/verify/'.$reference);
            if (! $response->json('status') || ($response->json('data.status') !== 'success')) {
                throw new RuntimeException('Payment has not been confirmed by Paystack.');
            }
        }

        return $this->fulfill($payment);
    }

    public function fulfill(Payment $payment): Payment
    {
        if ($payment->status === 'successful') {
            return $payment;
        }
        $payment->update([
            'status' => 'successful',
            'receipt_no' => 'RCP-'.Str::upper(Str::random(8)),
        ]);

        if ($payment->invoice_id) {
            $invoice = $payment->invoice;
            $this->invoices->applyPayment($invoice, (float) $payment->amount);
            $this->audit->record('payment.paystack', 'Paystack payment for '.$invoice->number, 'payments', 'invoice', $invoice->id, null, $invoice->fresh());
            $this->notifier->send($payment->user, 'payment', 'Payment received', 'Receipt '.$payment->receipt_no, 'payments', $payment->id);
            $this->afterInvoicePaid($invoice->fresh());
        } elseif ($payment->purpose === 'wallet_topup') {
            $wallet = $payment->user->student?->wallet;
            if ($wallet) {
                $this->wallets->credit($wallet, (float) $payment->amount, 'Paystack wallet funding', 'payments', $payment->id, $payment->reference);
            }
        }

        return $payment->fresh();
    }

    public function handleWebhook(array $payload, ?string $signature): void
    {
        WebhookLog::query()->create([
            'provider' => 'paystack',
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

    private function afterInvoicePaid(Invoice $invoice): void
    {
        if ($invoice->category === 'application_fee' && $invoice->application_id) {
            $app = $invoice->application()->first() ?? \App\Models\Application::query()->find($invoice->application_id);
            if ($app && in_array($app->stage, ['started', 'awaiting_application_fee'], true)) {
                $app->update(['stage' => 'fee_paid', 'current_step' => 'biodata']);
                $this->notifier->send($app->user, 'application_fee', 'Application fee received', 'You can now complete your application form.', 'admissions', $app->id);
            }
        }
        if ($invoice->category === 'acceptance_fee' && $invoice->application_id) {
            $app = \App\Models\Application::query()->find($invoice->application_id);
            if ($app && ! $app->student_id) {
                $app->update(['stage' => 'acceptance_paid']);
                $this->students->createFromApplication($app->fresh());
            }
        }
    }
}
