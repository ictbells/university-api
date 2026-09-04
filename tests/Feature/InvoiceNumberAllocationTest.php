<?php

namespace Tests\Feature;

use App\Models\FeeItem;
use App\Models\Invoice;
use App\Models\User;
use App\Services\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceNumberAllocationTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoice_numbers_do_not_reuse_soft_deleted_sequences(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $fee = FeeItem::query()->create([
            'name' => 'Sundry charge',
            'category' => 'sundry',
            'amount' => 1000,
            'wallet_allowed' => true,
            'is_active' => true,
        ]);

        $service = app(InvoiceService::class);
        $first = $service->createForFee($user, $fee);
        $this->assertMatchesRegularExpression('/^INV-\d{8}-\d{5}$/', $first->number);

        $first->delete();
        $this->assertSoftDeleted('invoices', ['id' => $first->id]);

        $second = $service->createForFee($user, $fee);
        $this->assertNotSame($first->number, $second->number);
        $this->assertSame(1, Invoice::withTrashed()->where('number', $first->number)->count());
        $this->assertSame(1, Invoice::withTrashed()->where('number', $second->number)->count());
    }
}
