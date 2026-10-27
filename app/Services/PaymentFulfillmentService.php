<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Support\FeeSchedule;
use Illuminate\Support\Facades\DB;

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
        if (
            ! FeeSchedule::onlinePaymentAllowed($invoice->category)
            && ! PublicPayRequestService::invoiceIsPublicPay($invoice)
        ) {
            abort(422, 'This invoice must be paid from the campus wallet. Only application, acceptance, transcript, and public request fees can be paid online.');
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
        if ($invoiceId) {
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
        return DB::transaction(function () use ($payment, $source) {
            // Callback verify and gateway webhooks both call fulfill for the same
            // reference; claim the row under lock so only one path runs side effects.
            $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->first();
            if (! $locked) {
                return $payment;
            }
            if ($locked->status === 'successful') {
                return $locked;
            }

            $locked->loadMissing(['invoice', 'user.student.wallet']);
            $locked->update([
                'status' => 'successful',
                'receipt_no' => $this->receiptNumberFor($locked),
            ]);

            if ($locked->invoice_id) {
                $invoice = $locked->invoice;
                $this->invoices->applyPayment($invoice, (float) $locked->amount);
                $this->abandonSiblingPendingPayments($locked);
                $this->audit->record(
                    'payment.'.strtolower($locked->method ?: 'online'),
                    $source.' payment for '.$invoice->number,
                    'payments',
                    'invoice',
                    $invoice->id,
                    null,
                    $invoice->fresh(),
                );
                $this->notifier->send($locked->user, 'payment', 'Payment received', 'Receipt '.$locked->receipt_no, 'payments', $locked->id);
                $this->admissions->handleInvoicePaid($invoice->fresh());
            } elseif ($locked->purpose === 'wallet_topup') {
                $wallet = $locked->user?->student?->wallet;
                if ($wallet) {
                    $this->wallets->credit(
                        $wallet,
                        (float) $locked->amount,
                        $source.' wallet funding',
                        'payments',
                        $locked->id,
                        $locked->reference,
                    );
                }
            }

            return $locked->fresh();
        });
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

    /**
     * Official bursary doc is the invoice number end-to-end.
     * Only allocate a new BUT serial when there is no invoice (e.g. wallet top-up).
     */
    private function receiptNumberFor(Payment $payment): string
    {
        $invoiceNumber = trim((string) ($payment->invoice?->number ?? ''));
        if ($invoiceNumber !== '') {
            return $invoiceNumber;
        }

        return app(BursaryDocumentSequence::class)->allocate();
    }
}
