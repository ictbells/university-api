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

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function isPayable(): bool
    {
        return in_array($this->status, ['unpaid', 'partial'], true);
    }
}
