<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends BaseModel
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'full_amount' => 'decimal:2',
            'balance' => 'decimal:2',
            'rebate_total' => 'decimal:2',
            'wallet_allowed' => 'boolean',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function rebates(): HasMany
    {
        return $this->hasMany(InvoiceRebate::class)->whereNull('reversed_at')->latest('id');
    }

    public function allRebates(): HasMany
    {
        return $this->hasMany(InvoiceRebate::class)->latest('id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function latestPendingOnlinePayment(): ?Payment
    {
        return $this->payments()
            ->where('status', 'pending')
            ->whereIn('method', ['wema', 'paystack', 'paygate'])
            ->latest('id')
            ->first();
    }

    /**
     * @return array{id: int, reference: string, transaction_id: string|null, method: string, amount: float}|null
     */
    public function pendingOnlinePaymentPayload(): ?array
    {
        if (! $this->isPayable()) {
            return null;
        }

        $payment = $this->relationLoaded('payments')
            ? $this->payments
                ->where('status', 'pending')
                ->filter(fn (Payment $row) => in_array($row->method, ['wema', 'paystack', 'paygate'], true))
                ->sortByDesc('id')
                ->first()
            : $this->latestPendingOnlinePayment();

        if (! $payment) {
            return null;
        }

        $txId = trim((string) $payment->paystack_reference);
        if ($txId === '' || preg_match('/^(WEMA|PSK|UPG)-/i', $txId) === 1) {
            $txId = '';
        }

        return [
            'id' => $payment->id,
            'reference' => (string) $payment->reference,
            'transaction_id' => $txId !== '' ? $txId : null,
            'method' => (string) $payment->method,
            'amount' => (float) $payment->amount,
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function academicSession(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class, 'academic_session_id');
    }

    public function isPayable(): bool
    {
        return in_array($this->status, ['unpaid', 'partial'], true);
    }

    public function shareLabel(): ?string
    {
        if ($this->relationLoaded('items')) {
            $order = ['1st 25%', '2nd 25%', '3rd 25%', '4th 25%', 'Full 100% (pay at once)'];
            $found = [];
            foreach ($this->items as $item) {
                $text = (string) $item->description;
                foreach ($order as $label) {
                    $short = $label === 'Full 100% (pay at once)' ? 'Full 100%' : $label;
                    if (str_contains($text, $short) || str_contains($text, $label)) {
                        $found[$short] = $short;
                    }
                }
            }
            if ($found !== []) {
                $labels = [];
                foreach (['1st 25%', '2nd 25%', '3rd 25%', '4th 25%', 'Full 100%'] as $label) {
                    if (isset($found[$label])) {
                        $labels[] = $label;
                    }
                }

                return implode(' + ', $labels);
            }
        }

        if ($this->installment_percent) {
            return ((int) $this->installment_percent).'% installment';
        }

        return null;
    }
}
