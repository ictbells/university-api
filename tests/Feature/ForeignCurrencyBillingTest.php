<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\AcademicTerm;
use App\Models\Application;
use App\Models\Intake;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use App\Models\Wallet;
use App\Services\PaymentFulfillmentService;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ForeignCurrencyBillingTest extends TestCase
{
    use RefreshDatabase;

    public function test_postgraduate_non_nigeria_starts_in_usd_and_nigeria_stays_naira(): void
    {
        Mail::fake();
        $intake = $this->pgIntake(50);

        $foreign = User::factory()->create(['status' => 'active']);
        Sanctum::actingAs($foreign);
        $this->postJson('/api/applications', [
            'entry_mode' => 'pg',
            'intake_id' => $intake->id,
            'country' => 'Non-Nigeria',
        ])->assertOk()
            ->assertJsonPath('billing_currency', 'USD')
            ->assertJsonPath('country', 'Non-Nigeria');

        $foreignApplication = Application::query()->where('user_id', $foreign->id)->first();
        $this->assertSame('USD', $foreignApplication->billing_currency);
        $this->assertSame('USD', $foreignApplication->applicationFeeInvoice->currency);
        $this->assertEquals(50, (float) $foreignApplication->applicationFeeInvoice->amount);

        $local = User::factory()->create(['status' => 'active']);
        Sanctum::actingAs($local);
        $this->postJson('/api/applications', [
            'entry_mode' => 'pg',
            'intake_id' => $intake->id,
            'country' => 'Nigeria',
        ])->assertOk()
            ->assertJsonPath('billing_currency', 'NGN');

        $localApplication = Application::query()->where('user_id', $local->id)->first();
        $this->assertSame('NGN', $localApplication->applicationFeeInvoice->currency);
        $this->assertEquals(20000, (float) $localApplication->applicationFeeInvoice->amount);
    }

    public function test_postgraduate_start_requires_country_and_a_usd_price(): void
    {
        Mail::fake();
        $intake = $this->pgIntake(null);
        $user = User::factory()->create(['status' => 'active']);
        Sanctum::actingAs($user);

        $this->postJson('/api/applications', [
            'entry_mode' => 'pg',
            'intake_id' => $intake->id,
        ])->assertStatus(422);

        $this->postJson('/api/applications', [
            'entry_mode' => 'pg',
            'intake_id' => $intake->id,
            'country' => 'Non-Nigeria',
        ])->assertStatus(422)
            ->assertJsonPath('message', 'Set a USD application fee in the fee catalog for this entry mode, or on the application session, before applicants can apply.');

        $this->assertSame(0, Application::query()->count());
    }

    public function test_usd_checkout_uses_wema_even_when_paystack_is_the_default(): void
    {
        config([
            'services.paystack.public' => '',
            'services.paystack.secret' => '',
            'services.wema.public' => 'wema-public-test',
            'services.wema.business_id' => 'wema-business-test',
        ]);
        $user = User::factory()->create(['status' => 'active', 'email' => 'foreign@example.com']);
        $invoice = Invoice::query()->create([
            'number' => 'INV-USD-APP',
            'user_id' => $user->id,
            'category' => 'application_fee',
            'currency' => 'USD',
            'amount' => 50,
            'full_amount' => 50,
            'balance' => 50,
            'status' => 'unpaid',
            'wallet_allowed' => false,
        ]);

        Sanctum::actingAs($user);
        $this->postJson('/api/payments/initialize', ['invoice_id' => $invoice->id])
            ->assertOk()
            ->assertJsonPath('provider', 'wema')
            ->assertJsonPath('checkout.currency', 'USD')
            ->assertJsonPath('checkout.amount', 50);

        $payment = Payment::query()->where('invoice_id', $invoice->id)->first();
        $this->assertSame('wema', $payment->method);
        $this->assertSame('USD', $payment->currency);
        $this->assertSame('pending', $payment->status);
    }

    public function test_usd_wallet_topup_credits_the_dollar_purse(): void
    {
        config([
            'services.wema.public' => 'wema-public-test',
            'services.wema.business_id' => 'wema-business-test',
        ]);
        [$user, $student, $wallet] = $this->usdStudent();

        Sanctum::actingAs($user);
        $this->postJson('/api/wallet/topup', ['amount' => 0.5])
            ->assertStatus(422);

        $this->postJson('/api/wallet/topup', ['amount' => 25])
            ->assertOk()
            ->assertJsonPath('provider', 'wema')
            ->assertJsonPath('checkout.currency', 'USD');

        $payment = Payment::query()->where('user_id', $user->id)->where('purpose', 'wallet_topup')->first();
        $this->assertSame('USD', $payment->currency);
        app(PaymentFulfillmentService::class)->fulfill($payment, 'test');

        $wallet->refresh();
        $this->assertEquals(25, (float) $wallet->balance_usd);
        $this->assertEquals(1000, (float) $wallet->balance);
        $this->assertSame('USD', $wallet->transactions()->first()->currency);
        $this->assertNotNull($student->id);
    }

    public function test_usd_wallet_pays_a_dollar_invoice_and_rejects_a_naira_invoice(): void
    {
        [$user, $student, $wallet] = $this->usdStudent(40);

        $dollar = Invoice::query()->create([
            'number' => 'INV-USD-HOSTEL',
            'user_id' => $user->id,
            'student_id' => $student->id,
            'category' => 'hostel',
            'currency' => 'USD',
            'amount' => 15,
            'full_amount' => 15,
            'balance' => 15,
            'status' => 'unpaid',
            'wallet_allowed' => true,
        ]);
        $naira = Invoice::query()->create([
            'number' => 'INV-NGN-HOSTEL',
            'user_id' => $user->id,
            'student_id' => $student->id,
            'category' => 'hostel',
            'currency' => 'NGN',
            'amount' => 5000,
            'full_amount' => 5000,
            'balance' => 5000,
            'status' => 'unpaid',
            'wallet_allowed' => true,
        ]);

        Sanctum::actingAs($user);
        $this->postJson('/api/wallet/pay/'.$naira->id)
            ->assertStatus(422);
        $wallet->refresh();
        $this->assertEquals(40, (float) $wallet->balance_usd);
        $this->assertEquals(1000, (float) $wallet->balance);

        $this->postJson('/api/wallet/pay/'.$dollar->id)->assertOk();
        $wallet->refresh();
        $dollar->refresh();
        $this->assertEquals(25, (float) $wallet->balance_usd);
        $this->assertEquals(1000, (float) $wallet->balance);
        $this->assertSame('paid', $dollar->status);
        $this->assertSame('USD', $dollar->payments()->first()->currency);
    }

    public function test_finance_totals_do_not_add_dollars_into_naira(): void
    {
        $staff = $this->financeStaff();
        $user = User::factory()->create(['status' => 'active']);
        $student = Student::query()->create([
            'user_id' => $user->id,
            'first_name' => 'Ada',
            'last_name' => 'Foreign',
            'matric_number' => 'BUT/2026/USD',
            'status' => 'active',
            'current_level' => '100',
            'billing_currency' => 'USD',
        ]);
        Wallet::query()->create([
            'student_id' => $student->id,
            'balance' => 3000,
            'balance_usd' => 12,
        ]);
        $naira = Invoice::query()->create([
            'number' => 'INV-NGN-TUI',
            'user_id' => $user->id,
            'student_id' => $student->id,
            'category' => 'tuition',
            'amount' => 10000,
            'full_amount' => 10000,
            'balance' => 2500,
            'status' => 'partial',
            'wallet_allowed' => true,
        ]);
        Payment::query()->create([
            'user_id' => $user->id,
            'invoice_id' => $naira->id,
            'method' => 'paystack',
            'amount' => 7500,
            'status' => 'successful',
            'reference' => 'PSK-NGN',
            'purpose' => 'tuition',
        ]);
        $dollar = Invoice::query()->create([
            'number' => 'INV-USD-TUI',
            'user_id' => $user->id,
            'student_id' => $student->id,
            'category' => 'tuition',
            'currency' => 'USD',
            'amount' => 40,
            'full_amount' => 40,
            'balance' => 10,
            'status' => 'partial',
            'wallet_allowed' => true,
        ]);
        Payment::query()->create([
            'user_id' => $user->id,
            'invoice_id' => $dollar->id,
            'method' => 'wema',
            'amount' => 30,
            'currency' => 'USD',
            'status' => 'successful',
            'reference' => 'WEMA-USD',
            'purpose' => 'tuition',
        ]);

        Sanctum::actingAs($staff);
        $this->getJson('/api/finance/dashboard')
            ->assertOk()
            ->assertJsonPath('currency', 'all')
            ->assertJsonPath('totals.collected', 7500)
            ->assertJsonPath('totals.outstanding', 2500)
            ->assertJsonPath('totals.wallet_liability', 3000)
            ->assertJsonPath('totals_usd.collected', 30)
            ->assertJsonPath('totals_usd.outstanding', 10)
            ->assertJsonPath('totals_usd.wallet_liability', 12);
    }

    private function pgIntake(?float $usd): Intake
    {
        $session = AcademicSession::query()->create(['label' => '2025/2026']);
        $term = AcademicTerm::query()->create([
            'academic_session_id' => $session->id,
            'name' => 'First',
            'session_label' => '2025/2026',
            'is_current' => true,
        ]);

        return Intake::query()->create([
            'academic_term_id' => $term->id,
            'name' => 'PG 2025',
            'entry_mode' => 'pg',
            'is_open' => true,
            'application_fee_amount' => 20000,
            'application_fee_amount_usd' => $usd,
            'opens_on' => now()->subDay()->toDateString(),
            'closes_on' => now()->addMonth()->toDateString(),
        ]);
    }

    /**
     * @return array{0: User, 1: Student, 2: Wallet}
     */
    private function usdStudent(float $dollars = 0): array
    {
        $user = User::factory()->create(['status' => 'active']);
        $student = Student::query()->create([
            'user_id' => $user->id,
            'first_name' => 'Ada',
            'last_name' => 'Foreign',
            'matric_number' => 'BUT/2026/'.uniqid(),
            'status' => 'active',
            'current_level' => '100',
            'country' => 'Non-Nigeria',
            'billing_currency' => 'USD',
        ]);
        $wallet = Wallet::query()->create([
            'student_id' => $student->id,
            'balance' => 1000,
            'balance_usd' => $dollars,
        ]);

        return [$user, $student, $wallet];
    }

    private function financeStaff(): User
    {
        foreach (PermissionCatalog::all() as $perm) {
            Permission::query()->updateOrCreate(['key' => $perm['key']], $perm);
        }
        $role = Role::query()->create([
            'name' => 'Finance',
            'slug' => 'finance-usd-'.uniqid(),
            'is_system' => false,
            'is_active' => true,
        ]);
        $role->permissions()->sync(
            Permission::query()->whereIn('key', ['finance.invoices.manage'])->pluck('id'),
        );
        $user = User::factory()->create(['status' => 'active']);
        $user->roles()->attach($role->id);

        return $user->fresh(['roles.permissions']);
    }
}
