<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\OfficeDepartment;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class ReportExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (PermissionCatalog::all() as $perm) {
            Permission::query()->updateOrCreate(['key' => $perm['key']], $perm);
        }
    }

    public function test_payment_report_returns_numeric_amounts_and_totals(): void
    {
        $user = $this->financeReporter();
        $this->seedPayments();

        Sanctum::actingAs($user);
        $response = $this->postJson('/api/reports/run', [
            'dataset' => 'payments',
            'columns' => ['receipt_no', 'payer', 'amount', 'status'],
        ])->assertOk();

        $amount = $response->json('rows.0.amount');
        $this->assertIsNumeric($amount);
        $this->assertEquals(15000.5, $response->json('totals.amount'));
        $this->assertSame('number', $response->json('columns.2.type'));
        $this->assertTrue($response->json('columns.2.aggregatable'));
    }

    public function test_payment_report_excel_writes_amount_as_number_with_total_row(): void
    {
        $user = $this->financeReporter();
        $this->seedPayments();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/reports/export', [
            'dataset' => 'payments',
            'columns' => ['receipt_no', 'payer', 'amount', 'status'],
            'format' => 'excel',
            'title' => 'Report of Payment so far',
        ])->assertOk();

        $content = $this->binary($response);
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($path, $content);

        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getActiveSheet();
        unlink($path);

        $amountCol = null;
        $headerRow = null;
        foreach ($sheet->getRowIterator() as $row) {
            foreach ($row->getCellIterator('A', $sheet->getHighestColumn()) as $cell) {
                if (strcasecmp((string) $cell->getValue(), 'Amount') === 0) {
                    $amountCol = $cell->getColumn();
                    $headerRow = $cell->getRow();
                    break 2;
                }
            }
        }

        $this->assertNotNull($amountCol);
        $valueCell = $sheet->getCell($amountCol.($headerRow + 1));
        $this->assertNotSame(DataType::TYPE_STRING, $valueCell->getDataType());
        $this->assertIsNumeric($valueCell->getValue());

        $highest = (int) $sheet->getHighestRow();
        $this->assertSame('Total', (string) $sheet->getCell('A'.$highest)->getValue());
        $totalCell = $sheet->getCell($amountCol.$highest);
        $this->assertEquals(15000.5, (float) $totalCell->getValue());
        $this->assertNotSame(DataType::TYPE_STRING, $totalCell->getDataType());
    }

    public function test_payment_report_pdf_downloads(): void
    {
        $user = $this->financeReporter();
        $this->seedPayments();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/reports/export', [
            'dataset' => 'payments',
            'columns' => ['receipt_no', 'payer', 'amount', 'status'],
            'format' => 'pdf',
            'title' => 'Report of Payment so far',
        ])->assertOk();

        $response->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $this->binary($response));
        $this->assertStringContainsString('attachment', (string) $response->headers->get('content-disposition'));
    }

    private function seedPayments(): void
    {
        $payer = User::factory()->create(['name' => 'Ada Lovelace', 'status' => 'active']);
        $invoice = Invoice::query()->create([
            'number' => 'INV-RPT-1',
            'user_id' => $payer->id,
            'category' => 'sundry',
            'amount' => 20000,
            'full_amount' => 20000,
            'balance' => 5000,
            'status' => 'partial',
            'wallet_allowed' => true,
        ]);
        Payment::query()->create([
            'invoice_id' => $invoice->id,
            'user_id' => $payer->id,
            'method' => 'paystack',
            'amount' => 10000.25,
            'status' => 'successful',
            'reference' => 'PAY-RPT-1',
            'receipt_no' => 'RCPT-1',
            'purpose' => 'sundry',
        ]);
        Payment::query()->create([
            'invoice_id' => $invoice->id,
            'user_id' => $payer->id,
            'method' => 'wallet',
            'amount' => 5000.25,
            'status' => 'successful',
            'reference' => 'PAY-RPT-2',
            'receipt_no' => 'RCPT-2',
            'purpose' => 'sundry',
        ]);
    }

    private function financeReporter(): User
    {
        return $this->staffUser(['reports.view', 'finance.invoices.manage'], ['home', 'reports']);
    }

    /**
     * @param  \Illuminate\Testing\TestResponse  $response
     */
    private function binary($response): string
    {
        try {
            $streamed = $response->streamedContent();
            if (is_string($streamed) && $streamed !== '') {
                return $streamed;
            }
        } catch (\Throwable) {
            // Not a streamed download.
        }

        return (string) $response->getContent();
    }

    /**
     * @param  list<string>  $permissions
     * @param  list<string>  $navKeys
     */
    private function staffUser(array $permissions, array $navKeys): User
    {
        $role = Role::query()->create([
            'name' => 'Test '.implode('-', $permissions),
            'slug' => 'test-'.substr(sha1(implode(',', $permissions).implode(',', $navKeys).uniqid('', true)), 0, 12),
            'is_system' => false,
            'is_active' => true,
        ]);
        $ids = Permission::query()->whereIn('key', $permissions)->pluck('id');
        $role->permissions()->sync($ids);

        $office = $this->officeOwning($navKeys);
        if (! $office) {
            $office = OfficeDepartment::query()->create([
                'name' => 'Test office '.$role->slug,
                'code' => substr($role->slug, 0, 20),
                'is_active' => true,
            ]);
            $office->syncNavKeys($navKeys);
        }

        $user = User::factory()->create();
        $user->roles()->attach($role->id);
        Staff::query()->create([
            'user_id' => $user->id,
            'staff_number' => 'ST-'.strtoupper(substr($role->slug, -8)),
            'office_department_id' => $office->id,
        ]);

        return $user->fresh(['roles.permissions', 'staff']);
    }

    /**
     * @param  list<string>  $navKeys
     */
    private function officeOwning(array $navKeys): ?OfficeDepartment
    {
        foreach ($navKeys as $key) {
            if ($key === 'home') {
                continue;
            }
            $link = \App\Models\OfficeNavLink::query()->where('nav_key', $key)->first();
            if (! $link) {
                continue;
            }
            $owner = app(\App\Services\OfficeNavOwnerResolver::class)->departmentAndUnit($link->linkable);

            return $owner['department'] ?? null;
        }

        return null;
    }
}
