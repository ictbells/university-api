<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\AcademicTerm;
use App\Models\Application;
use App\Models\Intake;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OfferAcceptancePortalTest extends TestCase
{
    use RefreshDatabase;

    public function test_me_flags_unpaid_acceptance_when_offer_is_issued_without_invoice(): void
    {
        $user = $this->applicantWithOffer('offer_issued');
        Sanctum::actingAs($user);

        $this->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('unpaid_acceptance_fee', true)
            ->assertJsonPath('lifecycle_stage', 'awaiting_acceptance_fee');
    }

    public function test_me_flags_unpaid_acceptance_when_invoice_is_outstanding(): void
    {
        $user = $this->applicantWithOffer('awaiting_acceptance_fee');
        $invoice = Invoice::query()->create([
            'number' => 'ACC-001',
            'user_id' => $user->id,
            'application_id' => $user->latestApplication->id,
            'category' => 'acceptance_fee',
            'amount' => 25000,
            'balance' => 25000,
            'status' => 'unpaid',
        ]);
        $user->latestApplication->update(['acceptance_fee_invoice_id' => $invoice->id]);

        Sanctum::actingAs($user->fresh());

        $this->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('unpaid_acceptance_fee', true);
    }

    public function test_opening_the_file_prefers_catalog_amount_over_intake_default(): void
    {
        $user = $this->applicantWithOffer('offer_issued', 7000);
        \App\Models\FeeItem::query()->create([
            'name' => 'Acceptance',
            'category' => 'acceptance_fee',
            'amount' => 8000,
            'is_active' => true,
            'wallet_allowed' => false,
        ]);
        $application = $user->latestApplication;
        $this->assertNull($application->acceptance_fee_invoice_id);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/applications/{$application->id}")->assertOk();
        $this->assertSame('unpaid', $response->json('acceptance_fee_invoice.status'));
        $this->assertEquals(8000, (float) $response->json('acceptance_fee_invoice.amount'));
        $this->assertNotNull($application->fresh()->acceptance_fee_invoice_id);
        $this->assertSame('awaiting_acceptance_fee', $application->fresh()->stage);
    }

    public function test_opening_the_file_uses_catalog_amount_for_entry_mode(): void
    {
        $user = $this->applicantWithOffer('offer_issued', 7000);
        \App\Models\FeeItem::query()->create([
            'name' => 'PG acceptance',
            'category' => 'acceptance_fee',
            'entry_mode' => 'pg',
            'amount' => 15000,
            'is_active' => true,
            'wallet_allowed' => false,
        ]);
        \App\Models\FeeItem::query()->create([
            'name' => 'UTME acceptance',
            'category' => 'acceptance_fee',
            'entry_mode' => 'utme',
            'amount' => 9000,
            'is_active' => true,
            'wallet_allowed' => false,
        ]);
        $application = $user->latestApplication;

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/applications/{$application->id}")->assertOk();
        $this->assertEquals(9000, (float) $response->json('acceptance_fee_invoice.amount'));
        $this->assertSame('unpaid', $response->json('acceptance_fee_invoice.status'));
    }

    public function test_opening_the_file_uses_intake_default_when_catalog_is_missing(): void
    {
        $user = $this->applicantWithOffer('offer_issued', 7000);
        Sanctum::actingAs($user);

        $response = $this->getJson("/api/applications/{$user->latestApplication->id}")->assertOk();
        $this->assertEquals(7000, (float) $response->json('acceptance_fee_invoice.amount'));
    }

    public function test_opening_the_file_replaces_disabled_acceptance_invoice_with_updated_amount(): void
    {
        $user = $this->applicantWithOffer('awaiting_acceptance_fee', 5000);
        $application = $user->latestApplication;
        $old = Invoice::query()->create([
            'number' => 'ACC-WRONG',
            'user_id' => $user->id,
            'application_id' => $application->id,
            'category' => 'acceptance_fee',
            'amount' => 5000,
            'balance' => 5000,
            'status' => 'cancelled',
            'disabled_reason' => 'Wrong acceptance amount',
        ]);
        $application->update(['acceptance_fee_invoice_id' => $old->id]);
        \App\Models\FeeItem::query()->create([
            'name' => 'Acceptance',
            'category' => 'acceptance_fee',
            'amount' => 12000,
            'is_active' => true,
            'wallet_allowed' => false,
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/applications/{$application->id}")->assertOk();
        $this->assertSame('unpaid', $response->json('acceptance_fee_invoice.status'));
        $this->assertEquals(12000, (float) $response->json('acceptance_fee_invoice.amount'));
        $this->assertNotEquals($old->id, $response->json('acceptance_fee_invoice.id'));
        $this->assertSame(
            (int) $response->json('acceptance_fee_invoice.id'),
            (int) $application->fresh()->acceptance_fee_invoice_id,
        );
        $this->assertSame('cancelled', $old->fresh()->status);
        $this->assertEquals(5000, (float) $old->fresh()->amount);
    }

    public function test_invoices_list_replaces_disabled_acceptance_invoice(): void
    {
        $user = $this->applicantWithOffer('awaiting_acceptance_fee', 5000);
        $application = $user->latestApplication;
        $old = Invoice::query()->create([
            'number' => 'ACC-DISABLED',
            'user_id' => $user->id,
            'application_id' => $application->id,
            'category' => 'acceptance_fee',
            'amount' => 5000,
            'balance' => 5000,
            'status' => 'cancelled',
            'disabled_reason' => 'Wrong acceptance amount',
        ]);
        $application->update(['acceptance_fee_invoice_id' => $old->id]);
        \App\Models\FeeItem::query()->create([
            'name' => 'Acceptance',
            'category' => 'acceptance_fee',
            'amount' => 15000,
            'is_active' => true,
            'wallet_allowed' => false,
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/invoices')->assertOk();
        $rows = collect($response->json('data') ?? $response->json());
        $live = $rows->first(
            fn ($row) => ($row['category'] ?? null) === 'acceptance_fee' && ($row['status'] ?? null) === 'unpaid',
        );
        $this->assertNotNull($live);
        $this->assertNotEquals($old->id, $live['id']);
        $this->assertEquals(15000, (float) $live['amount']);
        $this->assertSame('cancelled', $old->fresh()->status);
        $this->assertSame((int) $live['id'], (int) $application->fresh()->acceptance_fee_invoice_id);
    }

    public function test_live_unpaid_acceptance_invoice_is_not_replaced_when_catalog_changes(): void
    {
        $user = $this->applicantWithOffer('awaiting_acceptance_fee', 5000);
        $application = $user->latestApplication;
        $live = Invoice::query()->create([
            'number' => 'ACC-LIVE',
            'user_id' => $user->id,
            'application_id' => $application->id,
            'category' => 'acceptance_fee',
            'amount' => 5000,
            'balance' => 5000,
            'status' => 'unpaid',
        ]);
        $application->update(['acceptance_fee_invoice_id' => $live->id]);
        \App\Models\FeeItem::query()->create([
            'name' => 'Acceptance',
            'category' => 'acceptance_fee',
            'amount' => 20000,
            'is_active' => true,
            'wallet_allowed' => false,
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/applications/{$application->id}")->assertOk();
        $this->assertSame($live->id, $response->json('acceptance_fee_invoice.id'));
        $this->assertEquals(5000, (float) $response->json('acceptance_fee_invoice.amount'));
        $this->assertSame('unpaid', $response->json('acceptance_fee_invoice.status'));
    }

    public function test_me_does_not_flag_acceptance_after_fee_is_paid(): void
    {
        $user = $this->applicantWithOffer('awaiting_acceptance_fee');
        $invoice = Invoice::query()->create([
            'number' => 'ACC-002',
            'user_id' => $user->id,
            'application_id' => $user->latestApplication->id,
            'category' => 'acceptance_fee',
            'amount' => 25000,
            'balance' => 0,
            'status' => 'paid',
        ]);
        $user->latestApplication->update(['acceptance_fee_invoice_id' => $invoice->id]);

        Sanctum::actingAs($user->fresh());

        $this->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('unpaid_acceptance_fee', false);
    }

    public function test_admission_letter_uses_official_offer_wording(): void
    {
        $user = $this->applicantWithOffer('offer_issued', 100000);
        Sanctum::actingAs($user);
        $application = $user->latestApplication;

        $html = $this->get("/api/applications/{$application->id}/offer-letter")
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('OFFER OF ADMISSION FOR THE 2026/2027 ACADEMIC SESSION', $html);
        $this->assertStringContainsString('With reference to your application for admission', $html);
        $this->assertStringContainsString('having fulfilled the admission requirements', $html);
        $this->assertStringContainsString('https://apply.bellsuniversityportal.com', $html);
        $this->assertStringContainsString('N100,000', $html);
        $this->assertStringContainsString('One Hundred Thousand Naira', $html);
        $this->assertStringContainsString('Pay-As-You-Eat (PAYE)', $html);
        $this->assertStringContainsString('Student Information Handbook', $html);
        $this->assertStringContainsString('Admission letter as issued by JAMB', $html);
        $this->assertStringContainsString('Only the best is good for Bells', $html);
        $this->assertStringContainsString('BUT/AD/2026/20269876543CD', $html);
    }

    private function applicantWithOffer(string $stage, float $intakeAmount = 7000): User
    {
        $role = Role::query()->firstOrCreate(
            ['slug' => 'applicant'],
            ['name' => 'Applicant', 'is_system' => true, 'is_active' => true],
        );
        $user = User::factory()->create(['jamb_registration' => '20269876543CD']);
        $user->roles()->attach($role->id);

        $session = AcademicSession::query()->create(['label' => '2026/2027']);
        $term = AcademicTerm::query()->create([
            'academic_session_id' => $session->id,
            'name' => 'First',
            'session_label' => '2026/2027',
            'is_current' => true,
        ]);
        $intake = Intake::query()->create([
            'academic_term_id' => $term->id,
            'name' => 'UTME 2026',
            'entry_mode' => 'utme',
            'is_open' => true,
            'application_fee_amount' => 5000,
            'acceptance_fee_amount' => $intakeAmount,
            'opens_on' => now()->subDay()->toDateString(),
            'closes_on' => now()->addMonth()->toDateString(),
        ]);
        Application::query()->create([
            'application_number' => 'APP/2026/00999',
            'user_id' => $user->id,
            'intake_id' => $intake->id,
            'entry_mode' => 'utme',
            'jamb_registration' => '20269876543CD',
            'stage' => $stage,
            'offer_reference' => 'OFF/2026/0001',
        ]);

        return $user->fresh(['latestApplication']);
    }
}
