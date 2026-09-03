<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Support\FeeSchedule;
use App\Support\PaymentGatewaySettings;
use Illuminate\Support\Str;

class PaymentFulfillmentService
{
    public function __construct(
        private InvoiceService $invoices,
        private WalletService $wallets,
        private ApplicationAdmissionService $admissions,
        private AuditWriter $audit,
        private Notifier $notifier,
    ) {}

    public function assertInvoicePayable(Invoice $invoice): void
    {
        if (! $invoice->isPayable()) {
            abort(422, 'This invoice cannot be paid.');
        }
        if (! FeeSchedule::onlinePaymentAllowed($invoice->category)) {
            abort(422, 'This invoice must be paid from the campus wallet. Only application, acceptance, and transcript fees can be paid online.');
        }
    }

    public function createPendingPayment(
        User $user,
        string $method,
        string $reference,
        float $amount,
        ?int $invoiceId,
        string $purpose,
    ): Payment {
        if ($invoiceId && $method === PaymentGatewaySettings::WEMA) {
            $existing = Payment::query()
                ->where('invoice_id', $invoiceId)
                ->where('user_id', $user->id)
                ->where('method', $method)
                ->where('status', 'pending')
                ->latest('id')
                ->first();
            if ($existing) {
                $existing->update([
                    'amount' => $amount,
                    'purpose' => $purpose,
                ]);

                return $existing->fresh();
            }
        }

        return Payment::query()->create([
            'invoice_id' => $invoiceId,
            'user_id' => $user->id,
            'method' => $method,
            'amount' => $amount,
            'status' => 'pending',
            'reference' => $reference,
            'paystack_reference' => $reference,
            'purpose' => $purpose,
        ]);
    }

    public function callbackUrl(string $portal): string
    {
        $base = $portal === 'staff'
            ? config('app.frontend_url')
            : config('app.student_url');

        return rtrim((string) $base, '/').'/payments/callback';
    }

    /**
     * @return array{first: string, last: string, phone: ?string}
     */
    public function customer(User $user): array
    {
        $user->loadMissing('student');
        $first = trim((string) ($user->student?->first_name ?: ''));
        $last = trim((string) ($user->student?->last_name ?: ''));
        if ($first === '' || $last === '') {
            $parts = preg_split('/\s+/', trim((string) $user->name), 2) ?: [];
            $first = $first !== '' ? $first : (string) ($parts[0] ?? 'Student');
            $last = $last !== '' ? $last : (string) ($parts[1] ?? $first);
        }

        $phone = $user->student?->phone ?: $user->phone;

        return [
            'first' => $first !== '' ? $first : 'Student',
            'last' => $last !== '' ? $last : $first,
            'phone' => $phone ? (string) $phone : null,
        ];
    }

    public function fulfill(Payment $payment, string $source): Payment
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
            $this->abandonSiblingPendingPayments($payment);
            $this->audit->record(
                'payment.'.strtolower($payment->method ?: 'online'),
                $source.' payment for '.$invoice->number,
                'payments',
                'invoice',
                $invoice->id,
                null,
                $invoice->fresh(),
            );
            $this->notifier->send($payment->user, 'payment', 'Payment received', 'Receipt '.$payment->receipt_no, 'payments', $payment->id);
            $this->admissions->handleInvoicePaid($invoice->fresh());
        } elseif ($payment->purpose === 'wallet_topup') {
            $wallet = $payment->user->student?->wallet;
            if ($wallet) {
                $this->wallets->credit($wallet, (float) $payment->amount, $source.' wallet funding', 'payments', $payment->id, $payment->reference);
            }
        }

        return $payment->fresh();
    }

    public function abandonSiblingPendingPayments(Payment $payment): void
    {
        if (! $payment->invoice_id) {
            return;
        }

        Payment::query()
            ->where('invoice_id', $payment->invoice_id)
            ->whereKeyNot($payment->id)
            ->where('status', 'pending')
            ->update(['status' => 'abandoned']);
    }
}
